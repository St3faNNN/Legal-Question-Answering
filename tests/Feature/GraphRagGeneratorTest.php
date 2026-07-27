<?php

use App\Contracts\GeneratesReplies;
use App\Models\User;
use App\Services\Chat\GraphRagReplyGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    config([
        'ai.generator' => 'http',
        'ai.endpoints.retrieval' => 'http://retrieval.test',
        'ai.endpoints.generation' => 'http://generation.test',
        'ai.timeout' => 5,
    ]);
});

function fakeRetrievalSuccess(): array
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

function fakeGenerationSuccess(): array
{
    return [
        'answer' => 'This physicist developed the theory of relativity.',
        'model' => 'bart-jeopardy-final',
        'tokens' => 14,
        'latency_ms' => 850,
    ];
}

describe('GraphRAG generator binding', function (): void {
    it('resolves GraphRagReplyGenerator when the driver is http', function (): void {
        expect(app(GeneratesReplies::class))->toBeInstanceOf(GraphRagReplyGenerator::class);
    });
});

describe('GraphRAG chat flow', function (): void {
    it('returns the generated reply and stores retrieval metadata', function (): void {
        Http::fake([
            'retrieval.test/*' => Http::response(fakeRetrievalSuccess()),
            'generation.test/*' => Http::response(fakeGenerationSuccess()),
        ]);

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

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'retrieval.test/retrieve')
            && $request['question'] === 'Who is Albert Einstein?');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'generation.test/generate')
            && $request['question'] === 'Who is Albert Einstein?'
            && $request['context'] === 'albert einstein developed theory of relativity | albert einstein born_in ulm'
            && $request['entity'] === 'albert einstein'
            && $request['history'] === []);
    });

    it('never sends conversation history to the generator (avoids answer copying)', function (): void {
        Http::fake([
            'retrieval.test/*' => Http::response(fakeRetrievalSuccess()),
            'generation.test/*' => Http::response(fakeGenerationSuccess()),
        ]);

        $user = User::factory()->create();
        actingAs($user);

        $conversationId = postJson('/api/v1/chat', ['message' => 'Who is Albert Einstein?'])
            ->assertCreated()
            ->json('conversation.id');

        postJson('/api/v1/chat', [
            'message' => 'Where was he born?',
            'conversation_id' => $conversationId,
        ])->assertCreated();

        // Even on a follow-up, history is empty so the model answers only from
        // the current retrieved context.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'generation.test/generate')
            && $request['question'] === 'Where was he born?'
            && $request['history'] === []);
    });

    it('still generates a reply when retrieval finds no context', function (): void {
        Http::fake([
            'retrieval.test/*' => Http::response([
                'context' => '',
                'entities' => [],
                'triples' => [],
                'latency_ms' => 3,
            ]),
            'generation.test/*' => Http::response(fakeGenerationSuccess()),
        ]);

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Something unknown to the graph'])
            ->assertCreated();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'generation.test/generate')
            && $request['context'] === ''
            && $request['entity'] === null);

        $assistantMessage = $user->conversations()->first()->messages()->where('role', 'assistant')->first();

        expect($assistantMessage->metadata['triple_count'])->toBe(0)
            ->and($assistantMessage->metadata['entities'])->toBe([])
            ->and($assistantMessage->metadata['top_triples'])->toBe([]);
    });

    it('caps top_triples at the five highest-ranked facts', function (): void {
        $triples = collect(range(1, 8))->map(fn (int $i): array => [
            'head' => 'albert einstein',
            'relation' => "relation_{$i}",
            'tail' => "tail_{$i}",
            'confidence' => 1 - ($i / 100),
        ])->all();

        Http::fake([
            'retrieval.test/*' => Http::response([
                'context' => 'some context',
                'entities' => ['albert einstein'],
                'triples' => $triples,
                'latency_ms' => 8,
            ]),
            'generation.test/*' => Http::response(fakeGenerationSuccess()),
        ]);

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Tell me everything'])
            ->assertCreated();

        $assistantMessage = $user->conversations()->first()->messages()->where('role', 'assistant')->first();

        expect($assistantMessage->metadata['triple_count'])->toBe(8)
            ->and($assistantMessage->metadata['top_triples'])->toHaveCount(5)
            ->and($assistantMessage->metadata['top_triples'][0]['relation'])->toBe('relation_1')
            ->and($assistantMessage->metadata['top_triples'][4]['relation'])->toBe('relation_5');
    });

    it('returns 503 and keeps the user message when retrieval times out', function (): void {
        Http::fake([
            'retrieval.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

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

    it('returns 503 when the retrieval service responds with an error status', function (): void {
        Http::fake([
            'retrieval.test/*' => Http::response(['detail' => 'graph not loaded'], 500),
        ]);

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Hello'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.service', 'retrieval')
            ->assertJsonPath('error.reason', 'http_error');

        expect($user->conversations()->first()->messages)->toHaveCount(1);
    });

    it('returns 503 when the generation service responds with an error status', function (): void {
        Http::fake([
            'retrieval.test/*' => Http::response(fakeRetrievalSuccess()),
            'generation.test/*' => Http::response(['detail' => 'model not loaded'], 503),
        ]);

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Hello'])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.service', 'generation')
            ->assertJsonPath('error.reason', 'http_error');

        expect($user->conversations()->first()->messages)->toHaveCount(1);
    });
});
