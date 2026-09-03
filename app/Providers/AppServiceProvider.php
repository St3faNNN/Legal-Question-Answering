<?php

namespace App\Providers;

use App\Contracts\RunsLegalResearch;
use App\Grpc\GenerationClient;
use App\Grpc\GrpcRetrievalClient;
use App\Grpc\OpenAiCompatibleGenerationClient;
use App\Grpc\RetrievalClient;
use App\Services\Agent\HttpAgentClient;
use App\Services\Logging\AiLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // There is no reply-generator binding here any more.
        //
        // It used to be a match over four drivers whose default arm bound a
        // PlaceholderReplyGenerator. That arm was reachable by a typo or an unset
        // AI_GENERATOR — the state the local .env was actually in — and it wrote
        // the literal string "AI service not connected yet." into `messages` as a
        // successful assistant answer: HTTP 201, no exception, no failed_jobs row,
        // and counted as produced by the volume panel. A fabricated answer that
        // looks like a real one is worse than an outage, so the branch went rather
        // than being fixed, leaving one implementation behind the interface.
        //
        // One implementation behind an interface with one caller is not a seam,
        // so GeneratesReplies and its single implementation are gone too: the
        // streaming job orchestrates retrieval and generation directly, and the
        // seams that still earn their keep are the two clients below, which is
        // where the tests bind fakes.
        //
        // gRPC retrieval client — UNCHANGED by the removal of local generation.
        // Retrieval is a separate service on :8001 and stays gRPC. Bound lazily:
        // the closure, and therefore the PHP grpc extension and the generated
        // stubs it touches, is only reached when something actually retrieves.
        // Tests bind fakes for this interface instead.
        $this->app->bind(RetrievalClient::class, fn (): RetrievalClient => new GrpcRetrievalClient(
            self::required('ai.endpoints.retrieval', 'AI_RETRIEVAL_URL'),
            // Retrieval has its own, much tighter deadline than gRPC generation:
            // it is fast now (~4-10s), so a hung retrieval should surface in
            // under a minute rather than sharing generation's 240s budget.
            (int) config('ai.retrieval_timeout', 45),
        ));

        // Generation. A hosted OpenAI-compatible endpoint is the only backend;
        // there is no branch and no fallback. A missing key throws here, naming
        // the variable, rather than sending an empty bearer token and surfacing
        // the provider's 401 as though the service were down.
        $this->app->bind(GenerationClient::class, fn (Application $app): GenerationClient => new OpenAiCompatibleGenerationClient(
            baseUrl: self::required('ai.llm.base_url', 'LLM_BASE_URL'),
            apiKey: self::required('ai.llm.api_key', 'LLM_API_KEY'),
            model: (string) config('ai.llm.model'),
            maxTokens: (int) config('ai.llm.max_tokens'),
            timeout: (int) config('ai.llm.timeout'),
            promptPath: (string) config('ai.llm.prompt_path'),
            logger: $app->make(AiLogger::class),
        ));

        // The escalation path. Bound lazily like the two above, so a deploy
        // without an agent service never constructs a client — EscalationService
        // refuses at the boundary instead, and no button is offered. The
        // implementation is HTTP because the agent is TypeScript and stays that
        // way; see HttpAgentClient for the bridging decision. Tests bind a fake.
        $this->app->bind(RunsLegalResearch::class, fn (): RunsLegalResearch => new HttpAgentClient(
            baseUrl: self::required('agent.url', 'AGENT_SERVICE_URL'),
            token: self::required('agent.token', 'AGENT_SERVICE_TOKEN'),
            timeout: (int) config('agent.timeout', 120),
            maxSteps: (int) config('agent.max_steps', 8),
            connectTimeout: (int) config('agent.connect_timeout', 5),
        ));
    }

    /**
     * Read a config value that has no safe default, failing loudly and naming
     * the environment variable when it is absent.
     *
     * Two failure modes this exists to prevent, both observed:
     *
     *   - A missing endpoint becomes an empty host, and the client reports a
     *     connection error against "" instead of saying which variable is unset.
     *     failed_jobs already carries three rows reading "errors resolving
     *     retrieval:8001"; the null-host variant of that message is strictly
     *     harder to diagnose.
     *   - A missing API key becomes an empty bearer token, and the provider's
     *     401 surfaces as if the service were down.
     *
     * Thrown from the container binding, so it fires when the client is first
     * resolved rather than at boot: an app whose generation driver is never
     * exercised (the placeholder driver, most tests) still starts.
     */
    private static function required(string $key, string $variable): string
    {
        $value = config($key);

        if ($value === null || trim((string) $value) === '') {
            throw new RuntimeException(
                "Missing required configuration: set {$variable} in your environment "
                ."(read as config('{$key}'))."
            );
        }

        return (string) $value;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every limit reads config/limits.php, whose defaults are the literals
        // that used to be written here — an unset variable changes nothing.
        // Keyed by IP for the unauthenticated endpoints (the caller has no
        // account yet) and by user id elsewhere.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(
            (int) config('limits.rate.login')
        )->by($request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(
            (int) config('limits.rate.register')
        )->by($request->ip()));

        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(
            (int) config('limits.rate.chat')
        )->by($request->user()?->id ?: $request->ip()));

        // An agent run measured 25,397 tokens against ~8k for a pipeline answer,
        // and takes ~7.5s of wall clock rather than ~2.6s. Five a minute bounds
        // the burst; the daily unit budget (GenerationBudget) bounds the spend.
        RateLimiter::for('escalate', fn (Request $request) => Limit::perMinute(
            (int) config('limits.rate.escalate')
        )->by($request->user()?->id ?: $request->ip()));

        // The agent's tool endpoints. Named rather than the inline "throttle:60,1"
        // it replaced, so the number lives in config with everything else; the
        // key and the rate are unchanged.
        RateLimiter::for('tools', fn (Request $request) => Limit::perMinute(
            (int) config('limits.rate.tools')
        )->by($request->user()?->id ?: $request->ip()));
    }
}
