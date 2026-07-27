<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

describe('api tokens', function (): void {
    it('creates a personal access token', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        $response = postJson('/api/v1/tokens', ['name' => 'My App'])
            ->assertCreated()
            ->assertJsonStructure(['token', 'name']);

        expect($response->json('token'))->toBeString()
            ->and($response->json('name'))->toBe('My App');
    });

    it('lists the user tokens', function (): void {
        $user = User::factory()->create();
        $user->createToken('Token One');
        $user->createToken('Token Two');

        actingAs($user);

        getJson('/api/v1/tokens')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [['id', 'name']],
                'meta' => ['current_page'],
            ])
            ->assertJsonCount(2, 'data');
    });

    it('lists the user tokens with pagination', function (): void {
        $user = User::factory()->create();
        for ($i = 0; $i < 16; $i++) {
            $user->createToken("Token {$i}");
        }

        actingAs($user);

        getJson('/api/v1/tokens')
            ->assertSuccessful()
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 16);
    });

    it('deletes a token belonging to the user', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('Doomed');

        actingAs($user);

        deleteJson('/api/v1/tokens/'.$token->accessToken->id)
            ->assertNoContent();

        expect($user->tokens()->count())->toBe(0);
    });

    it('does not delete a token from another user', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $token = $owner->createToken('Owner Token');

        actingAs($intruder);

        deleteJson('/api/v1/tokens/'.$token->accessToken->id)->assertNotFound();

        expect($owner->fresh()->tokens()->count())->toBe(1);
    });

    it('requires a token name', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/tokens', [])->assertJsonValidationErrorFor('name');
    });

    it('validates abilities as an array', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/tokens', [
            'name' => 'My App',
            'abilities' => 'not-an-array',
        ])->assertJsonValidationErrorFor('abilities');
    });

    it('requires authentication', function (): void {
        postJson('/api/v1/tokens', ['name' => 'My App'])->assertUnauthorized();
    });
});
