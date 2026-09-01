<?php

namespace App\Providers;

use App\Contracts\GeneratesReplies;
use App\Contracts\RunsLegalResearch;
use App\Grpc\GenerationClient;
use App\Grpc\GrpcGenerationClient;
use App\Grpc\GrpcRetrievalClient;
use App\Grpc\OpenAiCompatibleGenerationClient;
use App\Grpc\RetrievalClient;
use App\Services\Agent\HttpAgentClient;
use App\Services\Chat\GraphRagGrpcReplyGenerator;
use App\Services\Chat\GraphRagReplyGenerator;
use App\Services\Chat\PlaceholderReplyGenerator;
use App\Services\Logging\AiLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GeneratesReplies::class, function (Application $app): GeneratesReplies {
            return match (config('ai.generator')) {
                'http' => $app->make(GraphRagReplyGenerator::class),
                // gRPC and openai_compatible share the orchestrator: retrieval is
                // gRPC either way; only the GenerationClient binding below differs.
                'grpc', 'openai_compatible' => $app->make(GraphRagGrpcReplyGenerator::class),
                'placeholder' => $app->make(PlaceholderReplyGenerator::class),
                default => $app->make(PlaceholderReplyGenerator::class),
            };
        });

        // gRPC retrieval client. Bound lazily: the closure — and therefore the
        // PHP grpc extension and the generated stubs it touches — is only reached
        // when a driver that orchestrates retrieval resolves it (Docker).
        // Retrieval is gRPC under both the grpc and openai_compatible drivers.
        // Tests bind fakes for this interface instead.
        $this->app->bind(RetrievalClient::class, fn (): RetrievalClient => new GrpcRetrievalClient(
            (string) config('ai.endpoints.retrieval'),
            // Retrieval has its own, much tighter deadline than gRPC generation:
            // it is fast now (~4-10s), so a hung retrieval should surface in
            // under a minute rather than sharing generation's 240s budget.
            (int) config('ai.retrieval_timeout', 45),
        ));

        // Generation client. This is the only seam that swaps between backends:
        // StreamChatReply resolves GenerationClient and never learns which one it
        // got. openai_compatible streams from a hosted endpoint over HTTP;
        // everything else uses the local llama.cpp service over gRPC.
        $this->app->bind(GenerationClient::class, function (Application $app): GenerationClient {
            if (config('ai.generator') === 'openai_compatible') {
                return new OpenAiCompatibleGenerationClient(
                    baseUrl: (string) config('ai.llm.base_url'),
                    apiKey: (string) config('ai.llm.api_key'),
                    model: (string) config('ai.llm.model'),
                    maxTokens: (int) config('ai.llm.max_tokens'),
                    timeout: (int) config('ai.llm.timeout'),
                    promptPath: (string) config('ai.llm.prompt_path'),
                    logger: $app->make(AiLogger::class),
                );
            }

            return new GrpcGenerationClient(
                (string) config('ai.endpoints.generation'),
                (int) config('ai.timeout', 30),
            );
        });

        // The escalation path. Bound lazily like the two above, so a deploy
        // without an agent service never constructs a client — EscalationService
        // refuses at the boundary instead, and no button is offered. The
        // implementation is HTTP because the agent is TypeScript and stays that
        // way; see HttpAgentClient for the bridging decision. Tests bind a fake.
        $this->app->bind(RunsLegalResearch::class, fn (): RunsLegalResearch => new HttpAgentClient(
            baseUrl: (string) config('agent.url'),
            token: (string) config('agent.token'),
            timeout: (int) config('agent.timeout', 120),
            maxSteps: (int) config('agent.max_steps', 8),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // An agent run measured 25,397 tokens against ~8k for a pipeline answer,
        // and takes ~7.5s of wall clock rather than ~2.6s. Five a minute bounds
        // the burst; the daily unit budget (GenerationBudget) bounds the spend.
        RateLimiter::for('escalate', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
    }
}
