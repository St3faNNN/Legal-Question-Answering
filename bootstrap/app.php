<?php

use App\Exceptions\AiServiceException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // This is a headless SPA/API with no server-rendered login page, so the
        // framework default of redirecting guests to a `login` route raised a 500
        // (RouteNotFoundException) for any unauthenticated request that did not
        // explicitly ask for JSON — e.g. an expired session or a bare probe.
        // Returning null suppresses the redirect so the exception handler renders
        // the 401 instead (JSON for `api/*`, see withExceptions below).
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AiServiceException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The AI service is currently unavailable. Your message was saved — please try again.',
                    'error' => [
                        'service' => $exception->service,
                        'reason' => $exception->reason,
                    ],
                ], 503);
            }
        });
    })->create();
