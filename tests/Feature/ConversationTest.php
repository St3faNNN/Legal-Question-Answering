<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

describe('conversations', function (): void {
    it('includes display_title but not messages in the index payload', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => null]);
        Message::factory()->for($conversation)->create([
            'role' => 'user',
            'content' => 'What is a knowledge graph?',
        ]);

        actingAs($user);

        getJson('/api/v1/conversations')
            ->assertSuccessful()
            ->assertJsonPath('data.0.display_title', 'What is a knowledge graph?')
            ->assertJsonMissingPath('data.0.messages');
    });

    it('does not run a query per conversation for display_title in the index', function (): void {
        $user = User::factory()->create();
        Conversation::factory()->count(2)->for($user)->create(['title' => null])
            ->each(fn (Conversation $c) => Message::factory()->for($c)->create(['role' => 'user']));

        actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        getJson('/api/v1/conversations')->assertSuccessful();
        $fewCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $other = User::factory()->create();
        Conversation::factory()->count(6)->for($other)->create(['title' => null])
            ->each(fn (Conversation $c) => Message::factory()->for($c)->create(['role' => 'user']));

        actingAs($other);

        DB::flushQueryLog();
        DB::enableQueryLog();
        getJson('/api/v1/conversations')->assertSuccessful();
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Query count is constant regardless of how many conversations exist.
        expect($manyCount)->toBe($fewCount);
    });

    it('lists the authenticated user conversations', function (): void {
        $user = User::factory()->create();
        Conversation::factory()->count(3)->for($user)->create([
            'title' => 'My Conversation',
        ]);

        actingAs($user);

        $response = getJson('/api/v1/conversations')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'created_at', 'updated_at']],
                'meta' => ['next_cursor', 'per_page'],
            ]);

        expect($response->json('data'))->toHaveCount(3);
    });

    it('only lists conversations belonging to the authenticated user', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Conversation::factory()->count(2)->for($user)->create();
        Conversation::factory()->count(3)->for($other)->create();

        actingAs($user);

        $response = getJson('/api/v1/conversations');

        expect($response->json('data'))->toHaveCount(2);
    });

    it('creates a conversation with a title', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        $response = postJson('/api/v1/conversations', ['title' => 'My New Chat'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'My New Chat');
    });

    it('creates a conversation without a title', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/conversations', [])
            ->assertCreated()
            ->assertJsonPath('data.title', null);
    });

    it('shows a conversation with its messages', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => 'Test Chat']);
        Message::factory()->count(3)->for($conversation)->create();

        actingAs($user);

        $response = getJson("/api/v1/conversations/{$conversation->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.title', 'Test Chat');

        expect($response->json('data.messages'))->toHaveCount(3);
    });

    it('shows display_title when title is null', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => null]);
        Message::factory()->for($conversation)->create([
            'role' => 'user',
            'content' => 'This is a very long first message that exceeds the fifty character limit for truncation.',
        ]);

        actingAs($user);

        $response = getJson("/api/v1/conversations/{$conversation->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.title', null)
            ->assertJsonPath('data.display_title', 'This is a very long first message that exceeds the');
    });

    it('shows fallback display_title when no messages', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => null]);

        actingAs($user);

        $response = getJson("/api/v1/conversations/{$conversation->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.display_title', "Conversation {$conversation->id}");
    });

    it('renames a conversation', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => 'Old']);

        actingAs($user);

        patchJson("/api/v1/conversations/{$conversation->id}", ['title' => 'New Title'])
            ->assertSuccessful()
            ->assertJsonPath('data.title', 'New Title');

        expect($conversation->fresh()->title)->toBe('New Title');
    });

    it('deletes a conversation (soft delete)', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        actingAs($user);

        deleteJson("/api/v1/conversations/{$conversation->id}")->assertNoContent();

        expect(Conversation::find($conversation->id))->toBeNull()
            ->and(Conversation::withTrashed()->find($conversation->id))->not->toBeNull();
    });

    it('prevents viewing another user conversation', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = Conversation::factory()->for($owner)->create();

        actingAs($intruder);

        getJson("/api/v1/conversations/{$conversation->id}")->assertNotFound();
    });

    it('prevents updating another user conversation', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = Conversation::factory()->for($owner)->create();

        actingAs($intruder);

        patchJson("/api/v1/conversations/{$conversation->id}", ['title' => 'Hacked'])
            ->assertNotFound();

        expect($conversation->fresh()->title)->not->toBe('Hacked');
    });

    it('prevents deleting another user conversation', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $conversation = Conversation::factory()->for($owner)->create();

        actingAs($intruder);

        deleteJson("/api/v1/conversations/{$conversation->id}")->assertNotFound();

        expect($conversation->fresh())->not->toBeNull();
    });

    it('requires authentication', function (): void {
        getJson('/api/v1/conversations')->assertUnauthorized();
        postJson('/api/v1/conversations', [])->assertUnauthorized();
        getJson('/api/v1/conversations/1')->assertUnauthorized();
    });

    it('validates title length on update', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        actingAs($user);

        patchJson("/api/v1/conversations/{$conversation->id}", ['title' => ''])
            ->assertJsonValidationErrorFor('title');
    });
});
