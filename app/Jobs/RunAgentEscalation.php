<?php

namespace App\Jobs;

use App\Contracts\RunsLegalResearch;
use App\Exceptions\AiServiceException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Agent\AgentReplyComposer;
use App\Services\Chat\NonLegalInputGate;
use App\Services\Logging\AiLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * One escalated answer: the multi-hop agent re-answers a question the pipeline
 * already answered, and its reply joins the conversation as its own message.
 *
 * It publishes onto the SAME Redis stream the pipeline uses and reuses
 * {@see StreamChatReply::channelKey()}, so the browser subscribes with the
 * existing SSE endpoint and relay — the escalation added no second transport.
 * What differs is what flows through it: the agent's model calls are
 * non-streaming by construction (the loop needs a complete tool_calls array
 * before it can act), so there are no token deltas to show. The progress is the
 * searches themselves — one `status` event per query as it is issued — and the
 * answer arrives as a single chunk at the end.
 *
 * Two deliberate differences from {@see StreamChatReply}:
 *
 *   - **No retries.** A run is billed from its first model call, and a failure
 *     anywhere past that point has already spent tokens. StreamChatReply can
 *     retry safely before its first token because nothing was billed yet; here
 *     there is no such window, so $tries is 1 and a failure is reported rather
 *     than replayed.
 *   - **The gate runs again.** The escalation endpoint already refuses non-legal
 *     input, but a greeting must never reach a 25k-token run, so the job re-checks
 *     rather than trusting its caller.
 */
