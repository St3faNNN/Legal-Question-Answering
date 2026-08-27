<?php

use App\Exceptions\AiServiceException;
use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Http\Controllers\Api\V1\ChatController;
use App\Jobs\StreamChatReply;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\NonLegalInputGate;
use App\Services\Logging\AiLogger;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

function bindStreamingRetrieval(Closure $handler): void
{
    app()->instance(RetrievalClient::class, new class($handler) implements RetrievalClient
    {
        public function __construct(private readonly Closure $handler) {}

        public function retrieve(string $question): array
        {
            return ($this->handler)($question);
        }

        public function references(string $law, string $article): array
        {
            return ['found' => false, 'source_citation' => '', 'references' => []];
        }
    });
}

function bindStreamingGeneration(Closure $streamHandler): void
{
    app()->instance(GenerationClient::class, new class($streamHandler) implements GenerationClient
    {
        public function __construct(private readonly Closure $streamHandler) {}

        public function generate(string $question, string $context, ?string $entity, array $history): array
        {
            return ['answer' => 'unused', 'model' => 'm', 'tokens' => 1, 'latency_ms' => 1];
        }

        public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
        {
            return ($this->streamHandler)($question, $context, $entity, $history);
        }
    });
}

function retrievalSuccessData(): array
{
    return [
        'context' => "[ZAKON-A, чл. 1]\nРаботно време и распоред",
        'entities' => ['ZAKON-A, чл. 1'],
        'triples' => [
            ['head' => 'ZAKON-A, чл. 1', 'relation' => 'упатува на', 'tail' => 'ZAKON-A, чл. 3', 'confidence' => 0.75],
        ],
        'chunks' => [
            ['id' => '1', 'text' => 'Работно време и распоред', 'source' => 'ZAKON-A, чл. 1', 'score' => 7.5],
        ],
        'context_items' => 1,
        'latency_ms' => 8,
    ];
}

