<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Escalation Service
    |--------------------------------------------------------------------------
    |
    | The multi-hop research agent runs as its own process (agent/src/server.ts)
    | and the app reaches it over HTTP. The agent is TypeScript and the app is
    | PHP; the loop is the artefact, so it runs unchanged rather than being
    | reimplemented. See App\Services\Agent\HttpAgentClient for why a service was
    | chosen over invoking Node from the queue worker.
    |
    | Escalation is OFF unless both "url" and "token" are set — a half-configured
    | deploy must not offer a button that 503s. The token is a shared secret; the
    | service is not published outside the Compose network, but it can spend real
    | tokens, so it refuses an unauthenticated caller regardless.
    |
    */

    'url' => env('AGENT_SERVICE_URL'),

    // SECRET — no default. Null when unset. isConfigured() already reads an
    // absent token as "no agent service", which keeps the control hidden rather
    // than offering a button that 401s; and a run dispatched by any other route
    // fails naming the variable (see AppServiceProvider).
    'token' => env('AGENT_SERVICE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Deadlines and Budget
    |--------------------------------------------------------------------------
    |
    | A measured run is ~7.5s wall clock over 3-4 model calls, but a run that
    | keeps searching is bounded only by "max_steps". "timeout" is the whole-run
    | cURL cap and must clear the worst case: max_steps model calls plus their
    | tool calls. 120s at the default budget of 8 steps.
    |
    */

    'timeout' => (int) env('AGENT_SERVICE_TIMEOUT', 120),

    'max_steps' => (int) env('AGENT_MAX_STEPS', 8),

    /*
    | Seconds to wait for the connection to the agent service to open, as
    | distinct from the whole-run budget above. Previously the literal 5 on
    | HttpAgentClient::CONNECT_TIMEOUT.
    */

    'connect_timeout' => (int) env('AGENT_SERVICE_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Daily Generation Budget
    |--------------------------------------------------------------------------
    |
    | One meter for both answer paths, denominated in "units", so an escalation
    | is priced against a normal question rather than being free next to it.
    |
    | The costs come from measurement, not guesswork. On the severance question:
    |
    |   pipeline    1 call,  4,628 tokens (4,399 prompt + 229 completion)
    |   escalation  2-4 calls, 8,964 / 25,274 / 25,397 tokens over three runs
    |
    | The spread is real — the agent decomposes a question differently run to run,
    | and a lucky single search costs a quarter of a three-search run. Averaged,
    | 19,878 / 4,628 = 4.3x, so an escalation costs 4 units. Sizing on the mean
    | rather than the floor keeps a run of expensive runs inside the cap.
    |
    | The default allowance of 60 units/day is ~60 ordinary questions or ~15
    | escalations. It is above what an interactive session reaches, so the cap
    | bites on runaway use rather than on ordinary use. Set to 0 to disable the
    | meter entirely.
    |
    */

    'budget' => [
        'daily_units' => (int) env('GENERATION_DAILY_UNITS', 60),
        'pipeline_cost' => (int) env('GENERATION_PIPELINE_COST', 1),
        'escalation_cost' => (int) env('GENERATION_ESCALATION_COST', 4),
    ],

];
