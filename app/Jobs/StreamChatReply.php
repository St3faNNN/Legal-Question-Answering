<?php

namespace App\Jobs;

use App\Exceptions\AiServiceException;
use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\Concerns\ComposesGraphRagReply;
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
 * Orchestrates one streamed chat reply off the web request: retrieve context
 * (gRPC unary), then generate the answer (gRPC server-streaming), pushing each
 * token chunk onto a Redis list keyed by the stream id. The SSE endpoint drains
 * that list to the browser. The final assistant Message (full text + retrieval
 * metadata) is persisted so history/reloads work.
 *
 * Failure is reported from one place — failed() — rather than swallowed in
 * handle(): letting the exception propagate records the job in failed_jobs (the
 * operational signal worth alerting on), while failed() still publishes a
 * terminal `error` event so the browser unblocks with a real message instead of
 * hanging. The SSE relay's idle timeout is the backstop for the one failure no
 * hook can cover — a SIGKILL/OOM that stops the worker before failed() can run.
 *
 * Retries are phase-gated: a failure before the first token is re-thrown for the
 * queue to retry (nothing was sent, nothing was billed); a failure after it is
 * failed permanently, because a retry would replay billed tokens. An idempotency
 * guard keyed on the stream id makes that safe against the cases a retry cannot
 * see — a completed message, or a partial run that died mid-stream — so
 * generation is never called (and charged) twice for one stream.
 */
class StreamChatReply implements ShouldQueue
{
    use ComposesGraphRagReply, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Ceiling on any job's timeout, and the reference the queue's retry_after,
     * the worker --timeout and emittedTtl() are all sized against. The
     * *effective* timeout is computed per job in the constructor from the live
     * config (~90s as configured); this constant is the bound that computation
     * must never exceed, so `retry_after > TIMEOUT_SECONDS` holds whatever the
     * inner timeouts are tuned to.
     *
     * It was the measured worst case back when a local llama.cpp fallback
     * existed. That path is gone, so 300 is now headroom rather than a measured
     * sum — kept deliberately, because shrinking it would only tighten a
     * backstop and drag retry_after down with it. Revisit if
     * AI_RETRIEVAL_TIMEOUT or LLM_TIMEOUT is raised far above today's values.
     */
    public const TIMEOUT_SECONDS = 300;

    /**
     * Headroom above the sum of the per-call gRPC deadlines. The job timeout is
     * a backstop for a hang the inner per-call deadlines miss (a stall below the
     * gRPC layer, not the call itself), so it need only clear the legitimate
     * worst case — retrieval then generation, back to back — by a small margin.
     */
    private const TIMEOUT_MARGIN_SECONDS = 15;

    public int $timeout;

    /**
     * Retries are safe only before the first token (nothing sent, nothing
     * billed); handle() enforces that by re-throwing pre-token failures and
     * permanently failing post-token ones. Three attempts absorb a transient
     * gRPC blip or a provider 429 without hammering. The worker --tries flag is
     * kept in step (docker-compose.yml), but this property is the governor.
     */
    public int $tries = 3;

