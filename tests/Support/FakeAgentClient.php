<?php

namespace Tests\Support;

use App\Contracts\RunsLegalResearch;
use Closure;

/**
 * Scripted {@see RunsLegalResearch} for the escalation tests.
 *
 * Replays a queued list of NDJSON events, or delegates to a closure when a test
 * needs to throw mid-run. Records the questions it was asked, so a test can
 * assert what actually reached the agent — the greeting case asserts it was
 * never called at all.
 */
final class FakeAgentClient implements RunsLegalResearch
{
    /** @var list<string> */
    public array $questions = [];

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        private readonly array $events = [],
        private readonly ?Closure $handler = null,
    ) {}

    public function run(string $question): iterable
    {
        $this->questions[] = $question;

        if ($this->handler !== null) {
            return ($this->handler)($question);
        }

        return $this->events;
    }

    public function called(): bool
    {
        return $this->questions !== [];
    }
}
