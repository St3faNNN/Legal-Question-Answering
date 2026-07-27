<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generator Driver
    |--------------------------------------------------------------------------
    |
    | This value determines which reply generator implementation is resolved
    | from the container when the ChatService needs an answer. Supported drivers:
    |
    |   - "placeholder": a static canned response (no AI infrastructure needed).
    |   - "http":  orchestrates the Retrieval and Generation microservices over
    |              HTTP/JSON.
    |   - "grpc":  the same orchestration over gRPC. Requires the PHP grpc
    |              extension and generated stubs, so it runs inside Docker; local
    |              Herd development stays on "placeholder"/"http".
    |
    | Swapping drivers never changes the ChatService or controllers.
    |
    */

    'generator' => env('AI_GENERATOR', 'placeholder'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds to wait for a response from the AI
    | microservices before aborting the request.
    |
    */

    'timeout' => env('AI_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Microservice Endpoints
    |--------------------------------------------------------------------------
    |
    | Endpoints for the Retrieval and Generation microservices. The "http"
    | driver expects base URLs (http://host:port); the "grpc" driver expects
    | gRPC targets (host:port, no scheme). Unset when the placeholder driver is
    | active.
    |
    */

    'endpoints' => [
        'retrieval' => env('AI_RETRIEVAL_URL'),
        'generation' => env('AI_GENERATION_URL'),
    ],

];
