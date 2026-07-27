<?php

namespace App\Grpc;

/**
 * Transport-agnostic view of the Generation Service the reply generator depends
 * on. The concrete gRPC implementation ({@see GrpcGenerationClient}) is only
 * bound when the grpc driver is active (Docker); tests bind a fake.
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
