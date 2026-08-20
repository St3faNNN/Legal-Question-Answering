<?php

namespace App\Grpc;

/**
 * Production {@see OpenAiStreamTransport}: a raw cURL streaming POST.
 *
 * Deliberately thin — every interesting decision lives in
 * {@see CurlOpenAiStreamHandle}. Kept as its own class purely so the driver can
 * be constructed with a fake transport in tests (see the OpenAI-compatible
 * generation feature test) without touching a socket. Uses only ext-curl, which
 * is already present; no new dependency.
 */
final class CurlOpenAiStreamTransport implements OpenAiStreamTransport
{
    public function post(
        string $url,
        array $headers,
        string $body,
        int $connectTimeout,
        int $timeout,
    ): OpenAiStreamHandle {
        return new CurlOpenAiStreamHandle($url, $headers, $body, $connectTimeout, $timeout);
    }
}
