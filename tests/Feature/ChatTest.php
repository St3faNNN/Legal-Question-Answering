<?php

use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Script the AI layer for a test that actually sends a message.
 *
 * These used to run against the placeholder generator, which answered every
 * question with "AI service not connected yet." and no model. That driver is
 * gone: it was reachable in production by an unset AI_GENERATOR and wrote that
 * string into `messages` as a real assistant answer, so the chat tests now do
 * what every other AI test does and bind an explicit fake.
 */
function bindChatAi(): void
{
    app()->instance(RetrievalClient::class, new class implements RetrievalClient
    {
        public function retrieve(string $question): array
        {
            return [
                'context' => "[ZAKON-A, чл. 1]\nОдредба",
                'entities' => ['ZAKON-A, чл. 1'],
                'triples' => [],
                'chunks' => [['id' => '1', 'text' => 'Одредба', 'source' => 'ZAKON-A, чл. 1', 'score' => 1.0]],
                'context_items' => 1,
                'latency_ms' => 5,
            ];
        }

        public function references(string $law, string $article): array
        {
            return ['found' => false, 'source_citation' => '', 'references' => []];
        }
    });

    app()->instance(GenerationClient::class, new class implements GenerationClient
    {
        public function generate(string $question, string $context, ?string $entity, array $history): array
        {
            return ['answer' => 'Одговор од изворите.', 'model' => 'gemini-flash-lite-latest', 'tokens' => 12, 'latency_ms' => 20];
        }

        public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
        {
            return [];
        }
    });
}

describe('chat', function (): void {
    it('rolls back the new conversation when persisting the user message fails', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        // Force the user-message insert to fail inside the transaction.
        Event::listen('eloquent.creating: '.Message::class, function (): void {
            throw new RuntimeException('insert failed');
        });

        postJson('/api/v1/chat', ['message' => 'Hello'])->assertStatus(500);

        // The conversation created in the same transaction must not survive.
        expect($user->conversations()->count())->toBe(0);
    });

    it('creates a conversation and returns a generated reply', function (): void {
        bindChatAi();

        $user = User::factory()->create();
        actingAs($user);

        $response = postJson('/api/v1/chat', ['message' => 'Hello, can you help me?'])
            ->assertCreated()
            ->assertJsonStructure([
                'conversation' => ['id', 'title', 'created_at', 'updated_at'],
                'message' => ['id', 'conversation_id', 'role', 'content', 'model', 'tokens', 'latency_ms', 'metadata'],
            ]);

        expect($response->json('message.role'))->toBe('assistant')
            ->and($response->json('message.content'))->toBe('Одговор од изворите.')
            // A real model name, not the placeholder's null.
            ->and($response->json('message.model'))->toBe('gemini-flash-lite-latest');
    });

    it('has no placeholder path left that fabricates an answer', function (): void {
        // The regression this guards: with the driver branch gone, a request that
        // reaches generation without a working backend must FAIL, not invent a
        // reply. The suite's inert clients throw, standing in for an outage.
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Прашање?'])->assertStatus(500);

        // Nothing was written as an assistant answer.
        expect($user->conversations()->first()?->messages()->where('role', 'assistant')->count() ?? 0)->toBe(0);
    });

    it('persists the user message and assistant reply', function (): void {
        bindChatAi();

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Tell me about GraphRAG.']);

        $conversation = $user->conversations()->first();

        expect($conversation->messages)->toHaveCount(2)
            ->and($conversation->messages->first()->role)->toBe('user')
            ->and($conversation->messages->first()->content)->toBe('Tell me about GraphRAG.')
            ->and($conversation->messages->last()->role)->toBe('assistant')
            ->and($conversation->messages->last()->content)->toBe('Одговор од изворите.');
    });

    it('continues an existing conversation', function (): void {
        bindChatAi();

        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        Message::factory()->for($conversation)->create([
            'role' => 'user',
            'content' => 'First message',
        ]);
        Message::factory()->for($conversation)->create([
            'role' => 'assistant',
            'content' => 'First reply',
        ]);

        actingAs($user);

        $response = postJson('/api/v1/chat', [
            'message' => 'Second message',
            'conversation_id' => $conversation->id,
        ])->assertCreated();

        expect($response->json('conversation.id'))->toBe($conversation->id);

        expect($conversation->fresh()->messages)->toHaveCount(4);
    });

    it('creates a new conversation when conversation_id is omitted', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        expect($user->conversations()->count())->toBe(0);

        postJson('/api/v1/chat', ['message' => 'New start']);

        expect($user->fresh()->conversations()->count())->toBe(1);
    });

    it('returns 404 for another user conversation', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = Conversation::factory()->for($owner)->create();

        actingAs($intruder);

        postJson('/api/v1/chat', [
            'message' => 'Intrusion attempt',
            'conversation_id' => $conversation->id,
        ])->assertNotFound();
    });

    it('validates message is required', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', [])->assertJsonValidationErrorFor('message');
    });

    it('validates message is not empty', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => ''])->assertJsonValidationErrorFor('message');
    });

    it('validates conversation_id exists', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', [
            'message' => 'Hello',
            'conversation_id' => 99999,
        ])->assertJsonValidationErrorFor('conversation_id');
    });

    it('requires authentication', function (): void {
        postJson('/api/v1/chat', ['message' => 'Hello'])->assertUnauthorized();
    });

    it('lists messages for a conversation (cursor paginated)', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        Message::factory()->count(5)->for($conversation)->create();

        actingAs($user);

        $response = getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [['id', 'role', 'content', 'created_at']],
                'meta' => ['next_cursor', 'per_page'],
            ]);

        expect($response->json('data'))->toHaveCount(5);
    });

    it('prevents listing messages from another user conversation', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = Conversation::factory()->for($owner)->create();

        actingAs($intruder);

        getJson("/api/v1/conversations/{$conversation->id}/messages")->assertNotFound();
    });
});
