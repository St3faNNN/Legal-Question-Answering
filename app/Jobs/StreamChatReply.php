<?php

namespace App\Jobs;

use App\Exceptions\AiServiceException;
use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\Concerns\ComposesGraphRagReply;
use App\Services\Logging\AiLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * Orchestrates one streamed chat reply off the web request: retrieve context
 * (gRPC unary), then generate the answer (gRPC server-streaming), pushing each
 * token chunk onto a Redis list keyed by the stream id. The SSE endpoint drains
 * that list to the browser. The final assistant Message (full text + retrieval
 * metadata) is persisted so history/reloads work.
 */
class StreamChatReply implements ShouldQueue
{
    use ComposesGraphRagReply, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** CPU generation can be slow; give the job room beyond the default 60s. */
    public int $timeout = 180;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $streamId,
        public readonly string $userMessage,
    ) {}

    public function handle(RetrievalClient $retrieval, GenerationClient $generation, AiLogger $logger): void
    {
        $key = self::channelKey($this->streamId);
        $start = hrtime(true);

        try {
            $retrievalStart = hrtime(true);
            $retrievalResult = $retrieval->retrieve($this->userMessage);
            $retrievalRoundtripMs = (int) ((hrtime(true) - $retrievalStart) / 1_000_000);

            $logger->info('graphrag.retrieval_completed', [
                'stream_id' => $this->streamId,
                'entities' => $retrievalResult['entities'] ?? [],
                'triple_count' => count($retrievalResult['triples'] ?? []),
                'roundtrip_ms' => $retrievalRoundtripMs,
                'service_ms' => $retrievalResult['latency_ms'] ?? null,
            ]);

            $full = '';
            $model = null;
            $tokens = null;
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
                    $full .= $chunk['text'];
                    $this->publish($key, ['type' => 'chunk', 'text' => $chunk['text']]);
                }
            }

            $generationRoundtripMs = (int) ((hrtime(true) - $generationStart) / 1_000_000);

            $payload = $this->buildReplyPayload(
                $retrievalResult,
                ['answer' => $full, 'model' => $model, 'tokens' => $tokens, 'latency_ms' => $generationRoundtripMs],
                $start,
            );

            $conversation = Conversation::findOrFail($this->conversationId);
            /** @var Message $message */
            $message = $conversation->messages()->create([
                'role' => 'assistant',
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
            ]);

            $this->publish($key, [
                'type' => 'done',
                'message_id' => $message->id,
                'model' => $payload['model'],
                'tokens' => $payload['tokens'],
                'top_triples' => $payload['metadata']['top_triples'],
            ]);
        } catch (AiServiceException $exception) {
            $logger->error('graphrag.stream_failed', [
                'stream_id' => $this->streamId,
                'service' => $exception->service,
                'reason' => $exception->reason,
            ]);

            $this->publish($key, [
                'type' => 'error',
                'service' => $exception->service,
                'reason' => $exception->reason,
                'message' => 'The AI service is currently unavailable. Please try again.',
            ]);
        }
    }

    public static function channelKey(string $streamId): string
    {
        return "chat:stream:{$streamId}";
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
