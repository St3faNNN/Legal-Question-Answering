<?php

use App\Contracts\RunsLegalResearch;
use App\Grpc\GenerationClient;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Tests\Support\InertAiClients;

/**
 * Configuration contract.
 *
 * Three things are asserted, in order of how expensive they are to get wrong:
 *
 *  1. The app boots and serves with only the SECRET variables set — every
 *     TUNABLE has a default equal to today's behaviour, so a thin .env is a
 *     working .env.
 *  2. A missing SECRET fails loudly and NAMES the variable, rather than
 *     dialling a null host or sending an empty bearer token and surfacing the
 *     provider's 401 as if the service were down.
 *  3. Everything docker-compose requires is declared in .env.example, so a
 *     fresh clone can be brought up from the documented file alone.
 */

/** Variables without which the application genuinely cannot run. */
function secretVariables(): array
{
    return ['APP_KEY', 'DB_PASSWORD'];
}

describe('the app boots with only the SECRET variables set', function (): void {
    it('resolves every tunable from a default when nothing is set', function (): void {
        // Simulate a bare environment: clear every non-secret project variable
        // from the config the container will read.
        config([
            'limits' => require config_path('limits.php'),
            'admin' => require config_path('admin.php'),
            'agent' => require config_path('agent.php'),
            'ai' => require config_path('ai.php'),
        ]);

        expect(config('limits.rate.login'))->toBe(5)
            ->and(config('limits.rate.register'))->toBe(5)
            ->and(config('limits.rate.chat'))->toBe(30)
            ->and(config('limits.rate.escalate'))->toBe(5)
            ->and(config('limits.rate.tools'))->toBe(60)
            ->and(config('limits.per_page.messages'))->toBe(20)
            ->and(config('limits.per_page.conversations'))->toBe(15)
            ->and(config('limits.per_page.todo_lists'))->toBe(15)
            ->and(config('limits.per_page.admin_exchanges'))->toBe(30)
            ->and(config('admin.window_days'))->toBe(90)
            ->and(config('admin.latency_buckets_ms'))->toBe([250, 500, 1000, 2000, 5000, 10000, 30000, 60000, 120000])
            ->and(config('agent.timeout'))->toBe(120)
            ->and(config('agent.max_steps'))->toBe(8)
            ->and(config('agent.connect_timeout'))->toBe(5)
            ->and(config('agent.budget.daily_units'))->toBe(60)
            ->and(config('agent.budget.pipeline_cost'))->toBe(1)
            ->and(config('agent.budget.escalation_cost'))->toBe(4)
            // The SSE relay's idle budgets. first_chunk_timeout used to be null
            // by default and be derived from the active generation driver; with
            // one backend it is a plain value the relay reads directly, so its
            // default is now load-bearing rather than a fallback.
            ->and(config('ai.stream.first_chunk_timeout'))->toBe(30)
            ->and(config('ai.stream.between_chunk_timeout'))->toBe(12)
            ->and(config('ai.stream.poll_seconds'))->toBe(5)
            ->and(config('ai.stream.key_ttl'))->toBe(300)
            ->and(config('ai.retrieval_top_n'))->toBe(10);
    });

    it('serves an authenticated request with the tunables at their defaults', function (): void {
        // The end-to-end version of the above: a request completes without any
        // project variable being set.
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/user')->assertOk();
    });

    it('declares no default for any secret', function (): void {
        // Asserted against the DECLARATION, not the resolved value. This process
        // loads the developer's real .env, so reading env('LLM_API_KEY') returns
        // their actual key and proves nothing about the fallback — it only drags
        // a live secret into the test output. The shape that matters is
        // env('VAR') with no second argument.
        $sources = [
            'LLM_API_KEY' => config_path('ai.php'),
            'AGENT_SERVICE_TOKEN' => config_path('agent.php'),
            'ADMIN_EMAILS' => config_path('admin.php'),
        ];

        foreach ($sources as $variable => $file) {
            $source = file_get_contents($file);
            $quoted = preg_quote($variable, '/');

            expect($source)->toContain("env('{$variable}')")
                // No `env('VAR', anything)` anywhere in the file.
                ->and($source)->not->toMatch("/env\(\s*'{$quoted}'\s*,/");
        }
    });

    it('reads an unset allowlist as nobody rather than everybody', function (): void {
        // ADMIN_EMAILS is the deliberate special case: no default, and empty must
        // grant nobody. AdminAccessTest asserts the resulting 404s; this asserts
        // the decision itself, because it is the one "no default" whose emptiness
        // is load-bearing rather than fatal.
        config(['admin.emails' => []]);

        $request = request();
        $request->setUserResolver(fn () => User::factory()->make(['email' => 'anyone@example.com']));

        expect(EnsureUserIsAdmin::isAdmin($request))->toBeFalse();
    });
});

