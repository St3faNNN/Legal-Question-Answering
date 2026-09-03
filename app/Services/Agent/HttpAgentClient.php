<?php

namespace App\Services\Agent;

use App\Contracts\RunsLegalResearch;
use App\Exceptions\AiServiceException;
use App\Grpc\CurlOpenAiStreamTransport;
use App\Grpc\OpenAiStreamTransport;
use RuntimeException;

/**
 * {@see RunsLegalResearch} backed by the agent's HTTP service (agent/src/server.ts).
 *
 * ## Why the agent is a service and not PHP
 *
 * The loop is the artefact: `agent/src/agent.ts` is a hand-written tool-calling
 * loop whose four load-bearing details (assistant message before its tool
 * results, matching tool_call_id, tool failures as results not exceptions, a step
 * budget) are the point of the project. Reimplementing it in PHP would delete the
 * thing being demonstrated, so it was not considered further.
 *
 * That leaves two ways to reach TypeScript from PHP:
 *
 *   - **A Node child process from the queue worker.** The app image is
 *     `php:8.4-cli` with no Node, so this needs Node added to it — and that
 *     rebuild recompiles the grpc PECL extension from source (~an hour, per the
 *     note in docker-compose.yml). It also makes progress awkward: the CLI's
 *     trace is a file, so "which search is running now" becomes disk polling, and
 *     the worker owns a child process's lifetime on top of the job's.
 *   - **A small HTTP service** (this class). The agent has *zero runtime
 *     dependencies*, so the container is `node:24-alpine` plus a bind mount —
 *     no npm install, no build step, no change to the PHP image. It streams
 *     NDJSON, which maps onto the Redis-list -> SSE relay the chat already uses,
 *     so each search reaches the browser as it is issued.
 *
 * The service won because it is the pattern the app already uses three times
 * over: an AI capability behind an interface, bound in AppServiceProvider, faked
 * in tests. Its cost is one more Compose service.
 *
 * ## Transport
 *
 * Reuses {@see OpenAiStreamTransport} — despite the name it is the app's generic
 * "streaming POST whose body is pulled as it arrives" seam, and NDJSON needs
 * exactly that. No new transport, no new dependency, and the existing
 * FakeOpenAiStreamTransport drives this class in tests without a socket.
 */
class HttpAgentClient implements RunsLegalResearch
{
    private readonly OpenAiStreamTransport $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout,
        private readonly int $maxSteps,
        /** Seconds to wait for the connection itself, apart from the run's budget. */
        private readonly int $connectTimeout = 5,
        ?OpenAiStreamTransport $transport = null,
    ) {
        $this->transport = $transport ?? new CurlOpenAiStreamTransport;
    }

    public function run(string $question): iterable
    {
        $handle = $this->transport->post(
            rtrim($this->baseUrl, '/').'/run',
            [
                'Content-Type: application/json',
                'Accept: application/x-ndjson',
                'Authorization: Bearer '.$this->token,
            ],
            json_encode(['question' => $question, 'maxSteps' => $this->maxSteps], JSON_UNESCAPED_UNICODE),
            $this->connectTimeout,
            $this->timeout,
        );

        try {
            if ($handle->status() !== 200) {
                throw AiServiceException::httpError('agent', $handle->status());
            }

            $carry = '';
            $sawTerminal = false;

            foreach ($handle->chunks() as $chunk) {
                if ($chunk === '') {
                    continue;
                }

                $parsed = NdjsonParser::parse($chunk, $carry);
                $carry = $parsed['remainder'];

                foreach ($parsed['events'] as $event) {
                    if ($event['type'] === 'error') {
                        throw AiServiceException::invalidResponse(
                            'agent',
                            is_string($event['message'] ?? null) ? $event['message'] : 'the run failed',
                        );
                    }

                    $sawTerminal = $sawTerminal || $event['type'] === 'done';

                    yield $event;
                }
            }

            // Flush a final line the stream ended without a newline on.
            $final = NdjsonParser::parse("\n", $carry);
            foreach ($final['events'] as $event) {
                $sawTerminal = $sawTerminal || $event['type'] === 'done';

                yield $event;
            }

            // A stream that ends without its terminal event produced no answer.
            // Saying so is what stops a truncated run being persisted as a reply.
            if (! $sawTerminal) {
                throw AiServiceException::invalidResponse('agent', 'the run ended without a final answer');
            }
        } catch (AiServiceException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw AiServiceException::connectionFailed('agent', $exception);
        } finally {
            $handle->close();
        }
    }
}
