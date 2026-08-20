<?php

namespace Tests\Support;

use App\Grpc\OpenAiStreamHandle;
use App\Grpc\OpenAiStreamTransport;

/**
 * Scripted {@see OpenAiStreamTransport} for the OpenAI-compatible driver tests.
 *
 * Each queued response is `{status, headers, chunks}`. With a single response,
 * every post() replays it (like Http::fake); with several, they are handed out
 * in order (like Http::fakeSequence) so the 429-then-200 retry path is testable.
 * Every request's url/headers/body is recorded for assertions on what was sent.
 */
final class FakeOpenAiStreamTransport implements OpenAiStreamTransport
{
    /** @var list<array{url: string, headers: list<string>, body: string, handle: FakeOpenAiStreamHandle}> */
    public array $requests = [];

    /** @var list<array{status?: int, headers?: array<string, string>, chunks?: list<string>}> */
    private array $responses;

    /**
     * @param  array{status?: int, headers?: array<string, string>, chunks?: list<string>}  ...$responses
     */
    public function __construct(array ...$responses)
    {
        $this->responses = $responses === [] ? [['status' => 200, 'chunks' => []]] : $responses;
    }

    public function post(
        string $url,
        array $headers,
        string $body,
        int $connectTimeout,
        int $timeout,
    ): OpenAiStreamHandle {
        // Advance through the script when several responses are queued; hold on
        // the last one otherwise, so repeated calls in a single test are cheap.
        $response = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];

        $handle = new FakeOpenAiStreamHandle(
            $response['status'] ?? 200,
            $response['headers'] ?? [],
            $response['chunks'] ?? [],
        );

        $this->requests[] = compact('url', 'headers', 'body', 'handle');

        return $handle;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    /** The decoded JSON body of the most recent request. */
    public function lastPayload(): array
    {
        return json_decode($this->requests[count($this->requests) - 1]['body'], true);
    }
}
