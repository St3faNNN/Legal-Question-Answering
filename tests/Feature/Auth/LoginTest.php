<?php

use App\Models\User;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\postJson;

describe('login', function (): void {
    it('logs in a user with valid credentials', function (): void {
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response = postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'password123',
        ], ['Referer' => config('app.url')]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'email'],
            ]);

        assertAuthenticated();
        expect($response->json('data.id'))->toBe($user->id);
    });

    it('fails with incorrect credentials', function (): void {
        User::factory()->create([
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
            'password' => 'wrong-password',
        ], ['Referer' => config('app.url')])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');

        assertGuest();
    });

    it('fails with non-existent email', function (): void {
        postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ], ['Referer' => config('app.url')])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    });

    it('requires an email', function (): void {
        postJson('/api/v1/auth/login', [
            'password' => 'password123',
        ])->assertJsonValidationErrorFor('email');
    });

    it('requires a password', function (): void {
        postJson('/api/v1/auth/login', [
            'email' => 'john@example.com',
        ])->assertJsonValidationErrorFor('password');
    });
});
