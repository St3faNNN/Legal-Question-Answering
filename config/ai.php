<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retrieval Deadline
    |--------------------------------------------------------------------------
    |
    | Bounds the gRPC retrieval call. Generation is a hosted HTTP endpoint and
    | uses "llm.timeout" below; there is no second generation backend to size a
    | deadline for. The local llama.cpp service (and the AI_GENERATOR /
    | AI_TIMEOUT / AI_GENERATION_URL knobs that selected it) was removed — it was
    | not viable on a t3.medium.
    |
    */

    'retrieval_timeout' => (int) env('AI_RETRIEVAL_TIMEOUT', 45),

    /*
    |--------------------------------------------------------------------------
    | Retrieved Passages (RETRIEVAL_TOP_N)
    |--------------------------------------------------------------------------
    |
    | How many passages the retrieval service returns, and therefore how many the
    | generator reads. The service owns this number (services/retrieval/app/
    | config.py, _resolve_top_n) — the value here is the *same env var*, read by
    | the app so the provisions panel can size itself to the generation context
    | instead of to a separate constant.
    |
    | That separation is what produced the severance case: the panel showed 5
    | while the generator read 10, the answer was grounded in the article at rank
    | 6, and the provision it cited from was never displayed. Retrieval,
    | generation and a practitioner's correctness review all passed, because none
    | of them compares what was shown against what was read. Deriving the panel
    | from this value is what stops the two drifting apart again; see
    | ComposesGraphRagReply::panelLimit().
    |
    | IMPORTANT: this is one env var read by two processes. In Docker it must be
    | set on the app containers as well as the retrieval container (both are
    | wired in docker-compose.yml) or the app will size the panel to its default
    | while the service returns something else.
    |
    | RERANK_TOP_N was honoured here as a deprecated alias. It is gone: the
    | retrieval service stopped reading it when the cross-encoder was deleted, so
    | honouring it on this side would have sized the panel from a variable the
    | service ignores — the exact desynchronisation the alias existed to prevent.
    | A stale RERANK_TOP_N in an old .env is now ignored by both processes.
    |
    */

    'retrieval_top_n' => (int) (env('RETRIEVAL_TOP_N') ?: 10),

    /*
    |--------------------------------------------------------------------------
    | Retrieval Endpoint
    |--------------------------------------------------------------------------
    |
    | gRPC target for the Retrieval microservice (host:port, no scheme). There is
    | no generation endpoint here any more: generation is a hosted HTTP endpoint
    | configured under "llm" below.
    |
    */

    'endpoints' => [
        // Retrieval only. NO DEFAULT: the hostname resolves only on the Compose
        // network, so an absent value fails at resolve-time naming the variable
        // rather than dialling a null host.
        'retrieval' => env('AI_RETRIEVAL_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosted Generation (the only generation backend)
    |--------------------------------------------------------------------------
    |
    | The only generation backend: a hosted chat-completions endpoint. The local
    | llama.cpp service it used to sit beside was removed (retrieval is untouched
    | and still gRPC). The names are provider-neutral, so pointing at a different
    | provider is an env change. The Macedonian system prompt lives in a single
    | file (prompts/legal_mk.txt) that this driver loads.
    |
    */

    'llm' => [
        'base_url' => env('LLM_BASE_URL'),
        // SECRET — no default. Null when unset, and AppServiceProvider refuses
        // to build the generation client rather than sending an empty bearer
        // token and reporting the provider's 401 as if it were a service
        // outage. A secret that falls back to a placeholder is worse than one
        // that crashes.
        'api_key' => env('LLM_API_KEY'),
        // DEFAULT CHANGED (2026-09-01), deliberately: this read
        // 'gemini-2.5-flash-lite' while docker-compose and every live .env set
        // 'gemini-flash-lite-latest'. A fresh checkout without a .env therefore
        // ran a different model from every real deployment. The default now
        // matches what actually runs.
        'model' => env('LLM_MODEL', 'gemini-flash-lite-latest'),
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
    |   - "first_chunk_timeout": silence tolerated before the first token. The
    |     pre-token gap is retrieval (~4-10s) then a ~1-2s prefill, so 30s
    |     surfaces a killed worker fast while clearing a warm request with room.
    |     This used to be derived from the active driver, because the local
    |     llama.cpp path added ~90s of prefill and needed ~120s; with one backend
    |     there is one budget and the relay reads this key directly. Raise it if a
    |     cold first-inference retrieval runs long.
    |   - "between_chunk_timeout": silence tolerated once tokens flow. The median
    |     inter-token gap measured ~93ms, so 12s (~130x) clears any normal
    |     provider pause yet surfaces a mid-stream stall in seconds, not minutes.
    |     Once decoding starts, it streams.
    |   - "poll_seconds": blpop block length — also the SSE keepalive cadence and
    |     the granularity at which the budgets above are re-checked.
    |
    */

    'stream' => [
        'first_chunk_timeout' => (int) env('CHAT_STREAM_FIRST_CHUNK_TIMEOUT', 30),
        'between_chunk_timeout' => (int) env('CHAT_STREAM_BETWEEN_CHUNK_TIMEOUT', 12),
        'poll_seconds' => (int) env('CHAT_STREAM_POLL_SECONDS', 5),
        // How long a stream's ownership key and its event list live in Redis.
        // Was the literal 300 at four call sites (ChatService, EscalationService,
        // and both jobs' publish()). It bounds how long after dispatch a browser
        // can still attach to a stream, so it must comfortably exceed the
        // slowest job budget (StreamChatReply::TIMEOUT_SECONDS).
        'key_ttl' => (int) env('CHAT_STREAM_KEY_TTL', 300),
    ],

];
