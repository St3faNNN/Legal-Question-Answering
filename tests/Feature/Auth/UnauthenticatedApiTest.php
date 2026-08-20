<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

describe('unauthenticated api requests', function (): void {
    // A bare probe or an expired browser session hits an auth-guarded API route
    // without an explicit JSON Accept header. This must resolve to a 401, never a
    // redirect to a (non-existent) login route — which previously raised a 500.
    it('returns a JSON 401, not a redirect or a 500, without a JSON Accept header', function (): void {
        $response = get('/api/v1/user');

        $response->assertUnauthorized(); // 401
        $response->assertHeader('content-type', 'application/json');
        expect($response->getContent())->toContain('Unauthenticated');
    });

    it('does not attempt an HTTP redirect for a guest API request', function (): void {
        $response = get('/api/v1/user', ['Accept' => '*/*']);

        expect($response->isRedirection())->toBeFalse();
        expect($response->getStatusCode())->toBe(401);
    });

    it('returns a JSON 401 when the client already asks for JSON', function (): void {
        getJson('/api/v1/user')->assertUnauthorized();
    });
});
