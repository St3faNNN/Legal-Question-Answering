<?php

namespace App\Grpc;

/**
 * Transport-agnostic view of the Retrieval Service the reply generator depends
 * on. The concrete gRPC implementation ({@see GrpcRetrievalClient}) is bound
 * whenever the app runs for real; tests bind a fake. That is the point of the
 * interface: the PHP grpc extension and the generated protobuf stubs exist only
 * inside the Docker image, so without this seam the reply path could not be
 * tested outside a container at all.
 */
interface RetrievalClient
{
    /**
     * @return array{
     *     context: string,
     *     entities: list<string>,
     *     triples: list<array{head: string, relation: string, tail: string, confidence: float}>,
     *     chunks: list<array{id: string, text: string, source: string, score: float}>,
     *     context_items: int,
     *     latency_ms: int,
     * }
     */
    public function retrieve(string $question): array;

    /**
     * Outgoing citations of a single article — the articles it points at. Pure
     * graph traversal over the citation graph; identifier-only (no body text).
     *
     * @return array{
     *     found: bool,
     *     source_citation: string,
     *     references: list<array{citation: string, heading: string, confidence: float}>,
     * }
     */
    public function references(string $law, string $article): array;
}
