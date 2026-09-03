<?php

use App\Ai\GenerationClient;
use App\Ai\RetrievalClient;
use App\Exceptions\AiServiceException;
use App\Jobs\StreamChatReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\MetricsService;
use App\Services\Chat\NonLegalInputGate;
use App\Services\Logging\AiLogger;
use Illuminate\Support\Facades\Redis;
use Tests\Support\InertAiClients;

/**
 * Local generation is gone; the hosted endpoint is the only backend.
 *
 * The value of the removal is not the reclaimed disk. It is that a misconfigured
 * AI_GENERATOR used to bind a placeholder generator whose answer — the literal
 * string "AI service not connected yet." — was written to `messages` as a
 * successful assistant reply: HTTP 201, no exception, no failed_jobs row, and
 * counted as produced by the dashboard's volume panel. A fabricated answer that
 * looks real is worse than an outage, so these tests assert that an unavailable
 * provider now FAILS, loudly and by name.
 *
 * They also assert the historical record survives: the ability to RUN a local
 * model is removed, the record that one ran is not.
 */
it('has no driver branch left to take', function (): void {
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

    // No code reads the selector any more. (The provider still *mentions* the
    // removed classes in a comment explaining why the branch went; that is the
    // point of the comment, so the assertions target code, not prose.)
    expect($provider)->not->toContain("config('ai.generator')")
        ->and($provider)->not->toContain('use App\Services\Chat\PlaceholderReplyGenerator;')
        ->and($provider)->not->toContain('use App\Ai\GrpcGenerationClient;')
        ->and(file_get_contents(app_path('Jobs/StreamChatReply.php')))->not->toContain("config('ai.generator')")
        ->and(file_get_contents(app_path('Http/Controllers/Api/V1/ChatController.php')))->not->toContain("config('ai.generator')")
        // And the classes themselves are gone, so no branch could be re-added
        // without also restoring them.
        ->and(class_exists('App\Services\Chat\PlaceholderReplyGenerator'))->toBeFalse()
        ->and(class_exists('App\Ai\GrpcGenerationClient'))->toBeFalse();
});

it('records a named provider failure in failed_jobs rather than answering', function (): void {
    // The outage case. The provider throws; the job must surface it as a failure
    // that names the service, not as an empty or fabricated answer.
    $conversation = Conversation::factory()->for(User::factory())->create();

    app()->instance(RetrievalClient::class, new class implements RetrievalClient
    {
        public function retrieve(string $question): array
        {
            return ['context' => 'x', 'entities' => [], 'triples' => [], 'chunks' => [], 'context_items' => 0, 'latency_ms' => 1];
        }

        public function references(string $law, string $article): array
        {
            return ['found' => false, 'source_citation' => '', 'references' => []];
        }
    });
    app()->instance(GenerationClient::class, new class implements GenerationClient
    {
        public function generate(string $q, string $c, ?string $e, array $h): array
        {
            throw AiServiceException::connectionFailed('generation', new RuntimeException('provider unreachable'));
        }

        public function generateStream(string $q, string $c, ?string $e, array $h): iterable
        {
            throw AiServiceException::connectionFailed('generation', new RuntimeException('provider unreachable'));
        }
    });

    $published = [];
    Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('expire')->andReturnTrue();
    Redis::shouldReceive('exists')->andReturn(0);
    Redis::shouldReceive('setex')->andReturnTrue();

    $job = new StreamChatReply($conversation->id, 'outage-1', 'Кој е рокот за жалба?', microtime(true));

    // Pre-token: the exception propagates so the queue records it in failed_jobs.
    expect(fn () => $job->handle(
        app(RetrievalClient::class),
        app(GenerationClient::class),
        app(AiLogger::class),
        app(NonLegalInputGate::class),
    ))->toThrow(AiServiceException::class);

    // No answer was invented.
    expect($conversation->messages()->where('role', 'assistant')->count())->toBe(0);

    // And failed() names the provider to the browser.
    $job->failed(AiServiceException::connectionFailed('generation', new RuntimeException('provider unreachable')));
    $error = collect($published)->firstWhere('type', 'error');

    expect($error)->not->toBeNull()
        ->and($error['service'])->toBe('generation')
        ->and($error['reason'])->toBe('connection');
});

it('throws naming LLM_API_KEY when the provider key is missing', function (): void {
    InertAiClients::forgetGeneration();
    config(['ai.llm.base_url' => 'https://example.test/v1/', 'ai.llm.api_key' => null]);

    expect(fn () => app(GenerationClient::class))
        ->toThrow(RuntimeException::class, 'LLM_API_KEY');
});

it('records a null model rather than a retired local one, and counts it', function (): void {
    // The reply composer (now StreamChatReply) used to fall back to
    // 'qwen2.5-1.5b-instruct' — a model
    // that can no longer be run at all — which would have joined the genuine
    // historical bucket in the by-model table.
    $conversation = Conversation::factory()->for(User::factory())->create();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Прашање?']);
    $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Одговор.',
        'model' => null,
        'metadata' => ['refused' => false, 'chunk_count' => 5],
    ]);
    // A gated reply also has a null model, legitimately: it makes no model call.
    $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Гејтирано.',
        'model' => null,
        'metadata' => ['gated' => true],
    ]);

    $nullModel = app(MetricsService::class)->overview()['latency']['null_model'];

    expect($nullModel['count'])->toBe(1)
        ->and($nullModel['gated_excluded'])->toBe(1)
        ->and($nullModel['out_of'])->toBe(2);
});

it('still reports the historical local-model rows in latencyByModel', function (): void {
    // MUST NOT CHANGE: removing the ability to run these models does not remove
    // the record that they ran. The by-model table depends on it.
    $conversation = Conversation::factory()->for(User::factory())->create();

    $historical = [
        'qwen2.5-1.5b-instruct' => 2,
        'qwen2.5-3b-instruct-q4_k_m' => 3,
        'facebook/bart-large' => 1,
        'flan-t5-base' => 1,
        'lamini-flan-t5-248m' => 4,
        'gemini-flash-lite-latest' => 5,
    ];

    foreach ($historical as $model => $count) {
        for ($i = 0; $i < $count; $i++) {
            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'Историски одговор.',
                'model' => $model,
                'latency_ms' => 1000 + $i,
                'metadata' => ['refused' => false, 'chunk_count' => 5, 'total_ms' => 1000 + $i],
            ]);
        }
    }

    $rows = collect(app(MetricsService::class)->overview()['latency']['by_model'])
        ->keyBy('model');

    foreach ($historical as $model => $count) {
        expect($rows)->toHaveKey($model)
            ->and($rows[$model]['answers'])->toBe($count)
            ->and($rows[$model]['p50_ms'])->not->toBeNull();
    }
});

it('leaves a stored qwen model name untouched when a message is read back', function (): void {
    // The narrower guarantee, stated on its own: nothing rewrites the column.
    $conversation = Conversation::factory()->for(User::factory())->create();
    /** @var Message $message */
    $message = $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Стар одговор.',
        'model' => 'qwen2.5-1.5b-instruct',
        'metadata' => ['refused' => false],
    ]);

    expect($message->fresh()->model)->toBe('qwen2.5-1.5b-instruct');
});
