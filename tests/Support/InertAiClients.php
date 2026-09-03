<?php

namespace Tests\Support;

use App\Ai\GenerationClient;
use App\Ai\RetrievalClient;
use RuntimeException;

/**
 * Inert AI clients bound by default across the Feature suite.
 *
 * Removing the placeholder generator removed the thing that let a chat
 * controller be *constructed* without an AI backend. The reply path now always
 * builds the GraphRAG orchestrator, which constructor-injects RetrievalClient and
 * GenerationClient — and the real RetrievalClient instantiates a gRPC stub, which
 * needs ext-grpc. That extension is absent outside the Docker image, so simply
 * resolving a controller would fatal locally.
 *
 * These stand in so a test that never asks a question does not need to know the
 * AI layer exists. They are deliberately hostile if actually used: any call
 * throws, so a test that reaches generation without binding a real fake fails
 * loudly rather than passing against a silent stub. That is the same principle
 * the production change is about — no quiet fallback that fabricates an answer.
 *
 * Tests that exercise the AI path override these with their own fakes; tests
 * that assert on the real container bindings call forgetInstance() first.
 */
final class InertAiClients
{
    /** Bind both, replacing anything already bound. */
    public static function bind(): void
    {
        app()->instance(RetrievalClient::class, new class implements RetrievalClient
        {
            public function retrieve(string $question): array
            {
                throw new RuntimeException('retrieval was called without a fake bound for this test');
            }

            public function references(string $law, string $article): array
            {
                throw new RuntimeException('references was called without a fake bound for this test');
            }
        });

        app()->instance(GenerationClient::class, new class implements GenerationClient
        {
            public function generate(string $question, string $context, ?string $entity, array $history): array
            {
                throw new RuntimeException('generation was called without a fake bound for this test');
            }

            public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
            {
                throw new RuntimeException('generation was called without a fake bound for this test');
            }
        });
    }

    /**
     * Drop the generation stand-in so the container's real binding resolves.
     *
     * Retrieval is deliberately NOT dropped: its real client instantiates a gRPC
     * stub that extends \Grpc\BaseStub, so resolving it without ext-grpc is a
     * fatal error rather than a test failure. Asserting on the generation binding
     * does not require a real retrieval client, so the stand-in stays.
     */
    public static function forgetGeneration(): void
    {
        app()->forgetInstance(GenerationClient::class);
    }
}
