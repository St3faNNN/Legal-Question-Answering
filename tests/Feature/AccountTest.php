<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;

describe('data export', function (): void {
    it('exports the authenticated user data as a JSON download', function (): void {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $conversation = Conversation::factory()->for($user)->create(['title' => 'My chat']);
        Message::factory()->for($conversation)->create(['role' => 'user', 'content' => 'Hello?']);
        $user->termsAcceptances()->create([
            'document' => 'terms',
            'version' => '1.0-draft',
            'accepted_at' => now(),
        ]);

        $response = actingAs($user)->getJson('/api/v1/account/export');

        $response->assertOk()
            ->assertJsonPath('account.email', 'ana@example.com')
            ->assertJsonPath('conversations.0.title', 'My chat')
            ->assertJsonPath('conversations.0.messages.0.content', 'Hello?')
            ->assertJsonPath('terms_acceptances.0.document', 'terms');

        expect($response->headers->get('content-disposition'))
            ->toContain('attachment')
            ->toContain('.json');
    });

    it('includes soft-deleted conversations in the export', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create(['title' => 'Archived']);
        $conversation->delete();

        $response = actingAs($user)->getJson('/api/v1/account/export');

        $response->assertOk()->assertJsonPath('conversations.0.title', 'Archived');
        expect($response->json('conversations.0.deleted_at'))->not->toBeNull();
    });

    it('does not include another user data in the export', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Conversation::factory()->for($other)->create(['title' => 'Someone elses secret']);

        $response = actingAs($user)->getJson('/api/v1/account/export');

        $response->assertOk();
        expect($response->json('conversations'))->toBe([]);
        expect(json_encode($response->json()))->not->toContain('secret');
    });

    it('requires authentication to export', function (): void {
        getJson('/api/v1/account/export')->assertUnauthorized();
    });
});

describe('account deletion', function (): void {
    it('permanently deletes the account and all associated data', function (): void {
        $user = User::factory()->create(['password' => 'password123']);
        $conversation = Conversation::factory()->for($user)->create();
        $message = Message::factory()->for($conversation)->create();
        $acceptance = $user->termsAcceptances()->create([
            'document' => 'terms',
            'version' => '1.0-draft',
            'accepted_at' => now(),
        ]);

        actingAs($user)->deleteJson('/api/v1/account', ['password' => 'password123'])
            ->assertNoContent();

        assertDatabaseMissing('users', ['id' => $user->id]);
        assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        assertDatabaseMissing('messages', ['id' => $message->id]);
        assertDatabaseMissing('terms_acceptances', ['id' => $acceptance->id]);
    });

    it('hard-deletes even soft-deleted conversations and their messages', function (): void {
        $user = User::factory()->create(['password' => 'password123']);
        $conversation = Conversation::factory()->for($user)->create();
        $message = Message::factory()->for($conversation)->create();
        $conversation->delete(); // soft delete

        actingAs($user)->deleteJson('/api/v1/account', ['password' => 'password123'])
            ->assertNoContent();

        // withTrashed would still see a soft-deleted row — assert it is truly gone.
        expect(Conversation::withTrashed()->find($conversation->id))->toBeNull();
        assertDatabaseMissing('messages', ['id' => $message->id]);
    });

    it('rejects deletion with an incorrect password', function (): void {
        $user = User::factory()->create(['password' => 'password123']);

        actingAs($user)->deleteJson('/api/v1/account', ['password' => 'wrong-password'])
            ->assertJsonValidationErrorFor('password');

        assertDatabaseHas('users', ['id' => $user->id]);
    });

    it('requires a password to delete', function (): void {
        $user = User::factory()->create();

        actingAs($user)->deleteJson('/api/v1/account', [])
            ->assertJsonValidationErrorFor('password');

        assertDatabaseHas('users', ['id' => $user->id]);
    });

    it('requires authentication to delete', function (): void {
        deleteJson('/api/v1/account', ['password' => 'x'])->assertUnauthorized();
    });
});
