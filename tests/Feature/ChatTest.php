<?php

use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Jobs\StreamChatReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\NonLegalInputGate;
use App\Services\Logging\AiLogger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * The chat request surface: conversation resolution, message persistence,
 * validation and ownership.
 *
 * There is one send endpoint now. POST /chat — which generated the reply inline
 * and returned it in the response — is gone; the browser has used POST
 * /chat/stream since the SSE relay landed, so the unary endpoint was a second
 * orchestration of the same work that only tests exercised. These cases moved to
 * the streaming endpoint rather than being deleted with it: what they assert
 * (the transaction rolls back, a foreign conversation 404s, an empty message is
 * rejected) is a property of the request, not of how the reply is delivered.
 */
function fakeStreamDispatch(): void
{
    Queue::fake();
    Redis::shouldReceive('setex')->andReturnTrue();
}

describe('chat', function (): void {
    it('rolls back the new conversation when persisting the user message fails', function (): void {
        fakeStreamDispatch();

        $user = User::factory()->create();
        actingAs($user);

        // Force the user-message insert to fail inside the transaction.
        Event::listen('eloquent.creating: '.Message::class, function (): void {
            throw new RuntimeException('insert failed');
        });

        postJson('/api/v1/chat/stream', ['message' => 'Hello'])->assertStatus(500);

        // The conversation created in the same transaction must not survive.
        expect($user->conversations()->count())->toBe(0);
    });

    it('has no placeholder path left that fabricates an answer', function (): void {
        // The regression this guards: with the driver branch gone, a run that
        // reaches generation without a working backend must FAIL, not invent a
        // reply. The suite's inert clients throw, standing in for an outage.
        //
        // Asserted against the job rather than the request, because that is where
        // generation happens now — a fabricated answer would be persisted there
        // and streamed to the browser, with the HTTP request long since returned.
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        Redis::shouldReceive('rpush')->andReturn(1);
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        $run = fn () => (new StreamChatReply($conversation->id, 'stream-inert', 'Прашање?', microtime(true)))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        );

        expect($run)->toThrow(RuntimeException::class);

        // Nothing was written as an assistant answer.
        expect($conversation->messages()->where('role', 'assistant')->count())->toBe(0);
    });

    it('persists the user message before the reply is generated', function (): void {
        // The user's message is committed by the request; the assistant's arrives
        // later from the worker. So a dispatched-but-unrun stream leaves exactly
        // one message — which is also what makes a failed generation survivable.
        fakeStreamDispatch();

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', ['message' => 'Tell me about GraphRAG.'])->assertStatus(202);

        $conversation = $user->conversations()->first();

        expect($conversation->messages)->toHaveCount(1)
            ->and($conversation->messages->first()->role)->toBe('user')
            ->and($conversation->messages->first()->content)->toBe('Tell me about GraphRAG.');
    });

    it('continues an existing conversation', function (): void {
        fakeStreamDispatch();

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

        $response = postJson('/api/v1/chat/stream', [
            'message' => 'Second message',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202);

        expect($response->json('conversation.id'))->toBe($conversation->id)
            ->and($conversation->fresh()->messages)->toHaveCount(3);
    });

    it('creates a new conversation when conversation_id is omitted', function (): void {
        fakeStreamDispatch();

        $user = User::factory()->create();
        actingAs($user);

        expect($user->conversations()->count())->toBe(0);

        postJson('/api/v1/chat/stream', ['message' => 'New start'])->assertStatus(202);

        expect($user->fresh()->conversations()->count())->toBe(1);
    });

    it('returns 404 for another user conversation', function (): void {
        fakeStreamDispatch();

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = Conversation::factory()->for($owner)->create();

        actingAs($intruder);

        postJson('/api/v1/chat/stream', [
            'message' => 'Intrusion attempt',
            'conversation_id' => $conversation->id,
        ])->assertNotFound();
    });

    it('validates message is required', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', [])->assertJsonValidationErrorFor('message');
    });

    it('validates message is not empty', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', ['message' => ''])->assertJsonValidationErrorFor('message');
    });

    it('validates conversation_id exists', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', [
            'message' => 'Hello',
            'conversation_id' => 99999,
        ])->assertJsonValidationErrorFor('conversation_id');
    });

    it('requires authentication', function (): void {
        postJson('/api/v1/chat/stream', ['message' => 'Hello'])->assertUnauthorized();
    });

    it('no longer exposes the non-streaming send endpoint', function (): void {
        // Deleted, not merely unused by the SPA: a second orchestration of the
        // same work is a second place for the panel/refusal invariants to drift.
        //
        // 405 rather than 404 because the SPA's GET catch-all in routes/web.php
        // matches the path — no POST route exists, which is the point. What
        // matters is that nothing is persisted and no reply comes back.
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat', ['message' => 'Hello'])->assertStatus(405);

        expect($user->conversations()->count())->toBe(0);
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
