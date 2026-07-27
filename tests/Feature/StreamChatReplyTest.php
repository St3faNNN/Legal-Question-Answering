<?php

use App\Exceptions\AiServiceException;
use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Jobs\StreamChatReply;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Logging\AiLogger;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;
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
        'context' => 'albert einstein developed the theory of relativity',
        'entities' => ['albert einstein'],
        'triples' => [
            ['head' => 'albert einstein', 'relation' => 'developed', 'tail' => 'theory of relativity', 'confidence' => 0.95],
        ],
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
            yield ['text' => '', 'done' => true, 'tokens' => 5, 'model' => 'lamini-flan-t5-248m'];
        });

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();

        (new StreamChatReply($conversation->id, 'stream-1', 'Who is Albert Einstein?'))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
        );

        $message = $conversation->messages()->where('role', 'assistant')->first();

        expect($message)->not->toBeNull()
            ->and($message->content)->toBe('Albert Einstein.')
            ->and($message->model)->toBe('lamini-flan-t5-248m')
            ->and($message->tokens)->toBe(5)
            ->and($message->metadata['entities'])->toBe(['albert einstein'])
            ->and($message->metadata['top_triples'])->toHaveCount(1);

        $chunkTexts = collect($published)->where('type', 'chunk')->pluck('text')->implode('');
        $done = collect($published)->firstWhere('type', 'done');

        expect($chunkTexts)->toBe('Albert Einstein.')
            ->and($done)->not->toBeNull()
            ->and($done['model'])->toBe('lamini-flan-t5-248m')
            ->and($done['top_triples'])->toHaveCount(1);
    });

    it('publishes an error event and persists no message when retrieval fails', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        bindStreamingRetrieval(function (): array {
            throw AiServiceException::connectionFailed('retrieval', new RuntimeException('gRPC UNAVAILABLE'));
        });
        bindStreamingGeneration(fn (): iterable => []); // never reached: retrieval throws first

        $published = [];
        Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use (&$published): int {
            $published[] = json_decode($json, true);

            return 1;
        });
        Redis::shouldReceive('expire')->andReturnTrue();

        (new StreamChatReply($conversation->id, 'stream-err', 'Will this survive?'))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
        );

        $error = collect($published)->firstWhere('type', 'error');

        expect($error)->not->toBeNull()
            ->and($error['service'])->toBe('retrieval')
            ->and($error['reason'])->toBe('connection')
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

        Queue::assertPushed(StreamChatReply::class);

        expect($user->conversations()->first()->messages()->where('role', 'user')->count())->toBe(1);
    });

    it('requires authentication', function (): void {
        postJson('/api/v1/chat/stream', ['message' => 'hi'])->assertUnauthorized();
    });
});
