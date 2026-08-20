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

/**
 * A hybrid retrieval result: reranked passages from the vector store plus the
 * cross-references the citation graph found between them.
 */
function grpcRetrievalSuccess(): array
{
    return [
        'context' => "[ZAKON-A, чл. 1]\nРаботно време и распоред\n\n[ZAKON-A, чл. 3]\nПлата и надоместоци",
        'entities' => ['ZAKON-A, чл. 1', 'ZAKON-A, чл. 3'],
        'triples' => [
            ['head' => 'ZAKON-A, чл. 1', 'relation' => 'упатува на', 'tail' => 'ZAKON-A, чл. 3', 'confidence' => 0.75],
        ],
        'chunks' => [
            ['id' => '1', 'text' => 'Работно време и распоред', 'source' => 'ZAKON-A, чл. 1', 'score' => 7.5],
            ['id' => '3', 'text' => 'Плата и надоместоци', 'source' => 'ZAKON-A, чл. 3', 'score' => 4.25],
        ],
        'context_items' => 2,
        'latency_ms' => 12,
    ];
}

function grpcGenerationSuccess(): array
{
    return [
        'answer' => 'Работното време е уредено во [ZAKON-A, чл. 1].',
        'model' => 'qwen2.5-1.5b-instruct',
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

        $response = postJson('/api/v1/chat', ['message' => 'Колку е работното време?'])
            ->assertCreated();

        expect($response->json('message.role'))->toBe('assistant')
            ->and($response->json('message.content'))->toBe('Работното време е уредено во [ZAKON-A, чл. 1].')
            ->and($response->json('message.model'))->toBe('qwen2.5-1.5b-instruct')
            ->and($response->json('message.tokens'))->toBe(14);

        $assistantMessage = $user->conversations()->first()->messages()->where('role', 'assistant')->first();

        expect($assistantMessage->metadata['entities'])->toBe(['ZAKON-A, чл. 1', 'ZAKON-A, чл. 3'])
            ->and($assistantMessage->metadata['triple_count'])->toBe(1)
            ->and($assistantMessage->metadata['top_triples'])->toBe([
                ['head' => 'ZAKON-A, чл. 1', 'relation' => 'упатува на', 'tail' => 'ZAKON-A, чл. 3', 'confidence' => 0.75],
            ])
            ->and($assistantMessage->metadata['context'])->toContain('Работно време и распоред')
            ->and($assistantMessage->metadata['retrieval_latency_ms'])->toBe(12)
            ->and($assistantMessage->metadata['generation_latency_ms'])->toBe(850);
    });

    it('stores the reranked passages as citations', function (): void {
        bindRetrievalClient(fn (): array => grpcRetrievalSuccess());
        bindGenerationClient(fn (): array => grpcGenerationSuccess());

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Колку е работното време?'])->assertCreated();

        $metadata = $user->conversations()->first()->messages()->where('role', 'assistant')->first()->metadata;

        expect($metadata['chunk_count'])->toBe(2)
            ->and($metadata['context_items'])->toBe(2)
            ->and($metadata['top_chunks'])->toBe([
                ['id' => '1', 'text' => 'Работно време и распоред', 'source' => 'ZAKON-A, чл. 1', 'score' => 7.5],
                ['id' => '3', 'text' => 'Плата и надоместоци', 'source' => 'ZAKON-A, чл. 3', 'score' => 4.25],
            ]);
    });

    it('caps top_chunks at the five highest-ranked passages', function (): void {
        bindRetrievalClient(fn (): array => [
            ...grpcRetrievalSuccess(),
            'chunks' => collect(range(1, 8))
                ->map(fn (int $i): array => [
                    'id' => (string) $i,
                    'text' => "текст {$i}",
                    'source' => "ZAKON-A, чл. {$i}",
                    'score' => 10.0 - $i,
                ])
                ->all(),
        ]);
        bindGenerationClient(fn (): array => grpcGenerationSuccess());

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Прашање?'])->assertCreated();

        $metadata = $user->conversations()->first()->messages()->where('role', 'assistant')->first()->metadata;

        // All eight were retrieved and reranked; only the leading five are
        // surfaced as citations.
        expect($metadata['chunk_count'])->toBe(8)
            ->and($metadata['top_chunks'])->toHaveCount(5)
            ->and(array_column($metadata['top_chunks'], 'id'))->toBe(['1', '2', '3', '4', '5']);
    });

    it('truncates long passage text in the citation trail', function (): void {
        bindRetrievalClient(fn (): array => [
            ...grpcRetrievalSuccess(),
            'chunks' => [['id' => '1', 'text' => str_repeat('а', 2000), 'source' => 'ZAKON-A, чл. 1', 'score' => 1.0]],
        ]);
        bindGenerationClient(fn (): array => grpcGenerationSuccess());

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Прашање?'])->assertCreated();

        $metadata = $user->conversations()->first()->messages()->where('role', 'assistant')->first()->metadata;

        // The metadata is a citation trail, not a second copy of the corpus.
        expect(mb_strlen($metadata['top_chunks'][0]['text']))->toBeLessThan(2000);
    });

    it('handles a retrieval response that carries no chunks', function (): void {
        // Older retrieval builds — and the HTTP driver's fakes — return only
        // triples; the payload must still assemble.
        bindRetrievalClient(fn (): array => [
            'context' => 'некој контекст',
            'entities' => ['ZAKON-A, чл. 1'],
            'triples' => [],
            'latency_ms' => 5,
        ]);
        bindGenerationClient(fn (): array => grpcGenerationSuccess());

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Прашање?'])->assertCreated();

        $metadata = $user->conversations()->first()->messages()->where('role', 'assistant')->first()->metadata;

        expect($metadata['chunk_count'])->toBe(0)
            ->and($metadata['top_chunks'])->toBe([])
            ->and($metadata['context_items'])->toBeNull();
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

        postJson('/api/v1/chat', ['message' => 'Колку е работното време?'])->assertCreated();

        expect($captured['question'])->toBe('Колку е работното време?')
            ->and($captured['context'])->toBe("[ZAKON-A, чл. 1]\nРаботно време и распоред\n\n[ZAKON-A, чл. 3]\nПлата и надоместоци")
            ->and($captured['entity'])->toBe('ZAKON-A, чл. 1')
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

        $conversationId = postJson('/api/v1/chat', ['message' => 'Што е јавна набавка?'])
            ->assertCreated()
            ->json('conversation.id');

        postJson('/api/v1/chat', [
            'message' => 'А кој ја спроведува?',
            'conversation_id' => $conversationId,
        ])->assertCreated();

        // Even on a follow-up, history is empty: each question is answered from
        // its own retrieved context, so the model can't copy prior answers.
        expect($captured['question'])->toBe('А кој ја спроведува?')
            ->and($captured['history'])->toBe([]);
    });

    it('still generates a reply when retrieval finds no context', function (): void {
        $captured = [];
        bindRetrievalClient(fn (): array => [
            'context' => '', 'entities' => [], 'triples' => [], 'chunks' => [], 'context_items' => 0, 'latency_ms' => 3,
        ]);
        bindGenerationClient(function (string $question, string $context, ?string $entity, array $history) use (&$captured): array {
            $captured = compact('context', 'entity');

            return grpcGenerationSuccess();
        });

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Нешто што го нема во документите'])->assertCreated();

        expect($captured['context'])->toBe('')
            ->and($captured['entity'])->toBeNull();

        $assistantMessage = $user->conversations()->first()->messages()->where('role', 'assistant')->first();

        expect($assistantMessage->metadata['triple_count'])->toBe(0)
            ->and($assistantMessage->metadata['entities'])->toBe([])
            ->and($assistantMessage->metadata['top_triples'])->toBe([])
            ->and($assistantMessage->metadata['top_chunks'])->toBe([]);
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
