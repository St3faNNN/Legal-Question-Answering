<?php

namespace App\Grpc;

/**
 * Byte-level transport seam under {@see OpenAiCompatibleGenerationClient}.
 *
 * The driver was migrated off Guzzle's streamed-read loop because
 * `Http::withOptions(['stream' => true])` + `$body->read()` collapses the
 * endpoint's trickled network writes into one or two coarse reads, so every
 * token surfaced at the very end of the call (ttft == total). This interface
 * exposes a streaming POST whose body is pulled *chunk by chunk as it arrives*,
 * letting the driver parse and yield deltas the instant each write lands.
 *
 * The production implementation ({@see CurlOpenAiStreamTransport}) drives a raw
 * cURL `WRITEFUNCTION` via a non-blocking `curl_multi` loop; tests bind a fake
 * that replays pre-scripted chunks, so the driver is exercised without a socket.
 */
interface OpenAiStreamTransport
{
    /**
     * Begin a streaming HTTP POST. Returns as soon as the response status line
     * and headers are available — before the body is consumed — so the caller
     * can branch on the status (e.g. retry a 429) before iterating chunks().
     *
     * @param  list<string>  $headers  cURL-style "Name: value" request headers
     * @param  int  $connectTimeout  seconds to wait for the connection to open
     * @param  int  $timeout  seconds for the whole request, streaming included
     *
     * @throws \RuntimeException when no HTTP response can be obtained at all
     *                           (DNS/connect failure, connection timeout)
     */
    public function post(
        string $url,
        array $headers,
        string $body,
        int $connectTimeout,
        int $timeout,
    ): OpenAiStreamHandle;
}