describe('StreamChatReply job', function (): void {
    it('streams chunks to redis and persists the final assistant message', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(fn (): array => retrievalSuccessData());
        bindStreamingGeneration(function (): iterable {
            yield ['text' => 'Albert ', 'done' => false, 'tokens' => 0, 'model' => ''];
            yield ['text' => 'Einstein.', 'done' => false, 'tokens' => 0, 'model' => ''];
            yield ['text' => '', 'done' => true, 'tokens' => 5, 'model' => 'qwen2.5-1.5b-instruct'];
        });

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        $receivedAt = microtime(true);
        (new StreamChatReply($conversation->id, 'stream-1', 'Who is Albert Einstein?', $receivedAt))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        );

        $message = $conversation->messages()->where('role', 'assistant')->first();

        expect($message)->not->toBeNull()
            ->and($message->content)->toBe('Albert Einstein.')
            ->and($message->model)->toBe('qwen2.5-1.5b-instruct')
            ->and($message->tokens)->toBe(5)
            ->and($message->metadata['entities'])->toBe(['ZAKON-A, чл. 1'])
            ->and($message->metadata['top_triples'])->toHaveCount(1)
            ->and($message->metadata['top_chunks'])->toHaveCount(1)
            ->and($message->metadata['context_items'])->toBe(1)
            // Split timers: retrieval, time-to-first-token, and end-to-end total.
            ->and($message->metadata['total_latency_ms'])->toBeGreaterThanOrEqual(0)
            ->and($message->metadata['retrieval_ms'])->toBeGreaterThanOrEqual(0)
            ->and($message->metadata['ttft_ms'])->toBeGreaterThanOrEqual(0)
            ->and($message->metadata['total_ms'])->toBeGreaterThanOrEqual(0);

        $chunkTexts = collect($published)->where('type', 'chunk')->pluck('text')->implode('');
        $done = collect($published)->firstWhere('type', 'done');

        expect($chunkTexts)->toBe('Albert Einstein.')
            ->and($done)->not->toBeNull()
            ->and($done['model'])->toBe('qwen2.5-1.5b-instruct')
            ->and($done['top_triples'])->toHaveCount(1)
            ->and($done)->toHaveKey('total_ms')
            ->and($done['total_ms'])->toBeGreaterThanOrEqual(0)
            ->and($done)->toHaveKey('retrieval_ms')
            ->and($done)->toHaveKey('ttft_ms')
            ->and($done['ttft_ms'])->toBeGreaterThanOrEqual(0);
    });

    it('publishes the retrieved passages on the done event so the live bubble can cite them', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(fn (): array => retrievalSuccessData());
        bindStreamingGeneration(function (): iterable {
            yield ['text' => 'Одговор.', 'done' => false, 'tokens' => 0, 'model' => ''];
            yield ['text' => '', 'done' => true, 'tokens' => 3, 'model' => 'qwen2.5-1.5b-instruct'];
        });

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        (new StreamChatReply($conversation->id, 'stream-chunks', 'Колку е работното време?', microtime(true)))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        );

        $done = collect($published)->firstWhere('type', 'done');

        expect($done['top_chunks'])->toBe([
            ['id' => '1', 'text' => 'Работно време и распоред', 'source' => 'ZAKON-A, чл. 1', 'score' => 7.5],
        ]);
    });

    it('announces each stage so the relay sees traffic during the silent retrieval phase', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(fn (): array => retrievalSuccessData());
        bindStreamingGeneration(function (): iterable {
            yield ['text' => 'Одговор.', 'done' => false, 'tokens' => 0, 'model' => ''];
            yield ['text' => '', 'done' => true, 'tokens' => 3, 'model' => 'qwen2.5-1.5b-instruct'];
        });

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        (new StreamChatReply($conversation->id, 'stream-status', 'Колку трае одморот?', microtime(true)))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        );

        $statuses = collect($published)->where('type', 'status')->values();

        // The retrieving status must precede any retrieval work, so the relay's
        // silence budget is reset before the slow phase, not after it.
        expect($published[0]['type'])->toBe('status')
            ->and($published[0]['stage'])->toBe('retrieving')
            ->and($statuses->pluck('stage')->all())->toBe(['retrieving', 'generating'])
            ->and($statuses->last()['chunk_count'])->toBe(1);
    });

    it('gates non-legal input in the stream with no retrieval or generation', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(function (): array {
            throw new RuntimeException('retrieval must not run for gated input');
        });
        bindStreamingGeneration(function (): iterable {
            throw new RuntimeException('generation must not run for gated input');
        });

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);

        (new StreamChatReply($conversation->id, 'stream-gate', 'Kako si', microtime(true)))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        );

        // No 'status' events (retrieving/generating) — just the canned answer and done.
        expect(collect($published)->pluck('type')->all())->toBe(['chunk', 'done']);

        $message = $conversation->messages()->where('role', 'assistant')->first();
        expect($message)->not->toBeNull()
            ->and($message->metadata['gated'])->toBeTrue()
            ->and($message->metadata['top_chunks'])->toBe([])
            ->and($message->content)->toContain('правни прашања');
    });

    it('announces the retrieving stage, then lets the failure propagate to be recorded', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(function (): array {
            throw AiServiceException::connectionFailed('retrieval', new RuntimeException('gRPC DEADLINE_EXCEEDED'));
        });
        bindStreamingGeneration(fn (): iterable => []);

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        $job = new StreamChatReply($conversation->id, 'stream-status-err', 'Прашање?', microtime(true));

        // handle() no longer swallows the failure: the retrieving status is
        // announced, then the exception propagates so the queue records the job
        // in failed_jobs. The queue would then invoke failed() (simulated here),
        // which is what publishes the terminal error to the browser.
        expect(fn () => $job->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        ))->toThrow(AiServiceException::class);

        expect(collect($published)->pluck('type')->all())->toBe(['status']);

        $job->failed(AiServiceException::connectionFailed('retrieval', new RuntimeException('gRPC DEADLINE_EXCEEDED')));

        expect(collect($published)->pluck('type')->all())->toBe(['status', 'error']);
    });

    it('propagates the failure (so it lands in failed_jobs) and persists no message when retrieval fails', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(function (): array {
            throw AiServiceException::connectionFailed('retrieval', new RuntimeException('gRPC UNAVAILABLE'));
        });
        bindStreamingGeneration(fn (): iterable => []); // never reached: retrieval throws first

        Redis::shouldReceive('rpush')->andReturn(1);
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        $job = new StreamChatReply($conversation->id, 'stream-err', 'Will this survive?', microtime(true));

        expect(fn () => $job->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        ))->toThrow(AiServiceException::class);

        expect($conversation->messages()->where('role', 'assistant')->count())->toBe(0);
    });

    it('publishes a terminal error event from failed() so a killed job still unblocks the client', function (): void {
        $conversation = Conversation::factory()->for(User::factory())->create();

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        $job = new StreamChatReply($conversation->id, 'stream-failed', 'Прашање?', microtime(true));
        $job->failed(AiServiceException::connectionFailed('generation', new RuntimeException('gRPC UNAVAILABLE')));

        $error = collect($published)->firstWhere('type', 'error');

        expect($error)->not->toBeNull()
            ->and($error['service'])->toBe('generation')
            ->and($error['reason'])->toBe('connection')
            ->and($error['message'])->toBe('The AI service is currently unavailable. Please try again.');
    });

    it('publishes a generic terminal error from failed() when the worker crashes for a non-service reason', function (): void {
        $conversation = Conversation::factory()->for(User::factory())->create();

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        $job = new StreamChatReply($conversation->id, 'stream-crash', 'Прашање?', microtime(true));
        $job->failed(new RuntimeException('worker segfault'));

        $error = collect($published)->firstWhere('type', 'error');

        expect($error)->not->toBeNull()
            ->and($error['service'])->toBe('worker')
            ->and($error['reason'])->toBe('crashed')
            ->and($error['message'])->toBe('The assistant stopped unexpectedly. Please try again.');
    });

    it('sizes the job timeout to the hosted generation deadline on the default driver', function (): void {
        config([
            'ai.generator' => 'openai_compatible',
            'ai.retrieval_timeout' => 45,
            'ai.llm.timeout' => 30,
        ]);

        $job = new StreamChatReply(1, 'stream-timeout-hosted', 'Прашање?', microtime(true));

        // retrieval (45) + hosted whole-request cap (30) + margin (15): tight
        // enough to surface a hang fast, above the legitimate per-request sum.
        expect($job->timeout)->toBe(90)
            ->and($job->timeout)->toBeLessThanOrEqual(StreamChatReply::TIMEOUT_SECONDS);
    });

    it('sizes the job timeout to the slower grpc fallback when that driver is active', function (): void {
        config([
            'ai.generator' => 'grpc',
            'ai.retrieval_timeout' => 45,
            'ai.timeout' => 240,
        ]);

        $job = new StreamChatReply(1, 'stream-timeout-grpc', 'Прашање?', microtime(true));

        // retrieval (45) + llama.cpp call bound AI_TIMEOUT (240) + margin (15) —
        // the worst case, and exactly the ceiling retry_after is sized above.
        expect($job->timeout)->toBe(300)
            ->and($job->timeout)->toBe(StreamChatReply::TIMEOUT_SECONDS);
    });

    it('is configured to retry with a short backoff', function (): void {
        $job = new StreamChatReply(1, 'stream-cfg', 'Прашање?', microtime(true));

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe(2);
    });

    it('does not retry a failure once the first token has been streamed', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(fn (): array => retrievalSuccessData());
        bindStreamingGeneration(function (): iterable {
            yield ['text' => 'Почеток ', 'done' => false, 'tokens' => 0, 'model' => ''];
            // The provider drops after a token has already reached the client.
            throw AiServiceException::connectionFailed('generation', new RuntimeException('stream reset'));
        });

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        $job = new StreamChatReply($conversation->id, 'stream-posttoken', 'Прашање?', microtime(true));

        // Post-token: the exception is caught and turned into a permanent failure
        // rather than re-thrown, so the queue never retries and replays billed
        // tokens. ($this->fail() no-ops without a queue Job in a unit context, so
        // handle() simply returns instead of throwing.)
        $job->handle(app(RetrievalClient::class), app(GenerationClient::class), app(AiLogger::class), app(NonLegalInputGate::class));

        expect(collect($published)->where('type', 'chunk')->pluck('text')->all())->toBe(['Почеток '])
            ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(0);
    });

    it('skips generation when a completed message already exists for the stream', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        // A prior run already produced and persisted the reply for this stream.
        $conversation->messages()->create([
            'role' => 'assistant',
            'stream_id' => 'stream-dup',
            'content' => 'Веќе одговорено.',
        ]);

        // Retrieval throws if reached — proving the guard skips before it.
        bindStreamingRetrieval(function (): array {
            throw new RuntimeException('retrieval must not run for a duplicate');
        });
        bindStreamingGeneration(fn (): iterable => []);

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0); // marker absent; the DB row is the guard
        Redis::shouldReceive('setex')->andReturnTrue();

        $job = new StreamChatReply($conversation->id, 'stream-dup', 'Прашање?', microtime(true));
        $job->handle(app(RetrievalClient::class), app(GenerationClient::class), app(AiLogger::class), app(NonLegalInputGate::class));

        // Returned before the retrieving status, and no second message created.
        expect($published)->toBeEmpty()
            ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1);
    });

    it('skips generation when a prior run already emitted a token (durable marker)', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(function (): array {
            throw new RuntimeException('retrieval must not run when the marker is set');
        });
        bindStreamingGeneration(fn (): iterable => []);

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('setex')->andReturnTrue();
        // The marker is present: a prior run reached the first token but died
        // before persisting the message (the uncatchable-kill case).
        Redis::shouldReceive('exists')
            ->with(StreamChatReply::emittedKey('stream-marked'))
            ->andReturn(1);

        $job = new StreamChatReply($conversation->id, 'stream-marked', 'Прашање?', microtime(true));
        $job->handle(app(RetrievalClient::class), app(GenerationClient::class), app(AiLogger::class), app(NonLegalInputGate::class));

        expect($published)->toBeEmpty()
            ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(0);
    });
});

