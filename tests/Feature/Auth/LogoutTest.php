<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

describe('logout', function (): void {
    it('logs out an authenticated user', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/auth/logout', [], ['Referer' => config('app.url')])
            ->assertNoContent();
    });

    it('requires authentication', function (): void {
        postJson('/api/v1/auth/logout')->assertUnauthorized();
    });
});