    /**
     * Seconds between attempts. Short on purpose: a pre-token retry runs inside
     * the SSE first-chunk window, and each attempt re-emits the "retrieving"
     * status, which keeps the browser's relay alive across the gap.
     */
    public int $backoff = 2;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $streamId,
        public readonly string $userMessage,
        /**
         * Wall-clock epoch (microtime) of when the query was received, captured
         * before the job was queued. It anchors the end-to-end timer measured in
         * handle(): unlike the per-hop hrtime timers, this one spans the
         * web->worker process boundary, so it must be a shared-clock epoch rather
         * than a per-process monotonic counter.
         */
        public readonly float $receivedAt,
    ) {
        // One generation backend, so one deadline: retrieval's gRPC budget
        // plus the hosted endpoint's whole-request cap, plus margin. This used to
        // branch on the driver because the local llama.cpp path needed ~300s of
        // CPU prefill; that path is gone.
        $this->timeout = (int) config('ai.retrieval_timeout')
            + (int) config('ai.llm.timeout')
            + self::TIMEOUT_MARGIN_SECONDS;
    }

    public function handle(RetrievalClient $retrieval, GenerationClient $generation, AiLogger $logger, NonLegalInputGate $gate): void
    {
        // Idempotency guard. A prior execution of this stream may have already
        // run generation — a manual requeue, an operator re-dispatch, or a retry
        // after a kill that fired no failed() hook — and re-running would call
        // the billed generation API again. Skip if a completed assistant message
        // exists, or if a prior run reached the first token (see alreadyGenerated).
        if ($this->alreadyGenerated()) {
            $logger->warning('graphrag.stream_skipped_duplicate', [
                'stream_id' => $this->streamId,
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $key = self::channelKey($this->streamId);
        $start = hrtime(true);

        // Non-legal input (greetings, small talk, empty) is answered from a canned
        // bilingual message with NO retrieval, graph expansion or LLM call. Emitted
        // like any reply — one chunk, persisted message, done — so the browser and
        // history are unaffected. Narrow by design (see NonLegalInputGate); a terse
        // real query still runs the full pipeline.
        //
        // GATE SITE 1 of 3. The gate is applied at every entry to an expensive
        // path, never once centrally: here for the pipeline, and for the agent in
        // EscalationService::escalate() (the endpoint) and RunAgentEscalation
        // (the job). Any new path that reaches retrieval or a model must add its
        // own check — there is no shared choke point that would do it for you.
        if ($gate->shouldGate($this->userMessage)) {
            $this->emitGatedReply($key, $gate, $logger);

            return;
        }

        // Retry phase gate: a failure BEFORE the first token has sent nothing to
        // the client and billed no output, so it is re-thrown for the queue to
        // retry (up to $tries). A failure AFTER the first token would replay
        // tokens and re-bill on retry, so it is caught and failed permanently.
        $tokensPushed = false;

        try {
            // Hybrid retrieval runs for tens of seconds on CPU and emits no
            // tokens. Announce each stage so the browser can show progress
            // instead of a bare spinner, and so the SSE relay sees traffic
            // rather than silence it would eventually give up on.
            $this->publish($key, ['type' => 'status', 'stage' => 'retrieving']);

            $retrievalStart = hrtime(true);
            $retrievalResult = $retrieval->retrieve($this->userMessage);
            $retrievalRoundtripMs = (int) ((hrtime(true) - $retrievalStart) / 1_000_000);

            $logger->info('graphrag.retrieval_completed', [
                'stream_id' => $this->streamId,
                'entities' => $retrievalResult['entities'] ?? [],
                'triple_count' => count($retrievalResult['triples'] ?? []),
                'chunk_count' => count($retrievalResult['chunks'] ?? []),
                'context_items' => $retrievalResult['context_items'] ?? null,
                'roundtrip_ms' => $retrievalRoundtripMs,
                'service_ms' => $retrievalResult['latency_ms'] ?? null,
            ]);

            $this->publish($key, [
                'type' => 'status',
                'stage' => 'generating',
                'chunk_count' => count($retrievalResult['chunks'] ?? []),
            ]);

            $full = '';
            $model = null;
            $tokens = null;
            $ttftMs = null;
            $generationStart = hrtime(true);

            // History is intentionally omitted (the small model copies prior
            // answers otherwise — see the generators).
            foreach ($generation->generateStream(
                $this->userMessage,
                $retrievalResult['context'] ?? '',
                $retrievalResult['entities'][0] ?? null,
                [],
            ) as $chunk) {
                if ($chunk['done']) {
                    $model = $chunk['model'] !== '' ? $chunk['model'] : null;
                    $tokens = $chunk['tokens'] !== 0 ? $chunk['tokens'] : null;

                    break;
                }

                if ($chunk['text'] !== '') {
                    // Time-to-first-token: from the received query to the moment
                    // the first token reaches the browser's side channel. This is
                    // the number that governs perceived speed, and it swings from
                    // ~90s (local CPU prefill) to ~1-2s on a hosted endpoint.
                    if ($ttftMs === null) {
                        $ttftMs = (int) round((microtime(true) - $this->receivedAt) * 1000);

                        // The retry boundary: past the first token a re-run would
                        // duplicate billed output. Flip the local gate, and record
                        // a durable marker so a *re-executed* job (one that got
                        // here then died before persisting) also skips generation.
                        $tokensPushed = true;
                        Redis::setex(self::emittedKey($this->streamId), self::emittedTtl(), '1');
                    }

                    $full .= $chunk['text'];
                    $this->publish($key, ['type' => 'chunk', 'text' => $chunk['text']]);
                }
            }

            $generationRoundtripMs = (int) ((hrtime(true) - $generationStart) / 1_000_000);

            // End-to-end: from the received query to this final generated word.
            // Spans the queue wait and both gRPC hops, so it uses the shared host
            // clock (microtime) — the per-process hrtime timers above cannot be
            // compared across the web request that queued this job and the worker
            // running it.
            $totalMs = (int) round((microtime(true) - $this->receivedAt) * 1000);

            $payload = $this->buildReplyPayload(
                $retrievalResult,
                ['answer' => $full, 'model' => $model, 'tokens' => $tokens, 'latency_ms' => $generationRoundtripMs],
                $start,
            );
            // Three timers, all anchored the same way the end-to-end one is:
            //   retrieval_ms — retrieval call start to context assembled
            //   ttft_ms      — request received to first token pushed to Redis
            //   total_ms     — request received to final token
            // total_latency_ms is kept (== total_ms) so already-persisted messages
            // and any code reading the old field keep working.
            $payload['metadata']['retrieval_ms'] = $retrievalRoundtripMs;
            $payload['metadata']['ttft_ms'] = $ttftMs;
            $payload['metadata']['total_ms'] = $totalMs;
            $payload['metadata']['total_latency_ms'] = $totalMs;

            $conversation = Conversation::findOrFail($this->conversationId);
            /** @var Message $message */
            $message = $conversation->messages()->create([
                'role' => 'assistant',
                // Persisted so the idempotency guard above can find a completed
                // reply and refuse to generate it a second time.
                'stream_id' => $this->streamId,
                'content' => $payload['answer'],
                'model' => $payload['model'],
                'tokens' => $payload['tokens'],
                'latency_ms' => $payload['latency_ms'],
                'metadata' => $payload['metadata'],
            ]);

            $logger->info('graphrag.generation_completed', [
                'stream_id' => $this->streamId,
                'model' => $payload['model'],
                'roundtrip_ms' => $generationRoundtripMs,
                'retrieval_ms' => $retrievalRoundtripMs,
                'ttft_ms' => $ttftMs,
                'total_ms' => $totalMs,
            ]);

            $this->publish($key, [
                'type' => 'done',
                'message_id' => $message->id,
                'model' => $payload['model'],
                'tokens' => $payload['tokens'],
                'retrieval_ms' => $retrievalRoundtripMs,
                'ttft_ms' => $ttftMs,
                'total_ms' => $totalMs,
                'top_triples' => $payload['metadata']['top_triples'],
                'top_chunks' => $payload['metadata']['top_chunks'],
            ]);
        } catch (Throwable $exception) {
            if ($tokensPushed) {
                // Post-token: never retry — a retry would duplicate billed
                // output. Fail permanently; failed() records the job in
                // failed_jobs and publishes the terminal error to the browser.
                $this->fail($exception);

                return;
            }

            // Pre-token: nothing sent, nothing billed — propagate so the queue
            // retries. failed() runs only once $tries is exhausted.
            throw $exception;
        }
    }

    /**
     * Emit a gated (non-legal) reply through the stream: the canned message as a
     * single chunk, a persisted assistant Message (so history/reloads work and
     * the idempotency guard sees it), then a `done` with empty passages. No
     * retrieval, no generation — so nothing billed and no retry logic needed.
     */
    private function emitGatedReply(string $key, NonLegalInputGate $gate, AiLogger $logger): void
    {
        $payload = $gate->gatedPayload();

        $this->publish($key, ['type' => 'chunk', 'text' => $payload['answer']]);

        $conversation = Conversation::findOrFail($this->conversationId);
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'stream_id' => $this->streamId,
            'content' => $payload['answer'],
            'model' => null,
            'tokens' => 0,
            'latency_ms' => 0,
            'metadata' => $payload['metadata'],
        ]);

        $logger->info('graphrag.input_gated', [
            'stream_id' => $this->streamId,
            'conversation_id' => $this->conversationId,
        ]);

        $this->publish($key, [
            'type' => 'done',
            'message_id' => $message->id,
            'model' => null,
            'tokens' => 0,
            'retrieval_ms' => 0,
            'ttft_ms' => 0,
            'total_ms' => 0,
            'top_triples' => [],
            'top_chunks' => [],
        ]);
    }

    /**
     * Whether a prior execution already ran generation for this stream, making a
     * re-run a duplicate (and a double charge). True if a completed assistant
     * message was persisted (a permanent record — catches a requeue at any
     * time), or if a prior run reached the first token and set the durable marker
     * (catches a partial run that died before persisting the message).
     */
    private function alreadyGenerated(): bool
    {
        $completed = Message::query()
            ->where('stream_id', $this->streamId)
            ->where('role', 'assistant')
            ->exists();

        return $completed || (bool) Redis::exists(self::emittedKey($this->streamId));
    }

    /**
     * Queue failure hook. Runs after handle() throws — a service error, an
     * unexpected crash, or a job-timeout kill once tries are exhausted — but NOT
     * after a SIGKILL, which gives the process no chance to run it; the SSE
     * relay's idle timeout covers that gap instead. Publishing the terminal
     * `error` event here unblocks the browser for every non-kill failure, while
     * the propagated exception is what lands the job in failed_jobs for alerting
     * — so the two goals (clean client error, durable failure record) are met
     * without swallowing the exception.
     */
    public function failed(?Throwable $exception): void
    {
        $isServiceError = $exception instanceof AiServiceException;

        $service = $isServiceError ? $exception->service : 'worker';
        $reason = $isServiceError ? $exception->reason : 'crashed';

        app(AiLogger::class)->error('graphrag.stream_failed', [
            'stream_id' => $this->streamId,
            'service' => $service,
            'reason' => $reason,
            'exception' => $exception === null ? null : $exception::class,
            'message' => $exception?->getMessage(),
        ]);

        $this->publish($this->channelKey($this->streamId), [
            'type' => 'error',
            'service' => $service,
            'reason' => $reason,
            'message' => $isServiceError
                ? 'The AI service is currently unavailable. Please try again.'
                : 'The assistant stopped unexpectedly. Please try again.',
        ]);
    }

    public static function channelKey(string $streamId): string
    {
        return "chat:stream:{$streamId}";
    }

    /**
     * Redis key marking that a run of this stream reached the first token. It is
     * separate from the event list (which the relay drains) so it survives to be
     * read by a retry.
     */
    public static function emittedKey(string $streamId): string
    {
        return "chat:stream:{$streamId}:emitted";
    }

    /**
     * How long the emitted-token marker lives. It must outlive one retry window —
     * retry_after, then the retried job reaching its idempotency check — so a
     * partial run's marker is still present when the retry looks. Derived from
     * the queue's retry_after plus a full job budget so it self-adjusts if either
     * is retuned.
     */
    private static function emittedTtl(): int
    {
        return (int) config('queue.connections.database.retry_after', 90)
            + self::TIMEOUT_SECONDS
            + 60;
    }

    /**
     * Append an event to the stream's Redis list (drained by the SSE endpoint)
     * and refresh its TTL so abandoned streams self-clean.
     *
     * @param  array<string, mixed>  $event
     */
    private function publish(string $key, array $event): void
    {
        Redis::rpush($key, json_encode($event));
        Redis::expire($key, 300);
    }
}
