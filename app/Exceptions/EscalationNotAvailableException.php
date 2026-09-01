<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The escalation path is not available for this request — the service is not
 * configured, or the message being escalated is one the agent could not improve
 * on (a gated greeting, a refusal, or an agent answer already).
 *
 * Renders itself for the same reason as {@see GenerationBudgetExceededException}:
 * the mapping belongs with the exception, and it keeps the feature inside app/,
 * which is what the Docker deploy mounts.
 */
class EscalationNotAvailableException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * A 422 rather than a 503: nothing is broken, this request is simply not one
     * the agent should be spent on.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'error' => ['reason' => $this->reason],
        ], 422);
    }
}
