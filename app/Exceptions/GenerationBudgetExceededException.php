<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The user's daily generation budget cannot cover this request.
 *
 * Distinct from a rate limit: the throttle bounds requests per minute (bursts),
 * this bounds tokens per day (spend). An escalation costs several times a normal
 * answer, so both are charged against the same meter — see config/agent.php.
 *
 * Renders itself rather than being registered in bootstrap/app.php. Laravel calls
 * render() on an exception that defines it, and keeping the mapping here means
 * the whole feature lives under app/ — which is what the Docker deploy actually
 * mounts (bootstrap/ is baked into the image, so a change there needs a rebuild
 * that recompiles the grpc extension from source).
 */
class GenerationBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $cost,
        public readonly int $remaining,
        public readonly int $allowance,
    ) {
        parent::__construct("This request costs {$cost} generation unit(s) and only {$remaining} of {$allowance} remain today.");
    }

    /**
     * A 429, not a 403: the request is legitimate, it is the rate of spend that
     * is capped, and it resets.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'message' => 'Дневниот лимит за генерирани одговори е исцрпен. Обидете се повторно утре.',
            'error' => [
                'reason' => 'budget_exhausted',
                'cost_units' => $this->cost,
                'remaining_units' => $this->remaining,
                'daily_units' => $this->allowance,
            ],
        ], 429);
    }
}
