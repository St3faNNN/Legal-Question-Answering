<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin Allowlist
    |--------------------------------------------------------------------------
    |
    | Who may reach the operational dashboard, by email address. A config
    | allowlist rather than a role column: the invite system deliberately avoided
    | role scaffolding, and one page does not justify introducing it. There is no
    | migration and no way to grant access from inside the app — access changes
    | are a deploy, which is the right friction for a panel that can read
    | conversation content.
    |
    | Comma-separated in ADMIN_EMAILS. Comparison is case-insensitive and
    | whitespace-trimmed, because an env var edited by hand will eventually carry
    | a stray space or a capital.
    |
    | FAILS CLOSED: an unset or empty ADMIN_EMAILS means nobody is an admin, and
    | every admin route 404s. A typo in the variable therefore locks the panel
    | rather than opening it.
    |
    */

    'emails' => array_values(array_filter(array_map(
        static fn (string $email): string => mb_strtolower(trim($email)),
        // NO DEFAULT, deliberately. (string) null is '' anyway, so this is the
        // same behaviour with the policy made explicit in the declaration: there
        // is nothing to fall back to, and empty means nobody.
        explode(',', (string) env('ADMIN_EMAILS')),
    ), static fn (string $email): bool => $email !== '')),

    /*
    |--------------------------------------------------------------------------
    | Metrics Window
    |--------------------------------------------------------------------------
    |
    | How far back the dashboard aggregates, in days. The aggregation reads
    | message rows into PHP (the metadata is a JSON column, and querying inside it
    | differs between the sqlite used in tests and the Postgres used in
    | production), so the window is what keeps that bounded. At the current scale
    | — a few hundred messages — this is immaterial; if the corpus of messages
    | grows into six figures, this is the number to lower before reaching for a
    | rollup table.
    |
    */

    'window_days' => (int) env('ADMIN_METRICS_WINDOW_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Latency Histogram Buckets (ms)
    |--------------------------------------------------------------------------
    |
    | Upper edges for the latency histograms, previously a constant on
    | MetricsService. They deliberately span four orders of magnitude: the hosted
    | path answers in seconds while the retired local llama.cpp path took ~90s,
    | and the stored data contains both.
    |
    | Comma-separated in the env var; the default is the list that shipped.
    |
    */

    'latency_buckets_ms' => array_values(array_filter(array_map(
        static fn (string $edge): int => (int) trim($edge),
        explode(',', (string) env('ADMIN_LATENCY_BUCKETS_MS', '250,500,1000,2000,5000,10000,30000,60000,120000')),
    ), static fn (int $edge): bool => $edge > 0)),

];