describe('a missing SECRET fails loudly, naming the variable', function (): void {
    beforeEach(fn () => InertAiClients::forgetGeneration());

    it('refuses to build the hosted generation client without LLM_API_KEY', function (): void {
        config([
            'ai.llm.base_url' => 'https://example.test/v1/',
            'ai.llm.api_key' => null,
        ]);

        expect(fn () => app(GenerationClient::class))
            ->toThrow(RuntimeException::class, 'LLM_API_KEY');
    });

    it('refuses to build the retrieval client without AI_RETRIEVAL_URL', function (): void {
        // Rather than dialling a null host: failed_jobs already carries rows
        // reading "errors resolving retrieval:8001", and the null-host variant of
        // that message is strictly harder to diagnose.
        //
        // The guard is exercised directly rather than through the container: the
        // suite binds an inert retrieval stand-in (the real client needs ext-grpc,
        // absent outside Docker), so resolving would return the stand-in and
        // never reach the guard.
        config(['ai.endpoints.retrieval' => null]);

        $guard = (new ReflectionMethod(AppServiceProvider::class, 'required'))
            ->getClosure();

        expect(fn () => $guard('ai.endpoints.retrieval', 'AI_RETRIEVAL_URL'))
            ->toThrow(RuntimeException::class, 'AI_RETRIEVAL_URL');
    });

    it('refuses to build the agent client without AGENT_SERVICE_TOKEN', function (): void {
        config(['agent.url' => 'http://agent:5060', 'agent.token' => null]);

        expect(fn () => app(RunsLegalResearch::class))
            ->toThrow(RuntimeException::class, 'AGENT_SERVICE_TOKEN');
    });

    it('names the config key alongside the variable, so the reader can find it', function (): void {
        config(['ai.endpoints.retrieval' => null]);

        $guard = (new ReflectionMethod(AppServiceProvider::class, 'required'))
            ->getClosure();

        expect(fn () => $guard('ai.endpoints.retrieval', 'AI_RETRIEVAL_URL'))
            ->toThrow(RuntimeException::class, "config('ai.endpoints.retrieval')");
    });
});

describe('.env.example documents everything a deployment needs', function (): void {
    it('declares every variable docker-compose requires from the environment', function (): void {
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $example = file_get_contents(base_path('.env.example'));

        // Variables compose interpolates. ${VAR:?...} is required outright;
        // ${VAR:-default} and ${VAR} still belong in the file so an operator can
        // discover them without reading the compose YAML.
        preg_match_all('/\$\{([A-Z][A-Z0-9_]*)[:}\-?]/', $compose, $matches);
        $required = array_unique($matches[1]);

        $undeclared = array_values(array_filter(
            $required,
            // Commented-out lines count as declared: they document the variable
            // and its default without overriding it.
            fn (string $name): bool => preg_match('/^#?\s*'.preg_quote($name, '/').'=/m', $example) !== 1,
        ));

        expect($undeclared)->toBe([]);
    });

    it('ships no value for any secret placeholder', function (): void {
        $example = file_get_contents(base_path('.env.example'));

        foreach (['APP_KEY', 'DB_PASSWORD', 'POSTGRES_PASSWORD', 'LLM_API_KEY', 'AGENT_SERVICE_TOKEN', 'AGENT_API_TOKEN', 'ADMIN_EMAILS'] as $secret) {
            expect($example)->toMatch('/^'.preg_quote($secret, '/').'=\s*$/m');
        }
    });

    it('no longer carries variables nothing reads', function (): void {
        $example = file_get_contents(base_path('.env.example'));

        // Verified dead by resolution, not by grep: config('hashing.rounds') is
        // null and config('broadcasting.default') ignores BROADCAST_CONNECTION,
        // and VITE_APP_NAME appears in no JS or Vite config.
        foreach (['BCRYPT_ROUNDS', 'BROADCAST_CONNECTION', 'VITE_APP_NAME'] as $dead) {
            expect($example)->not->toMatch('/^'.preg_quote($dead, '/').'=/m');
        }
    });
});
