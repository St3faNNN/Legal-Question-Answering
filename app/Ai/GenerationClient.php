<?php

namespace App\Ai;

/**
 * Transport-agnostic view of the generation backend the reply generator depends
 * on. There is one implementation — the hosted OpenAI-compatible client — and it
 * is bound unconditionally; tests bind a fake. The interface stays because it is
 * the seam those fakes attach to, and because it keeps the reply path from
 * naming a provider.
 *
 * The gRPC implementation this used to point at was deleted with local
 * generation. Only retrieval speaks gRPC now.
 */
interface GenerationClient
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{answer: string, model: string, tokens: int, latency_ms: int}
     */
    public function generate(string $question, string $context, ?string $entity, array $history): array;

    /**
     * Server-streaming generation: yields the answer incrementally, one chunk
     * per element, terminated by a chunk with done = true (carrying the total
     * token count and model name).
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return iterable<array{text: string, done: bool, tokens: int, model: string}>
     */
    public function generateStream(string $question, string $context, ?string $entity, array $history): iterable;
}
