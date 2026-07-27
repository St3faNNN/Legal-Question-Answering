<?php

namespace App\Services\Chat\Concerns;

use App\Exceptions\AiServiceException;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Shared orchestration for the GraphRAG reply generators. Both the HTTP and
 * gRPC drivers link a question to knowledge-graph context, generate a reply,
 * and assemble the same GeneratesReplies payload — only the transport differs.
 */
trait ComposesGraphRagReply
{
    private const CONTEXT_METADATA_LIMIT = 1000;

    private const TOP_TRIPLES_LIMIT = 5;

    private const HISTORY_MESSAGE_LIMIT = 6;

    private const HISTORY_CONTENT_LIMIT = 500;

    /**
     * Assemble the GeneratesReplies payload from the two services' responses.
     * Retrieval output (entities, triple count, truncated context) is stored in
     * the message metadata so retrieval quality is inspectable per message.
     *
     * @param  array{context?: string, entities?: list<string>, triples?: array<int, mixed>, latency_ms?: int|null}  $retrieval
     * @param  array{answer?: string, model?: string|null, tokens?: int|null, latency_ms?: int|null}  $generation
     * @return array{answer: string, model: ?string, tokens: ?int, latency_ms: ?int, metadata: array<string, mixed>}
     */
    private function buildReplyPayload(array $retrieval, array $generation, int $startNs): array
    {
        if (! isset($generation['answer']) || ! is_string($generation['answer']) || $generation['answer'] === '') {
            throw AiServiceException::invalidResponse('generation', 'missing answer field');
        }

        return [
            'answer' => $generation['answer'],
            'model' => $generation['model'] ?? 'bart-jeopardy-final',
            'tokens' => $generation['tokens'] ?? null,
            'latency_ms' => (int) ((hrtime(true) - $startNs) / 1_000_000),
            'metadata' => [
                'entities' => $retrieval['entities'] ?? [],
                'triple_count' => count($retrieval['triples'] ?? []),
                'top_triples' => $this->topTriples($retrieval['triples'] ?? []),
                'context' => Str::limit($retrieval['context'] ?? '', self::CONTEXT_METADATA_LIMIT),
                'retrieval_latency_ms' => $retrieval['latency_ms'] ?? null,
                'generation_latency_ms' => $generation['latency_ms'] ?? null,
            ],
        ];
    }

    /**
     * The highest-ranked knowledge-graph triples the answer was grounded in,
     * capped at TOP_TRIPLES_LIMIT. Retrieval already returns them in descending
     * relevance order, so the leading slice is the "top" set surfaced in the UI.
     *
     * @param  array<int, mixed>  $triples
     * @return list<array{head: string, relation: string, tail: string, confidence: float|null}>
     */
    private function topTriples(array $triples): array
    {
        return collect($triples)
            ->take(self::TOP_TRIPLES_LIMIT)
            ->filter(fn ($triple): bool => is_array($triple))
            ->map(fn (array $triple): array => [
                'head' => (string) ($triple['head'] ?? ''),
                'relation' => (string) ($triple['relation'] ?? ''),
                'tail' => (string) ($triple['tail'] ?? ''),
                'confidence' => isset($triple['confidence']) ? (float) $triple['confidence'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The conversation's most recent messages in chronological order, so the
     * Generation Service can resolve follow-up references ("Where was he
     * born?"). Skips the just-persisted current user message.
     *
     * @return list<array{role: string, content: string}>
     */
    private function conversationHistory(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderByDesc('id')
            ->skip(1)
            ->take(self::HISTORY_MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $message): array => [
                'role' => $message->role,
                'content' => Str::limit($message->content, self::HISTORY_CONTENT_LIMIT),
            ])
            ->all();
    }
}