class RunAgentEscalation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Headroom above the agent service's own whole-run cURL cap. The inner
     * deadline is the one that should fire and report; this is the backstop for a
     * stall below it.
     */
    private const TIMEOUT_MARGIN_SECONDS = 20;

    public int $timeout;

    /**
     * A run is billed from its first model call, so there is no phase in which a
     * retry is free. One attempt; a failure is surfaced, never replayed.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $streamId,
        public readonly string $question,
        /** The pipeline answer this escalates from — recorded for the audit trail. */
        public readonly ?int $sourceMessageId,
        /**
         * The user message $question was read from, resolved by id at the API
         * boundary. Persisted on the run so "which question did this answer?" is a
         * column rather than something re-derived from position later — the
         * re-derivation is what previously handed a run the wrong question.
         */
        public readonly ?int $questionMessageId,
        /** Shared-clock epoch captured before queueing; anchors the end-to-end timer. */
        public readonly float $receivedAt,
    ) {
        $this->timeout = (int) config('agent.timeout', 120) + self::TIMEOUT_MARGIN_SECONDS;
    }

    public function handle(
        RunsLegalResearch $agent,
        AgentReplyComposer $composer,
        AiLogger $logger,
        NonLegalInputGate $gate,
    ): void {
        // Same guard as the pipeline: a stream that already produced a message
        // must never run again — here that would be a second 25k-token charge.
        if (Message::query()->where('stream_id', $this->streamId)->where('role', 'assistant')->exists()) {
            $logger->warning('agent.escalation_skipped_duplicate', [
                'stream_id' => $this->streamId,
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $key = StreamChatReply::channelKey($this->streamId);

        // Defence in depth: the endpoint refuses non-legal input, but nothing
        // reaches the agent without passing the gate a second time here.
        if ($gate->shouldGate($this->question)) {
            $logger->warning('agent.escalation_gated', ['stream_id' => $this->streamId]);
            $this->publish($key, [
                'type' => 'error',
                'service' => 'agent',
                'reason' => 'gated',
                'message' => 'Ова прашање не е правно прашање и не може да се продлабочи.',
            ]);

            return;
        }

        $start = hrtime(true);
        $done = null;

        try {
            $this->publish($key, ['type' => 'status', 'stage' => 'agent_started']);

            foreach ($agent->run($this->question) as $event) {
                match ($event['type']) {
                    // The whole point of the progress design: each query reaches
                    // the browser as the model asks for it, not after the run.
                    'search' => $this->publish($key, [
                        'type' => 'status',
                        'stage' => 'agent_search',
                        'step' => $event['step'] ?? 0,
                        'tool' => $event['tool'] ?? '',
                        'query' => $event['query'] ?? '',
                    ]),
                    'result' => $this->publish($key, [
                        'type' => 'status',
                        'stage' => 'agent_result',
                        'step' => $event['step'] ?? 0,
                        'sources' => array_slice($event['sources'] ?? [], 0, 6),
                    ]),
                    'done' => $done = $event,
                    default => null,
                };
            }
        } catch (Throwable $exception) {
            // No retry window exists (see $tries), so a failure is failed here and
            // failed() publishes the terminal error the browser unblocks on.
            $this->fail($exception);

            return;
        }

        if ($done === null) {
            $this->fail(AiServiceException::invalidResponse('agent', 'the run produced no answer'));

            return;
        }

        $totalMs = (int) round((microtime(true) - $this->receivedAt) * 1000);
        $payload = $composer->compose($done, $totalMs);

        if ($payload['answer'] === '') {
            $this->fail(AiServiceException::invalidResponse('agent', 'the run returned an empty answer'));

            return;
        }

        // The answer arrives whole rather than token-by-token: the agent's model
        // calls are non-streaming by design. One chunk keeps the browser's
        // existing bubble-filling code path working unchanged.
        $this->publish($key, ['type' => 'chunk', 'text' => $payload['answer']]);

        $metadata = $payload['metadata'];
        $metadata['escalated_from_message_id'] = $this->sourceMessageId;
        $metadata['question_message_id'] = $this->questionMessageId;

        $conversation = Conversation::findOrFail($this->conversationId);
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'stream_id' => $this->streamId,
            'content' => $payload['answer'],
            'model' => $payload['model'],
            'tokens' => $payload['tokens'],
            'latency_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
            'metadata' => $metadata,
        ]);

        $message->agentRun()->create([
            'run_id' => $metadata['run_id'] ?? '',
            'question' => $this->question,
            'question_message_id' => $this->questionMessageId,
            'outcome' => $metadata['outcome'] ?? 'answered',
            'model' => $payload['model'],
            'steps_count' => $metadata['agent_steps'],
            'api_calls' => $metadata['agent_api_calls'],
            'total_tokens' => $metadata['agent_tokens'],
            'duration_ms' => $totalMs,
            'steps' => is_array($done['steps'] ?? null) ? $done['steps'] : [],
            'passages' => is_array($done['passages'] ?? null) ? $done['passages'] : [],
        ]);

        $logger->info('agent.escalation_completed', [
            'stream_id' => $this->streamId,
            'conversation_id' => $this->conversationId,
            'run_id' => $metadata['run_id'] ?? null,
            'outcome' => $metadata['outcome'] ?? null,
            'steps' => $metadata['agent_steps'],
            'api_calls' => $metadata['agent_api_calls'],
            'tokens' => $metadata['agent_tokens'],
            'total_ms' => $totalMs,
            // An article the answer cites that no retrieved passage backs is the
            // one groundedness failure per-claim citations make visible.
            'uncited_articles' => $metadata['uncited_articles'],
        ]);

        $this->publish($key, [
            'type' => 'done',
            'message_id' => $message->id,
            'model' => $payload['model'],
            'tokens' => $payload['tokens'],
            'agent' => true,
            'run_id' => $metadata['run_id'],
            'searches' => $metadata['searches'],
            'agent_steps' => $metadata['agent_steps'],
            'agent_api_calls' => $metadata['agent_api_calls'],
            'agent_tokens' => $metadata['agent_tokens'],
            'retrieval_ms' => null,
            'ttft_ms' => null,
            'total_ms' => $totalMs,
            'top_triples' => [],
            'top_chunks' => $metadata['top_chunks'],
        ]);
    }

    /**
     * Publishes the terminal error the browser unblocks on. Mirrors
     * {@see StreamChatReply::failed()}; the relay's idle timeout still covers the
     * SIGKILL case no hook can.
     */
    public function failed(?Throwable $exception): void
    {
        $isServiceError = $exception instanceof AiServiceException;

        app(AiLogger::class)->error('agent.escalation_failed', [
            'stream_id' => $this->streamId,
            'service' => $isServiceError ? $exception->service : 'worker',
            'reason' => $isServiceError ? $exception->reason : 'crashed',
            'exception' => $exception === null ? null : $exception::class,
            'message' => $exception?->getMessage(),
        ]);

        $this->publish(StreamChatReply::channelKey($this->streamId), [
            'type' => 'error',
            'service' => 'agent',
            'reason' => $isServiceError ? $exception->reason : 'crashed',
            'message' => 'Продлабоченото истражување не успеа. Обидете се повторно.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function publish(string $key, array $event): void
    {
        Redis::rpush($key, json_encode($event, JSON_UNESCAPED_UNICODE));
        Redis::expire($key, (int) config('ai.stream.key_ttl'));
    }
}
