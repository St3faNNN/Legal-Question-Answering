<?php

namespace App\Services\Chat;

use App\Contracts\GeneratesReplies;
use App\Exceptions\AiServiceException;
use App\Models\Conversation;
use App\Services\Chat\Concerns\ComposesGraphRagReply;
use App\Services\Logging\AiLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reply generator backed by the GraphRAG microservices over HTTP/JSON: the
 * Retrieval Service links the question to knowledge-graph context, the
 * Generation Service turns question + context into a reply via the fine-tuned
 * BART checkpoint.
 */
class GraphRagReplyGenerator implements GeneratesReplies
{
    use ComposesGraphRagReply;

    public function __construct(private readonly AiLogger $logger) {}

    public function reply(Conversation $conversation, string $userMessage): array
    {
        $start = hrtime(true);
        $timeout = (int) config('ai.timeout', 30);

        $retrieval = $this->post(
            'retrieval',
            config('ai.endpoints.retrieval').'/retrieve',
            ['question' => $userMessage],
            $timeout,
            $conversation,
        );

        $this->logger->info('graphrag.retrieval_completed', [
            'conversation_id' => $conversation->getKey(),
            'entities' => $retrieval['entities'] ?? [],
            'triple_count' => count($retrieval['triples'] ?? []),
            'latency_ms' => $retrieval['latency_ms'] ?? null,
        ]);

        $generation = $this->post(
            'generation',
            config('ai.endpoints.generation').'/generate',
            [
                'question' => $userMessage,
                'context' => $retrieval['context'] ?? '',
                'entity' => $retrieval['entities'][0] ?? null,
                // History omitted: the small stand-in model copied prior answers
                // when history was included. Answer from retrieved context only.
                'history' => [],
            ],
            $timeout,
            $conversation,
        );

        $this->logger->info('graphrag.generation_completed', [
            'conversation_id' => $conversation->getKey(),
            'model' => $generation['model'] ?? null,
            'tokens' => $generation['tokens'] ?? null,
            'latency_ms' => $generation['latency_ms'] ?? null,
        ]);

        return $this->buildReplyPayload($retrieval, $generation, $start);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(
        string $service,
        string $url,
        array $payload,
        int $timeout,
        Conversation $conversation,
    ): array {
        try {
            $response = Http::timeout($timeout)->acceptJson()->post($url, $payload);
        } catch (ConnectionException $exception) {
            $this->logFailure($service, 'connection', null, $conversation);

            throw AiServiceException::connectionFailed($service, $exception);
        }

        if ($response->failed()) {
            $this->logFailure($service, 'http_error', $response->status(), $conversation);

            throw AiServiceException::httpError($service, $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            $this->logFailure($service, 'invalid_response', $response->status(), $conversation);

            throw AiServiceException::invalidResponse($service, 'non-JSON body');
        }

        return $body;
    }

    private function logFailure(string $service, string $reason, ?int $status, Conversation $conversation): void
    {
        $this->logger->error('graphrag.request_failed', [
            'conversation_id' => $conversation->getKey(),
            'service' => $service,
            'reason' => $reason,
            'status' => $status,
        ]);
    }
}
