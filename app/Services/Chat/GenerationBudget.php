<?php

namespace App\Services\Chat;

use App\Exceptions\GenerationBudgetExceededException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A per-user daily budget for generated answers, denominated in units so the two
 * answer paths can share one meter.
 *
 * The throttle middleware already bounds *bursts* (requests per minute). This
 * bounds *spend*, which is a different quantity: one escalation is worth roughly
 * three ordinary questions in tokens, and a per-minute request limit cannot see
 * that difference. Charging both paths against the same counter is what makes an
 * escalation cost something relative to a normal question rather than being free.
 *
 * The counter is a cache key that expires at midnight, so there is nothing to
 * reset and no row to clean up. A cache flush forgives a day's spend — acceptable
 * for a fair-use cap, and the reason this is not the place for anything billed.
 */
class GenerationBudget
{
    /** Charge for one pipeline answer: a single generation call, measured at 4,628 tokens. */
    public function pipelineCost(): int
    {
        return max(0, (int) config('agent.budget.pipeline_cost', 1));
    }

    /**
     * Charge for one agent escalation. Measured across three runs of the same
     * question at 8,964 / 25,274 / 25,397 tokens over 2-4 model calls — a mean of
     * 4.3x the pipeline's single call. Sized on the mean rather than the cheapest
     * run, so a streak of three-search runs still fits the cap.
     */
    public function escalationCost(): int
    {
        return max(0, (int) config('agent.budget.escalation_cost', 4));
    }

    /** Units a user may spend per day; 0 disables the meter. */
    public function allowance(): int
    {
        return max(0, (int) config('agent.budget.daily_units', 0));
    }

    public function used(User $user): int
    {
        return (int) Cache::get($this->key($user), 0);
    }

    public function remaining(User $user): int
    {
        if ($this->allowance() === 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->allowance() - $this->used($user));
    }

    /**
     * Charge the user, or refuse. Increment-then-check rather than check-then-
     * increment: two concurrent requests would both pass a read-first check and
     * overspend, whereas an atomic increment lets the loser see the overshoot and
     * hand its units back.
     *
     * @throws GenerationBudgetExceededException when the charge would exceed the allowance
     */
    public function spend(User $user, int $units): void
    {
        $allowance = $this->allowance();
        if ($allowance === 0 || $units === 0) {
            return;
        }

        $key = $this->key($user);
        Cache::add($key, 0, $this->expiresAt());

        $total = (int) Cache::increment($key, $units);

        if ($total > $allowance) {
            // Roll the charge back so a refused request does not consume budget,
            // then report what was actually left before this attempt.
            Cache::decrement($key, $units);

            throw new GenerationBudgetExceededException(
                cost: $units,
                remaining: max(0, $allowance - ($total - $units)),
                allowance: $allowance,
            );
        }
    }

    /**
     * Hand units back — used when a charged request never reached generation
     * (a gated greeting, a dispatch that failed), so the meter reflects spend.
     */
    public function refund(User $user, int $units): void
    {
        if ($this->allowance() === 0 || $units === 0) {
            return;
        }

        Cache::decrement($this->key($user), $units);
    }

    private function key(User $user): string
    {
        return "generation-budget:{$user->id}:".Carbon::now()->toDateString();
    }

    private function expiresAt(): Carbon
    {
        return Carbon::now()->endOfDay();
    }
}
