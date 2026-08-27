<?php

namespace App\Services\Chat;

use App\Contracts\GeneratesReplies;
use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Models\Conversation;
use App\Services\Chat\Concerns\ComposesGraphRagReply;
use App\Services\Logging\AiLogger;

/**
 * Reply generator backed by the GraphRAG microservices over gRPC. Identical
 * orchestration and metadata to {@see GraphRagReplyGenerator}; only the
 * transport differs. The two clients are injected so the generator stays
 * decoupled from the PHP grpc extension and the generated stubs — the concrete
 * gRPC clients are bound only under the grpc driver (Docker), and tests bind
 * fakes.
 */
class GraphRagGrpcReplyGenerator implements GeneratesReplies
{
    use ComposesGraphRagReply;

    public function __construct(
        private readonly RetrievalClient $retrieval,
        private readonly GenerationClient $generation,
        private readonly AiLogger $logger,
        private readonly NonLegalInputGate $gate,
    ) {}

    public function reply(Conversation $conversation, string $userMessage): array
    {
        $start = hrtime(true);

        // Short-circuit non-legal input (greetings, small talk, empty) before any
        // embedding, search, graph expansion or LLM call. Narrow by design; a
        // terse real query still reaches retrieval. See NonLegalInputGate.
        if ($this->gate->shouldGate($userMessage)) {
            $this->logger->info('graphrag.input_gated', ['conversation_id' => $conversation->getKey()]);

            return $this->gate->gatedPayload();
        }

        $retrieval = $this->retrieval->retrieve($userMessage);

        $this->logger->info('graphrag.retrieval_completed', [
            'conversation_id' => $conversation->getKey(),
            'entities' => $retrieval['entities'] ?? [],
            'triple_count' => count($retrieval['triples'] ?? []),
            'chunk_count' => count($retrieval['chunks'] ?? []),
            'context_items' => $retrieval['context_items'] ?? null,
            'latency_ms' => $retrieval['latency_ms'] ?? null,
        ]);

        // Conversation history is intentionally omitted: with the small stand-in
        // model, feeding prior turns caused it to copy an earlier answer (e.g.
        // "Who is Stefan" inheriting the Einstein reply). Each question is
        // answered purely from its freshly retrieved context.
        $generation = $this->generation->generate(
            $userMessage,
            $retrieval['context'] ?? '',
            $retrieval['entities'][0] ?? null,
            [],
        );

        $this->logger->info('graphrag.generation_completed', [
            'conversation_id' => $conversation->getKey(),
            'model' => $generation['model'] ?? null,
            'tokens' => $generation['tokens'] ?? null,
            'latency_ms' => $generation['latency_ms'] ?? null,
        ]);

        return $this->buildReplyPayload($retrieval, $generation, $start);
    }
}
