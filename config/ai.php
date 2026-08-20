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
    |   - "openai_compatible": retrieval stays gRPC, but generation streams from a
    |              hosted OpenAI-compatible /chat/completions endpoint (e.g.
    |              Gemini's compatibility layer) instead of the local llama.cpp
    |              service. See the "llm" block below.
    |
    | Swapping drivers never changes the ChatService or controllers.
    |
    */

    'generator' => env('AI_GENERATOR', 'placeholder'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeouts
    |--------------------------------------------------------------------------
    |
    | Per-call deadlines. They are deliberately split by service because the two
    | now live at very different speeds:
    |
    |   - "timeout" (AI_TIMEOUT) bounds the gRPC generation call. That path is
    |     the opt-in local-llm fallback (llama.cpp on CPU: ~90s prefill plus
    |     decode), so it stays generous. On the default hosted path generation
    |     uses "llm.timeout" below instead, and this value is unused.
    |   - "retrieval_timeout" (AI_RETRIEVAL_TIMEOUT) bounds the gRPC retrieval
    |     call, which runs under both drivers. Retrieval is now ~4-10s warm (a
    |     cold first-inference pass after boot is ~30s), so a 45s deadline clears
    |     it with headroom while surfacing a genuine hang in under a minute — the
    |     240s it inherited from "timeout" would have hidden a hang for four.
    |
    */

    'timeout' => env('AI_TIMEOUT', 30),

    'retrieval_timeout' => (int) env('AI_RETRIEVAL_TIMEOUT', 45),

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

    /*
    |--------------------------------------------------------------------------
    | OpenAI-Compatible LLM (openai_compatible driver)
    |--------------------------------------------------------------------------
    |
    | Used when AI_GENERATOR=openai_compatible: a hosted chat-completions
    | endpoint replaces the local llama.cpp generation service (retrieval is
    | untouched). The names are provider-neutral, so pointing at a different
    | provider is an env change. The system prompt is shared with the generation
    | service via a single file (prompts/legal_mk.txt) so both backends answer
    | identically.
    |
    */

    'llm' => [
        'base_url' => env('LLM_BASE_URL'),
        'api_key' => env('LLM_API_KEY', ''),
        'model' => env('LLM_MODEL', 'gemini-2.5-flash-lite'),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
        // Whole-request cap (cURL CURLOPT_TIMEOUT), streaming included. Hosted
        // generation is ~1-2s to first token and streams a full answer in well
        // under 30s, so 30s bounds a stalled stream inside a minute without
        // cutting a legitimately long reply.
        'timeout' => (int) env('LLM_TIMEOUT', 30),
        'prompt_path' => env('LEGAL_PROMPT_PATH', base_path('prompts/legal_mk.txt')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming Relay (SSE idle timeouts)
    |--------------------------------------------------------------------------
    |
    | The SSE relay (ChatController@streamEvents) forwards the worker's events
    | to the browser. It is a *backstop*: a job that fails cleanly publishes its
    | own terminal error (StreamChatReply::failed()), so these silence budgets
    | only fire when the worker dies without saying so — a SIGKILL, a `docker
    | compose stop`, or an OOM that leaves no hook a chance to run. That makes
    | the first-chunk window the *only* thing a user experiences during the most
    | routine operational event: a deploy or restart mid-answer.
    |
    |   - first-chunk: silence tolerated before the first token, derived from the
    |     active driver because the pre-token gap differs ~20x. On the hosted
    |     path it is retrieval (~4-10s) then a ~1-2s prefill, so 30s surfaces a
    |     killed worker fast while clearing a warm request with room; the opt-in
    |     local llama.cpp fallback adds ~90s of prefill, so it needs ~120s.
    |     "first_chunk_timeout" pins one value for both drivers when set;
    |     otherwise the relay derives it from ai.generator using the two
    |     per-driver budgets. A cold first-inference retrieval that runs long can
    |     raise the hosted budget or pin the override.
    |   - "between_chunk_timeout": silence tolerated once tokens flow. The median
    |     inter-token gap measured ~93ms, so 12s (~130x) clears any normal
    |     provider pause yet surfaces a mid-stream stall in seconds, not minutes.
    |     Same for both drivers — once decoding starts, it streams.
    |   - "poll_seconds": blpop block length — also the SSE keepalive cadence and
    |     the granularity at which the budgets above are re-checked.
    |
    */

    'stream' => [
        // Null (env unset) = derive from the active driver via the two budgets
        // below; set CHAT_STREAM_FIRST_CHUNK_TIMEOUT to pin one value for both.
        'first_chunk_timeout' => env('CHAT_STREAM_FIRST_CHUNK_TIMEOUT'),
        'first_chunk_timeout_hosted' => (int) env('CHAT_STREAM_FIRST_CHUNK_TIMEOUT_HOSTED', 30),
        'first_chunk_timeout_grpc' => (int) env('CHAT_STREAM_FIRST_CHUNK_TIMEOUT_GRPC', 120),
        'between_chunk_timeout' => (int) env('CHAT_STREAM_BETWEEN_CHUNK_TIMEOUT', 12),
        'poll_seconds' => (int) env('CHAT_STREAM_POLL_SECONDS', 5),
    ],

];
