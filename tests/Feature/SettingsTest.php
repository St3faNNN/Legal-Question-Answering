<?php

use App\Models\User;
use App\Models\UserSetting;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

describe('settings', function (): void {
    it('shows default settings for a user without existing settings', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        getJson('/api/v1/settings')
            ->assertSuccessful()
            ->assertJsonPath('data.theme', 'system')
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.notifications', true)
            ->assertJsonPath('data.default_model', null);
    });

    it('shows existing settings', function (): void {
        $user = User::factory()->create();
        UserSetting::factory()->create([
            'user_id' => $user->id,
            'theme' => 'dark',
            'language' => 'es',
            'notifications' => false,
            'default_model' => 'bart-finetuned',
        ]);

        actingAs($user);

        getJson('/api/v1/settings')
            ->assertSuccessful()
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.language', 'es')
            ->assertJsonPath('data.notifications', false)
            ->assertJsonPath('data.default_model', 'bart-finetuned');
    });

    it('updates theme', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        putJson('/api/v1/settings', ['theme' => 'dark'])
            ->assertSuccessful()
            ->assertJsonPath('data.theme', 'dark');
    });

    it('updates language', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        putJson('/api/v1/settings', ['language' => 'fr'])
            ->assertSuccessful()
            ->assertJsonPath('data.language', 'fr');
    });

    it('updates notifications', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        putJson('/api/v1/settings', ['notifications' => false])
            ->assertSuccessful()
            ->assertJsonPath('data.notifications', false);
    });

    it('updates default_model with nullable', function (): void {
        $user = User::factory()->create();
        UserSetting::factory()->create([
            'user_id' => $user->id,
            'default_model' => 'old-model',
        ]);

        actingAs($user);

        putJson('/api/v1/settings', ['default_model' => null])
            ->assertSuccessful()
            ->assertJsonPath('data.default_model', null);
    });

    it('updates multiple fields at once', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        putJson('/api/v1/settings', [
            'theme' => 'light',
            'language' => 'de',
            'notifications' => false,
        ])
            ->assertSuccessful()
            ->assertJsonPath('data.theme', 'light')
            ->assertJsonPath('data.language', 'de')
            ->assertJsonPath('data.notifications', false);
    });

    it('validates theme value', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        putJson('/api/v1/settings', ['theme' => 'invalid'])
            ->assertJsonValidationErrorFor('theme');
    });

    it('validates language length', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        putJson('/api/v1/settings', ['language' => 'x'])
            ->assertJsonValidationErrorFor('language');
    });

    it('creates settings on first update', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        expect($user->settings)->toBeNull();

        putJson('/api/v1/settings', ['theme' => 'dark'])->assertSuccessful();

        expect($user->fresh()->settings)->not->toBeNull()
            ->and($user->fresh()->settings->theme)->toBe('dark');
    });

    it('requires authentication', function (): void {
        getJson('/api/v1/settings')->assertUnauthorized();
        putJson('/api/v1/settings', [])->assertUnauthorized();
    });
});
