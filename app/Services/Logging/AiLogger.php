<?php

namespace App\Services\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Laravel's log channels for AI-related events.
 *
 * Phase 1 logs placeholder activity; Phase 2 will log retrieval latency,
 * generation latency, token usage, and errors via the same methods.
 */
class AiLogger
{
    private const CHANNEL = 'ai';

    public function info(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->warning($event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->error($event, $context);
    }
}
