<?php

use App\Models\Invitation;
use App\Models\TermsAcceptance;
use App\Models\User;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\postJson;

describe('registration', function (): void {
    it('registers a new user with a valid invitation and authenticates them', function (): void {
        $invitation = Invitation::factory()->create();

        $response = postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'email', 'bio', 'created_at', 'updated_at', 'last_active_at'],
            ]);

        assertAuthenticated();
        expect($response->json('data.email'))->toBe('john@example.com');

        // The single-use code is consumed and linked to the new account.
        $invitation->refresh();
        expect($invitation->used_at)->not->toBeNull()
            ->and($invitation->used_by_user_id)->toBe($response->json('data.id'));
    });

    it('regenerates the session id on registration to prevent fixation', function (): void {
        $invitation = Invitation::factory()->create();
        $this->withSession(['seeded' => true]);
        $originalId = session()->getId();

        postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertCreated();

        expect(session()->getId())->not->toBe($originalId);
        assertAuthenticated();
    });

    it('throttles registration after 5 attempts per minute', function (): void {
        $codes = Invitation::factory()->count(6)->create();

        foreach (range(1, 5) as $i) {
            postJson('/api/v1/auth/register', [
                'name' => 'User '.$i,
                'email' => "user{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'invite_code' => $codes[$i - 1]->code,
                'accept_terms' => true,
            ], ['Referer' => config('app.url')])->assertCreated();
        }

        postJson('/api/v1/auth/register', [
            'name' => 'Too Many',
            'email' => 'toomany@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $codes[5]->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertStatus(429);
    });

    it('requires a name', function (): void {
        postJson('/api/v1/auth/register', [
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertJsonValidationErrorFor('name');
    });

    it('requires a valid email', function (): void {
        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertJsonValidationErrorFor('email');
    });

    it('requires a unique email', function (): void {
        User::factory()->create(['email' => 'taken@example.com']);

        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertJsonValidationErrorFor('email');
    });

    it('requires password confirmation', function (): void {
        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'mismatch',
        ])->assertJsonValidationErrorFor('password');
    });

    it('requires a minimum password length of 8', function (): void {
        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertJsonValidationErrorFor('password');
    });
});

describe('invitation gate', function (): void {
    it('requires an invitation code', function (): void {
        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('invite_code');

        assertDatabaseMissing('users', ['email' => 'john@example.com']);
    });

    it('rejects an unknown invitation code', function (): void {
        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => 'DOES-NOT-EXIST',
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('invite_code');

        assertDatabaseMissing('users', ['email' => 'john@example.com']);
    });

    it('rejects an already-used invitation code', function (): void {
        $invitation = Invitation::factory()->used()->create();

        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('invite_code');

        assertDatabaseMissing('users', ['email' => 'john@example.com']);
    });

    it('rejects an expired invitation code', function (): void {
        $invitation = Invitation::factory()->expired()->create();

        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('invite_code');

        assertDatabaseMissing('users', ['email' => 'john@example.com']);
    });

    it('rejects a code bound to a different email', function (): void {
        $invitation = Invitation::factory()->forEmail('invited@example.com')->create();

        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'someone.else@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('invite_code');

        assertDatabaseMissing('users', ['email' => 'someone.else@example.com']);
    });

    it('accepts a code bound to the matching email', function (): void {
        $invitation = Invitation::factory()->forEmail('invited@example.com')->create();

        postJson('/api/v1/auth/register', [
            'name' => 'Invited',
            'email' => 'invited@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertCreated();

        expect($invitation->refresh()->used_at)->not->toBeNull();
    });

    it('does not consume the code when other validation fails', function (): void {
        $invitation = Invitation::factory()->create();

        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'mismatch',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('password');

        // Request validation fails before the service runs, so the code stays
        // available for a corrected retry.
        expect($invitation->refresh()->used_at)->toBeNull();
    });

    it('mints a single-use code via the console command', function (): void {
        $this->artisan('invitations:create', ['--note' => 'for testing'])
            ->assertSuccessful();

        expect(Invitation::where('note', 'for testing')->count())->toBe(1);
    });
});

describe('terms acceptance', function (): void {
    it('requires explicit acceptance of the terms', function (): void {
        $invitation = Invitation::factory()->create();

        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            // accept_terms omitted
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('accept_terms');

        assertDatabaseMissing('users', ['email' => 'john@example.com']);
        expect($invitation->refresh()->used_at)->toBeNull();
    });

    it('rejects registration when acceptance is false', function (): void {
        $invitation = Invitation::factory()->create();

        postJson('/api/v1/auth/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => false,
        ], ['Referer' => config('app.url')])->assertJsonValidationErrorFor('accept_terms');

        assertDatabaseMissing('users', ['email' => 'john@example.com']);
    });

    it('records acceptance of each required document with its version and a timestamp', function (): void {
        $invitation = Invitation::factory()->create();

        $response = postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite_code' => $invitation->code,
            'accept_terms' => true,
        ], ['Referer' => config('app.url')])->assertCreated();

        $userId = $response->json('data.id');

        foreach (config('legal.acceptance_required') as $document) {
            $acceptance = TermsAcceptance::where('user_id', $userId)
                ->where('document', $document)
                ->first();

            expect($acceptance)->not->toBeNull()
                ->and($acceptance->version)->toBe(config("legal.documents.{$document}.version"))
                ->and($acceptance->accepted_at)->not->toBeNull();
        }
    });
});
