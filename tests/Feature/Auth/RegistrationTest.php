<?php

use App\Models\User;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\postJson;

describe('registration', function (): void {
    it('registers a new user and authenticates them', function (): void {
        $response = postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], ['Referer' => config('app.url')]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'email', 'bio', 'created_at', 'updated_at', 'last_active_at'],
            ]);

        assertAuthenticated();
        expect($response->json('data.email'))->toBe('john@example.com');
    });

    it('regenerates the session id on registration to prevent fixation', function (): void {
        $this->withSession(['seeded' => true]);
        $originalId = session()->getId();

        postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], ['Referer' => config('app.url')])->assertCreated();

        expect(session()->getId())->not->toBe($originalId);
        assertAuthenticated();
    });

    it('throttles registration after 5 attempts per minute', function (): void {
        foreach (range(1, 5) as $i) {
            postJson('/api/v1/auth/register', [
                'name' => 'User '.$i,
                'email' => "user{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ], ['Referer' => config('app.url')])->assertCreated();
        }

        postJson('/api/v1/auth/register', [
            'name' => 'Too Many',
            'email' => 'toomany@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
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
