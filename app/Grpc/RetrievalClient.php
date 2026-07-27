<?php

namespace App\Grpc;

/**
 * Transport-agnostic view of the Retrieval Service the reply generator depends
 * on. The concrete gRPC implementation ({@see GrpcRetrievalClient}) is only
 * bound when the grpc driver is active (Docker); tests bind a fake. This keeps
 * the generator and its tests independent of the PHP grpc extension and the
 * generated protobuf stubs.
 */
interface RetrievalClient
{
    /**
     * @return array{
     *     context: string,
     *     entities: list<string>,
     *     triples: list<array{head: string, relation: string, tail: string, confidence: float}>,
     *     latency_ms: int,
     * }
     */
    public function retrieve(string $question): array;
}
