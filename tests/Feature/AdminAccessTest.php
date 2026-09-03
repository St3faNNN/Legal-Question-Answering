<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * The gate on the operational dashboard.
 *
 * Every admin route is enumerated once here and asserted against three callers —
 * a guest, an ordinary authenticated user, and an allowlisted one — so a route
 * added later without a gate fails this file rather than shipping open. The
 * expected status for a non-allowlisted user is 404, not 403: a 403 would confirm
 * the panel exists.
 */
function adminRoutes(): array
{
    return [
        'metrics' => '/api/v1/admin/metrics',
        'exchanges' => '/api/v1/admin/exchanges',
        'exchange detail' => '/api/v1/admin/exchanges/1',
        'agent trace' => '/api/v1/admin/agent-runs/1/trace',
    ];
}

it('registers no admin route outside the gated group', function (): void {
    // The guard against the failure this whole file exists to prevent: someone
    // adds an /admin endpoint outside the middleware group.
    $ungated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin'))
        ->reject(fn ($route): bool => in_array(EnsureUserIsAdmin::class, $route->gatherMiddleware(), true))
        ->map(fn ($route): string => $route->uri())
        ->values()
        ->all();

    expect($ungated)->toBe([]);
});

describe('a normal authenticated user', function (): void {
    beforeEach(function (): void {
        config(['admin.emails' => ['operator@example.com']]);
    });

    it('cannot reach any admin route or endpoint', function (string $path): void {
        actingAs(User::factory()->create(['email' => 'ordinary@example.com']));

        // 404, not 403: the panel's existence is not advertised.
        getJson($path)->assertNotFound();
    })->with(adminRoutes());

    it('is not told it is an admin by the user payload', function (): void {
        actingAs(User::factory()->create(['email' => 'ordinary@example.com']));

        getJson('/api/v1/user')->assertOk()->assertJsonPath('data.features.admin', false);
    });

    it('cannot reach the panel by matching an allowlisted address case-insensitively in the wrong direction', function (): void {
        // "operator@example.com.evil.test" must not match "operator@example.com".
        actingAs(User::factory()->create(['email' => 'operator@example.com.evil.test']));

        getJson('/api/v1/admin/metrics')->assertNotFound();
    });
});

describe('a guest', function (): void {
    it('cannot reach any admin route or endpoint', function (string $path): void {
        config(['admin.emails' => ['operator@example.com']]);

        // Unauthenticated: 401 from the auth middleware, or 404 — either way, no
        // data. Never 200.
        expect(getJson($path)->status())->toBeIn([401, 404]);
    })->with(adminRoutes());
});

describe('an allowlisted user', function (): void {
    beforeEach(function (): void {
        config(['admin.emails' => ['operator@example.com']]);
    });

    it('reaches the metrics endpoint', function (): void {
        actingAs(User::factory()->create(['email' => 'operator@example.com']));

        getJson('/api/v1/admin/metrics')
            ->assertOk()
            ->assertJsonStructure(['data' => ['window', 'volume', 'latency', 'tokens', 'agent', 'failures']]);
    });

    it('matches the allowlist regardless of case or surrounding whitespace', function (): void {
        config(['admin.emails' => ['operator@example.com']]);
        actingAs(User::factory()->create(['email' => 'Operator@Example.COM']));

        getJson('/api/v1/admin/metrics')->assertOk();
    });

    it('is told it is an admin by the user payload', function (): void {
        actingAs(User::factory()->create(['email' => 'operator@example.com']));

        getJson('/api/v1/user')->assertOk()->assertJsonPath('data.features.admin', true);
    });
});

describe('the allowlist fails closed', function (): void {
    it('grants nobody when ADMIN_EMAILS is unset', function (string $path): void {
        config(['admin.emails' => []]);

        actingAs(User::factory()->create(['email' => 'operator@example.com']));

        getJson($path)->assertNotFound();
    })->with(adminRoutes());
});

