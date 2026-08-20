<?php

namespace App\Grpc;

use CurlHandle;
use CurlMultiHandle;
use RuntimeException;

/**
 * A streaming response driven by a raw cURL `WRITEFUNCTION` over a non-blocking
 * `curl_multi` loop.
 *
 * The structural constraint this class exists to solve: a PHP generator cannot
 * `yield` from inside a cURL write callback (the callback is invoked deep in
 * libcurl's C stack during curl_multi_exec). So the callback does the minimum —
 * append received bytes to a buffer — and the generator ({@see self::chunks()})
 * owns the pump loop: it advances curl_multi a step, then yields whatever the
 * callback buffered. That interleaving is what makes tokens surface as they
 * arrive instead of in one batch at the end.
 *
 * The handle is constructed already "past the headers": the constructor pumps
 * the transfer only until the status line is parsed (or the connection fails),
 * so {@see self::status()} and {@see self::header()} are valid immediately and
 * the caller can branch (retry a 429, throw on 5xx) before touching the body.
 *
 * @internal Created by {@see CurlOpenAiStreamTransport}; not resolved by name.
 */
final class CurlOpenAiStreamHandle implements OpenAiStreamHandle
{
    private CurlHandle $handle;

    private CurlMultiHandle $multi;

    /** Bytes received by the write callback but not yet yielded by chunks(). */
    private string $buffer = '';

    /** Lower-cased response header name => value. */
    private array $headers = [];

    private int $responseStatus = 0;

    /** curl_multi_exec's still-running count; > 0 while the body is streaming. */
    private int $running = 0;

    /** The per-handle CURLE_* result once the transfer completes, else null. */
    private ?int $result = null;

    private bool $closed = false;

    /**
     * @param  list<string>  $headers  cURL-style "Name: value" request headers
     */
    public function __construct(
        string $url,
        array $headers,
        string $body,
        int $connectTimeout,
        int $timeout,
    ) {
        $this->handle = curl_init();

        curl_setopt_array($this->handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            // Stream identity-encoded: never advertise gzip, which would let the
            // server buffer whole blocks and defeat the incremental writes this
            // whole class exists to preserve.
            CURLOPT_TCP_NODELAY => true,
            // A small read buffer makes libcurl invoke the write callback more
            // often, so a delta is buffered close to when its bytes land.
            CURLOPT_BUFFERSIZE => 1024,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $handle, string $line): int {
                $this->captureHeader($line);

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (CurlHandle $handle, string $data): int {
                // The one job of the callback: buffer bytes. chunks() drains it.
                $this->buffer .= $data;

                return strlen($data);
            },
        ]);

        $this->multi = curl_multi_init();
        curl_multi_add_handle($this->multi, $this->handle);

        $this->pumpUntilResponse();
    }

    public function status(): int
    {
        return $this->responseStatus;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function chunks(): iterable
    {
        // Bytes may already have been buffered while we pumped for the headers.
        if ($this->buffer !== '') {
            yield $this->takeBuffer();
        }

        while ($this->running > 0) {
            // Block until there is socket activity (or 1s elapses) so the pump
            // loop is not a busy-wait. -1 on some platforms means "no fds yet";
            // a short sleep avoids spinning.
            if (curl_multi_select($this->multi, 1.0) === -1) {
                usleep(1000);
            }

            $this->pump();

            if ($this->buffer !== '') {
                yield $this->takeBuffer();
            }
        }

        // Flush any trailing bytes the final pump collected.
        if ($this->buffer !== '') {
            yield $this->takeBuffer();
        }

        // A transfer that ended with a cURL error (e.g. the stream was severed
        // mid-body, or CURLOPT_TIMEOUT fired) surfaces here so the driver can
        // turn it into the same terminal error a dropped connection produces.
        if ($this->result !== null && $this->result !== CURLE_OK) {
            throw new RuntimeException(
                'generation stream failed: '.curl_strerror($this->result),
            );
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        curl_multi_remove_handle($this->multi, $this->handle);
        curl_close($this->handle);
        curl_multi_close($this->multi);
    }

    /**
     * Advance the transfer until the status code is known, or the connection
     * fails outright. Leaves the transfer mid-body (status known, bytes still
     * arriving) for chunks() to finish.
     *
     * @throws RuntimeException when no HTTP response is ever received
     */
    private function pumpUntilResponse(): void
    {
        do {
            $this->pump();

            $this->responseStatus = (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
            if ($this->responseStatus > 0) {
                return;
            }

            if ($this->running > 0 && curl_multi_select($this->multi, 1.0) === -1) {
                usleep(1000);
            }
        } while ($this->running > 0);

        // The transfer finished without ever producing a status line: a DNS,
        // connect, or connection-timeout failure. Report it so the transport
        // raises a connection error rather than returning a 0-status handle.
        $this->responseStatus = (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
        if ($this->responseStatus === 0) {
            $reason = $this->result !== null && $this->result !== CURLE_OK
                ? curl_strerror($this->result)
                : (curl_error($this->handle) ?: 'no HTTP response from generation endpoint');
            $this->close();

            throw new RuntimeException($reason);
        }
    }

    /** One non-blocking step of the multi handle, recording completion state. */
    private function pump(): void
    {
        $status = curl_multi_exec($this->multi, $this->running);
        if ($status !== CURLM_OK) {
            throw new RuntimeException('curl_multi_exec failed: '.curl_multi_strerror($status));
        }

        while (($message = curl_multi_info_read($this->multi)) !== false) {
            if ($message['msg'] === CURLMSG_DONE) {
                $this->result = $message['result'];
            }
        }
    }

    private function takeBuffer(): string
    {
        $chunk = $this->buffer;
        $this->buffer = '';

        return $chunk;
    }

    /** Parse a raw "Name: value" header line into the lower-cased header map. */
    private function captureHeader(string $line): void
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            return; // status line or the blank header terminator
        }

        $name = strtolower(trim(substr($line, 0, $colon)));
        $this->headers[$name] = trim(substr($line, $colon + 1));
    }
}
