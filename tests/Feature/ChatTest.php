<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

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

    it('creates a conversation and returns a placeholder reply', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        $response = postJson('/api/v1/chat', ['message' => 'Hello, can you help me?'])
            ->assertCreated()
            ->assertJsonStructure([
                'conversation' => ['id', 'title', 'created_at', 'updated_at'],
                'message' => ['id', 'conversation_id', 'role', 'content', 'model', 'tokens', 'latency_ms', 'metadata'],
            ]);

        expect($response->json('message.role'))->toBe('assistant')
            ->and($response->json('message.content'))->toBe('AI service not connected yet.')
            ->and($response->json('message.model'))->toBeNull();
    });

    it('persists the user message and assistant reply', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Tell me about GraphRAG.']);

        $conversation = $user->conversations()->first();

        expect($conversation->messages)->toHaveCount(2)
            ->and($conversation->messages->first()->role)->toBe('user')
            ->and($conversation->messages->first()->content)->toBe('Tell me about GraphRAG.')
            ->and($conversation->messages->last()->role)->toBe('assistant')
            ->and($conversation->messages->last()->content)->toBe('AI service not connected yet.');
    });

    it('continues an existing conversation', function (): void {
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
