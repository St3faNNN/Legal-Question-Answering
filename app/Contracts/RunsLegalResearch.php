<?php

namespace App\Contracts;

use App\Ai\GenerationClient;
use App\Ai\RetrievalClient;
use App\Exceptions\AiServiceException;

/**
 * The escalation path: a multi-hop research run over the corpus, in place of the
 * single retrieve-then-generate pipeline.
 *
 * The implementation behind this interface is deliberately NOT PHP. The agent is
 * a hand-written TypeScript tool-calling loop (agent/src/agent.ts) and it is the
 * artefact this project is about; it runs unchanged, as its own process, and the
 * app reaches it over HTTP. This interface is the seam — the same shape
 * {@see RetrievalClient} and {@see GenerationClient} take, so
 * the job that consumes it never learns which side of the language boundary the
 * work happened on, and tests bind a fake rather than a socket.
 */
interface RunsLegalResearch
{
    /**
     * Run the agent and yield its progress as it happens.
     *
     * A run makes several searches over ~7 seconds, so the events are pulled
     * lazily: `search` when the model asks for a query, `result` with the
     * citations that came back, then exactly one terminal `done` (or `error`).
     * The caller republishes them, which is what puts each search on screen while
     * it is running instead of behind a spinner.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws AiServiceException when the service cannot be
     *                            reached or answers unusably
     */
    public function run(string $question): iterable;
}
