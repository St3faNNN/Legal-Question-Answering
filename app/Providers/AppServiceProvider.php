<?php

namespace App\Providers;

use App\Contracts\GeneratesReplies;
use App\Grpc\GenerationClient;
use App\Grpc\GrpcGenerationClient;
use App\Grpc\GrpcRetrievalClient;
use App\Grpc\RetrievalClient;
use App\Services\Chat\GraphRagGrpcReplyGenerator;
use App\Services\Chat\GraphRagReplyGenerator;
use App\Services\Chat\PlaceholderReplyGenerator;
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
                'grpc' => $app->make(GraphRagGrpcReplyGenerator::class),
                'placeholder' => $app->make(PlaceholderReplyGenerator::class),
                default => $app->make(PlaceholderReplyGenerator::class),
            };
        });

        // gRPC transport clients. Bound lazily: the closures — and therefore the
        // PHP grpc extension and the generated stubs they touch — are only
        // reached when the grpc driver resolves GraphRagGrpcReplyGenerator
        // (Docker). Tests bind fakes for these interfaces instead.
        $this->app->bind(RetrievalClient::class, fn (): RetrievalClient => new GrpcRetrievalClient(
            (string) config('ai.endpoints.retrieval'),
            (int) config('ai.timeout', 30),
        ));

        $this->app->bind(GenerationClient::class, fn (): GenerationClient => new GrpcGenerationClient(
            (string) config('ai.endpoints.generation'),
            (int) config('ai.timeout', 30),
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
    }
}
