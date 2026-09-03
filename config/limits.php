<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Named limiters registered in AppServiceProvider::boot(). Every default here
    | is the literal that was previously written inline, so an unset variable
    | changes nothing.
    |
    | The limiters bound bursts. Spend over a day is a different quantity and is
    | bounded separately by the generation budget (config/agent.php) — a
    | per-minute request count cannot see that an escalation costs four times a
    | normal question.
    |
    */

    'rate' => [
        // Keyed by IP: an attacker guessing passwords has no account yet.
        'login' => (int) env('RATE_LIMIT_LOGIN', 5),
        'register' => (int) env('RATE_LIMIT_REGISTER', 5),
        // Keyed by user, falling back to IP. A question costs a model call.
        'chat' => (int) env('RATE_LIMIT_CHAT', 30),
        // Deliberately far tighter than chat: a run is ~4x the tokens and ~7s of
        // wall clock.
        'escalate' => (int) env('RATE_LIMIT_ESCALATE', 5),
        // The agent's own tool endpoints. Retrieval only, no generation, so this
        // is generous — one agent run issues several searches in a few seconds.
        'tools' => (int) env('RATE_LIMIT_TOOLS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Page Sizes
    |--------------------------------------------------------------------------
    |
    | Cursor-pagination page sizes. Previously inline defaults on the service
    | methods and a constant on the admin controller.
    |
    */

    'per_page' => [
        'messages' => (int) env('PER_PAGE_MESSAGES', 20),
        'conversations' => (int) env('PER_PAGE_CONVERSATIONS', 15),
        'todo_lists' => (int) env('PER_PAGE_TODO_LISTS', 15),
        // The admin exchange list. Larger because the rows are metadata only —
        // no question or answer text travels with them.
        'admin_exchanges' => (int) env('PER_PAGE_ADMIN_EXCHANGES', 30),
    ],

];
