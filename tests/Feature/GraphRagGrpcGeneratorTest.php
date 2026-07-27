<?php

use App\Contracts\GeneratesReplies;
use App\Exceptions\AiServiceException;
use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Models\User;
use App\Services\Chat\GraphRagGrpcReplyGenerator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    config(['ai.generator' => 'grpc', 'ai.timeout' => 5]);
});

/**
 * Bind a fake RetrievalClient whose retrieve() delegates to $handler.
 */
function bindRetrievalClient(Closure $handler): void
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

/**
 * Bind a fake GenerationClient whose generate() delegates to $handler.
 */
function bindGenerationClient(Closure $handler): void
{
    app()->instance(GenerationClient::class, new class($handler) implements GenerationClient
    {
        public function __construct(private readonly Closure $handler) {}

        public function generate(string $question, string $context, ?string $entity, array $history): array
        {
            return ($this->handler)($question, $context, $entity, $history);
        }

        public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
        {
            return []; // unused by the unary generator under test
        }
    });
}

function grpcRetrievalSuccess(): array
{
    return [
        'context' => 'albert einstein developed theory of relativity | albert einstein born_in ulm',
        'entities' => ['albert einstein'],
        'triples' => [
            ['head' => 'albert einstein', 'relation' => 'developed', 'tail' => 'theory of relativity', 'confidence' => 0.95],
            ['head' => 'albert einstein', 'relation' => 'born_in', 'tail' => 'ulm', 'confidence' => 0.9],
        ],
        'latency_ms' => 12,
    ];
}

function grpcGenerationSuccess(): array
{
    return [
        'answer' => 'This physicist developed the theory of relativity.',
        'model' => 'bart-jeopardy-final',
        'tokens' => 14,
        'latency_ms' => 850,
    ];
}

describe('GraphRAG gRPC generator binding', function (): void {
    it('resolves GraphRagGrpcReplyGenerator when the driver is grpc', function (): void {
        bindRetrievalClient(fn (): array => grpcRetrievalSuccess());
        bindGenerationClient(fn (): array => grpcGenerationSuccess());

        expect(app(GeneratesReplies::class))->toBeInstanceOf(GraphRagGrpcReplyGenerator::class);
    });
});

describe('GraphRAG gRPC chat flow', function (): void {
    it('returns the generated reply and stores retrieval metadata', function (): void {
        bindRetrievalClient(fn (): array => grpcRetrievalSuccess());
        bindGenerationClient(fn (): array => grpcGenerationSuccess());

        $user = User::factory()->create();
        actingAs($user);

        $response = postJson('/api/v1/chat', ['message' => 'Who is Albert Einstein?'])
            ->assertCreated();

        expect($response->json('message.role'))->toBe('assistant')
            ->and($response->json('message.content'))->toBe('This physicist developed the theory of relativity.')
            ->and($response->json('message.model'))->toBe('bart-jeopardy-final')
            ->and($response->json('message.tokens'))->toBe(14);

        $assistantMessage = $user->conversations()->first()->messages()->where('role', 'assistant')->first();

        expect($assistantMessage->metadata['entities'])->toBe(['albert einstein'])
            ->and($assistantMessage->metadata['triple_count'])->toBe(2)
            ->and($assistantMessage->metadata['top_triples'])->toBe([
                ['head' => 'albert einstein', 'relation' => 'developed', 'tail' => 'theory of relativity', 'confidence' => 0.95],
                ['head' => 'albert einstein', 'relation' => 'born_in', 'tail' => 'ulm', 'confidence' => 0.9],
            ])
            ->and($assistantMessage->metadata['context'])->toContain('albert einstein developed theory of relativity')
            ->and($assistantMessage->metadata['retrieval_latency_ms'])->toBe(12)
            ->and($assistantMessage->metadata['generation_latency_ms'])->toBe(850);
    });

    it('forwards question, context and entity to the generation client', function (): void {
        $captured = [];
        bindRetrievalClient(fn (): array => grpcRetrievalSuccess());
        bindGenerationClient(function (string $question, string $context, ?string $entity, array $history) use (&$captured): array {
            $captured = compact('question', 'context', 'entity', 'history');

            return grpcGenerationSuccess();
        });

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Who is Albert Einstein?'])->assertCreated();

        expect($captured['question'])->toBe('Who is Albert Einstein?')
            ->and($captured['context'])->toBe('albert einstein developed theory of relativity | albert einstein born_in ulm')
            ->and($captured['entity'])->toBe('albert einstein')
            ->and($captured['history'])->toBe([]);
    });

    it('never sends conversation history to the generator (avoids answer copying)', function (): void {
        $captured = [];
        bindRetrievalClient(fn (): array => grpcRetrievalSuccess());
        bindGenerationClient(function (string $question, string $context, ?string $entity, array $history) use (&$captured): array {
            $captured = compact('question', 'history');

            return grpcGenerationSuccess();
        });

        $user = User::factory()->create();
        actingAs($user);

        $conversationId = postJson('/api/v1/chat', ['message' => 'Who is Albert Einstein?'])
            ->assertCreated()
            ->json('conversation.id');

        postJson('/api/v1/chat', [
            'message' => 'Where was he born?',
            'conversation_id' => $conversationId,
        ])->assertCreated();

        // Even on a follow-up, history is empty: each question is answered from
        // its own retrieved context, so the small model can't copy prior answers.
        expect($captured['question'])->toBe('Where was he born?')
            ->and($captured['history'])->toBe([]);
    });

    it('still generates a reply when retrieval finds no context', function (): void {
        $captured = [];
        bindRetrievalClient(fn (): array => ['context' => '', 'entities' => [], 'triples' => [], 'latency_ms' => 3]);
        bindGenerationClient(function (string $question, string $context, ?string $entity, array $history) use (&$captured): array {
            $captured = compact('context', 'entity');

            return grpcGenerationSuccess();
        });

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Something unknown to the graph'])->assertCreated();

        expect($captured['context'])->toBe('')
            ->and($captured['entity'])->toBeNull();

        $assistantMessage = $user->conversations()->first()->messages()->where('role', 'assistant')->first();

        expect($assistantMessage->metadata['triple_count'])->toBe(0)
            ->and($assistantMessage->metadata['entities'])->toBe([])
            ->and($assistantMessage->metadata['top_triples'])->toBe([]);
    });

    it('returns 503 and keeps the user message when retrieval fails', function (): void {
        bindRetrievalClient(function (): array {
            throw AiServiceException::connectionFailed('retrieval', new RuntimeException('gRPC UNAVAILABLE'));
        });
        // Both clients are constructor-injected, so both must resolve even
        // though generation is never reached once retrieval throws.
        bindGenerationClient(fn (): array => grpcGenerationSuccess());

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Will this survive?'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.service', 'retrieval')
            ->assertJsonPath('error.reason', 'connection');

        $messages = $user->conversations()->first()->messages;

        expect($messages)->toHaveCount(1)
            ->and($messages->first()->role)->toBe('user')
            ->and($messages->first()->content)->toBe('Will this survive?');
    });

    it('returns 503 when the generation service fails', function (): void {
        bindRetrievalClient(fn (): array => grpcRetrievalSuccess());
        bindGenerationClient(function (): array {
            throw AiServiceException::invalidResponse('generation', 'gRPC status 13: model not loaded');
        });

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Hello'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.service', 'generation')
            ->assertJsonPath('error.reason', 'invalid_response');

        expect($user->conversations()->first()->messages)->toHaveCount(1);
    });
});