describe('the exchange list', function (): void {
    beforeEach(function (): void {
        config(['admin.emails' => ['operator@example.com']]);
    });

    it('returns metadata without any question or answer text', function (): void {
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->for($owner)->create();
        $conversation->messages()->create(['role' => 'user', 'content' => 'ТАЈНО ПРАШАЊЕ ЗА ОТКАЗ']);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'ТАЈНИОТ ОДГОВОР',
            'model' => 'gemini-flash-lite-latest',
            'metadata' => ['refused' => false, 'chunk_count' => 10, 'ttft_ms' => 3084, 'top_chunks' => [
                ['id' => '1', 'text' => 'x', 'source' => 'ZAKON-ZA-RABOTNI, чл. 96', 'score' => 0.1],
            ]],
        ]);

        actingAs(User::factory()->create(['email' => 'operator@example.com']));

        $response = getJson('/api/v1/admin/exchanges')->assertOk();

        // The content of neither turn appears anywhere in the list payload.
        $body = $response->getContent();
        expect($body)->not->toContain('ТАЈНО ПРАШАЊЕ')
            ->and($body)->not->toContain('ТАЈНИОТ ОДГОВОР');

        $response->assertJsonPath('data.0.path', 'pipeline')
            ->assertJsonPath('data.0.ttft_ms', 3084)
            // Size without content.
            ->assertJsonPath('data.0.question_chars', mb_strlen('ТАЈНО ПРАШАЊЕ ЗА ОТКАЗ'))
            ->assertJsonPath('data.0.citations.0', 'ZAKON-ZA-RABOTNI, чл. 96');
    });

    it('does not leak the agent-generated search queries into the list', function (): void {
        // An agent query is a paraphrase of the question. Listing the strings
        // would put question content back into the list view by the side door.
        $conversation = Conversation::factory()->for(User::factory())->create();
        $conversation->messages()->create(['role' => 'user', 'content' => 'Прашање?']);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Одговор.',
            'metadata' => ['agent' => true, 'searches' => [
                ['tool' => 'search_law', 'query' => 'технолошки вишок отказ испратнина'],
            ]],
        ]);

        actingAs(User::factory()->create(['email' => 'operator@example.com']));

        $response = getJson('/api/v1/admin/exchanges')->assertOk();

        expect($response->getContent())->not->toContain('технолошки вишок отказ испратнина');
        $response->assertJsonPath('data.0.search_count', 1);
    });

    it('returns the text only from the single-exchange endpoint', function (): void {
        $conversation = Conversation::factory()->for(User::factory())->create();
        $conversation->messages()->create(['role' => 'user', 'content' => 'ТАЈНО ПРАШАЊЕ']);
        $answer = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'ТАЈНИОТ ОДГОВОР',
            'metadata' => ['refused' => false, 'chunk_count' => 5],
        ]);

        actingAs(User::factory()->create(['email' => 'operator@example.com']));

        getJson("/api/v1/admin/exchanges/{$answer->id}")
            ->assertOk()
            ->assertJsonPath('data.question', 'ТАЈНО ПРАШАЊЕ')
            ->assertJsonPath('data.answer', 'ТАЈНИОТ ОДГОВОР');
    });

    it('serves an agent run trajectory', function (): void {
        $conversation = Conversation::factory()->for(User::factory())->create();
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Одговор.',
            'metadata' => ['agent' => true],
        ]);
        $run = $message->agentRun()->create([
            'run_id' => '20260829-110442-opqr0w',
            'question' => 'Прашање?',
            'outcome' => 'answered',
            'model' => 'gemini-flash-lite-latest',
            'steps_count' => 2, 'api_calls' => 2, 'total_tokens' => 8964, 'duration_ms' => 12137,
            'steps' => [['index' => 0, 'calls' => [['tool' => 'search_law', 'query' => 'испратнина', 'sources' => []]]]],
            'passages' => [],
        ]);

        actingAs(User::factory()->create(['email' => 'operator@example.com']));

        getJson("/api/v1/admin/agent-runs/{$run->id}/trace")
            ->assertOk()
            ->assertJsonPath('data.run_id', '20260829-110442-opqr0w')
            ->assertJsonPath('data.totals.total_tokens', 8964);
    });
});
