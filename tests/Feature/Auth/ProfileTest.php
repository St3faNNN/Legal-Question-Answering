<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

describe('profile', function (): void {
    it('shows the current authenticated user', function (): void {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        actingAs($user);

        getJson('/api/v1/user')
            ->assertSuccessful()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com');
    });

    it('requires authentication', function (): void {
        getJson('/api/v1/user')->assertUnauthorized();
    });

    it('updates the user name', function (): void {
        $user = User::factory()->create(['name' => 'Old Name']);
        actingAs($user);

        patchJson('/api/v1/profile', ['name' => 'New Name'])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'New Name');

        expect($user->fresh()->name)->toBe('New Name');
    });

    it('updates the user bio', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        patchJson('/api/v1/profile', ['bio' => 'I love building things.'])
            ->assertSuccessful()
            ->assertJsonPath('data.bio', 'I love building things.');
    });

    it('validates name length', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        patchJson('/api/v1/profile', ['name' => ''])
            ->assertJsonValidationErrorFor('name');
    });

    it('validates bio max length', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        patchJson('/api/v1/profile', ['bio' => str_repeat('x', 2001)])
            ->assertJsonValidationErrorFor('bio');
    });
});
