<?php

use App\Grpc\GenerationClient;
use App\Grpc\OpenAiCompatibleGenerationClient;
use App\Grpc\RetrievalClient;
use App\Jobs\StreamChatReply;
use Tests\Support\InertAiClients;

/**
 * Container bindings for the AI path.
 *
 * Local generation was removed: a llama.cpp / transformers service on the same
 * host is not viable on a t3.medium. The hosted OpenAI-compatible endpoint is now
 * the ONLY generation backend — not the default among several — so these tests
 * assert the absence of a driver branch as much as the presence of a binding.
 *
 * The branch that was removed mattered: its default arm bound a placeholder
 * generator that answered every question with the literal string "AI service not
 * connected yet." as a successful assistant message — HTTP 201, no exception, no
 * failed_jobs row. It was reachable by a typo in AI_GENERATOR, and the live .env
 * was sitting on it.
 */
beforeEach(function (): void {
    // Assert on the real bindings, not the suite-wide stand-ins.
    InertAiClients::forgetGeneration();

    config([
        'ai.llm.base_url' => 'https://example.test/v1/',
        'ai.llm.api_key' => 'test-key',
        'ai.llm.model' => 'gemini-flash-lite-latest',
        'ai.llm.max_tokens' => 1024,
        'ai.llm.timeout' => 30,
        'ai.llm.prompt_path' => base_path('prompts/legal_mk.txt'),
    ]);
});

it('resolves generation to the hosted client', function (): void {
    expect(app(GenerationClient::class))->toBeInstanceOf(OpenAiCompatibleGenerationClient::class);
});

it('resolves the same hosted client whatever ai.generator says', function (string $stale): void {
    // The variable is gone from config, but a stale value left in someone's .env
    // must not resurrect a second code path or silence generation.
    config(['ai.generator' => $stale]);

    expect(app(GenerationClient::class))->toBeInstanceOf(OpenAiCompatibleGenerationClient::class);
})->with(['placeholder', 'grpc', 'http', '', 'typo']);

it('has no placeholder or local-generation class left to resolve', function (): void {
    expect(class_exists('App\Services\Chat\PlaceholderReplyGenerator'))->toBeFalse()
        ->and(class_exists('App\Services\Chat\GraphRagReplyGenerator'))->toBeFalse()
        ->and(class_exists('App\Grpc\GrpcGenerationClient'))->toBeFalse()
        // The unary reply path went with the non-streaming endpoint: one
        // implementation behind an interface with one caller is not a seam.
        ->and(interface_exists('App\Contracts\GeneratesReplies'))->toBeFalse()
        ->and(class_exists('App\Services\Chat\GraphRagGrpcReplyGenerator'))->toBeFalse()
        ->and(trait_exists('App\Services\Chat\Concerns\ComposesGraphRagReply'))->toBeFalse()
        // The generation protobuf stubs are gone with the service.
        ->and(class_exists('App\Grpc\Pb\Generation\GenerationClient'))->toBeFalse();
});

it('keeps the retrieval layer intact', function (): void {
    // Retrieval is a separate service on :8001 and is NOT part of this removal.
    // Checked on the filesystem rather than with class_exists: the generated
    // stub extends \Grpc\BaseStub, so autoloading it without ext-grpc is a fatal
    // error, and ext-grpc only exists inside the Docker image.
    expect(interface_exists(RetrievalClient::class))->toBeTrue()
        ->and(file_exists(app_path('Grpc/GrpcRetrievalClient.php')))->toBeTrue()
        ->and(file_exists(app_path('Grpc/Pb/Retrieval/RetrievalClient.php')))->toBeTrue()
        ->and(file_exists(base_path('proto/retrieval.proto')))->toBeTrue()
        // …while the generation half of the same layer is gone.
        ->and(file_exists(app_path('Grpc/Pb/Generation')))->toBeFalse()
        ->and(file_exists(base_path('proto/generation.proto')))->toBeFalse()
        ->and(file_exists(base_path('services/generation')))->toBeFalse();
});

it('reports the generation service as one hop, not a choice of hops', function (): void {
    // The job's timeout used to branch on the driver (300s for local llama.cpp,
    // 90s hosted). With one backend there is one sum.
    config(['ai.retrieval_timeout' => 45, 'ai.llm.timeout' => 30]);

    expect((new StreamChatReply(1, 's', 'q', microtime(true)))->timeout)->toBe(90);
});