describe('POST /chat/stream', function (): void {
    it('persists the user message, dispatches the job, and returns 202', function (): void {
        Queue::fake();
        Redis::shouldReceive('setex')->andReturnTrue();

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', ['message' => 'Who is Albert Einstein?'])
            ->assertStatus(202)
            ->assertJsonStructure(['conversation' => ['id'], 'stream_id']);

        Queue::assertPushed(
            StreamChatReply::class,
            fn (StreamChatReply $job): bool => $job->receivedAt > 0,
        );

        expect($user->conversations()->first()->messages()->where('role', 'user')->count())->toBe(1);
    });

    it('requires authentication', function (): void {
        postJson('/api/v1/chat/stream', ['message' => 'hi'])->assertUnauthorized();
    });
});

describe('GET /chat/stream/{id} relay', function (): void {
    it('closes a silent stream with a terminal error when the first chunk never arrives', function (): void {
        // The worker died before producing anything and left no terminal event
        // (the SIGKILL case failed() cannot cover). With the first-chunk budget
        // elapsed, the relay must close with an explicit error rather than
        // hanging on the client until a bare disconnect.
        config(['ai.stream.first_chunk_timeout' => 0, 'ai.stream.poll_seconds' => 1]);

        $user = User::factory()->create();
        actingAs($user);

        Redis::shouldReceive('get')->with('chat:stream:silent-1:user')->andReturn((string) $user->id);

        $connection = Mockery::mock();
        $connection->shouldReceive('blpop')->andReturn(null);
        Redis::shouldReceive('connection')->andReturn($connection);

        $errors = collect(explode("\n", get('/api/v1/chat/stream/silent-1')->streamedContent()))
            ->filter(fn (string $line): bool => str_starts_with($line, 'data: '))
            ->map(fn (string $line): array => json_decode(substr($line, 6), true))
            ->where('type', 'error')
            ->values();

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['reason'])->toBe('timeout');
    });

    it('derives the first-chunk silence budget from the active generation driver', function (): void {
        config([
            'ai.stream.first_chunk_timeout' => null,
            'ai.stream.first_chunk_timeout_hosted' => 30,
            'ai.stream.first_chunk_timeout_grpc' => 120,
        ]);

        $resolve = (new ReflectionMethod(ChatController::class, 'firstChunkTimeout'))
            ->getClosure(app(ChatController::class));

        config(['ai.generator' => 'openai_compatible']);
        expect($resolve())->toBe(30);

        config(['ai.generator' => 'grpc']);
        expect($resolve())->toBe(120);

        // An explicit pin wins over the per-driver derivation for both.
        config(['ai.stream.first_chunk_timeout' => 45]);
        expect($resolve())->toBe(45);
    });

    it('closes with a terminal error when tokens stall mid-stream', function (): void {
        // Once tokens flow, the far tighter between-chunk budget applies: a
        // provider that goes silent mid-answer is surfaced quickly, and the
        // already-delivered chunk is not re-sent.
        config(['ai.stream.between_chunk_timeout' => 0, 'ai.stream.poll_seconds' => 1]);

        $user = User::factory()->create();
        actingAs($user);

        Redis::shouldReceive('get')->with('chat:stream:stall-1:user')->andReturn((string) $user->id);

        $calls = 0;
        $connection = Mockery::mock();
        $connection->shouldReceive('blpop')->andReturnUsing(function () use (&$calls): ?array {
            $calls++;

            return $calls === 1 ? [null, json_encode(['type' => 'chunk', 'text' => 'Од'])] : null;
        });
        Redis::shouldReceive('connection')->andReturn($connection);

        $payloads = collect(explode("\n", get('/api/v1/chat/stream/stall-1')->streamedContent()))
            ->filter(fn (string $line): bool => str_starts_with($line, 'data: '))
            ->map(fn (string $line): array => json_decode(substr($line, 6), true))
            ->values();

        expect($payloads)->toHaveCount(2)
            ->and($payloads[0]['type'])->toBe('chunk')
            ->and($payloads[1]['type'])->toBe('error')
            ->and($payloads[1]['reason'])->toBe('stalled');
    });

    it('forwards status events without ending the stream', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        Redis::shouldReceive('get')->with('chat:stream:relay-1:user')->andReturn((string) $user->id);

        $events = [
            [null, json_encode(['type' => 'status', 'stage' => 'retrieving'])],
            [null, json_encode(['type' => 'status', 'stage' => 'generating', 'chunk_count' => 10])],
            [null, json_encode(['type' => 'chunk', 'text' => 'Одговор'])],
            [null, json_encode(['type' => 'done', 'message_id' => 7])],
        ];

        $connection = Mockery::mock();
        // By reference: an arrow function would capture a copy and re-shift the
        // same first event forever.
        $connection->shouldReceive('blpop')->andReturnUsing(function () use (&$events): ?array {
            return array_shift($events);
        });
        Redis::shouldReceive('connection')->andReturn($connection);

        $content = get('/api/v1/chat/stream/relay-1')->streamedContent();

        // Decoded rather than matched as text: json_encode escapes Cyrillic to
        // \uXXXX, which the browser's JSON.parse resolves but a substring
        // assertion would not.
        $payloads = collect(explode("\n", $content))
            ->filter(fn (string $line): bool => str_starts_with($line, 'data: '))
            ->map(fn (string $line): array => json_decode(substr($line, 6), true))
            ->values();

        expect($payloads)->toHaveCount(4)
            ->and($payloads[0]['stage'])->toBe('retrieving')
            ->and($payloads[1]['chunk_count'])->toBe(10)
            ->and($payloads[2]['text'])->toBe('Одговор')
            ->and($payloads[3]['type'])->toBe('done');
    });
});
