<?php

namespace Tests\Support;

use App\Grpc\OpenAiStreamHandle;

/**
 * In-memory {@see OpenAiStreamHandle} for the OpenAI-compatible driver tests.
 *
 * Replays a scripted list of raw byte chunks, one per {@see self::chunks()}
 * iteration, so a test controls exactly how the SSE body is split across
 * "reads". {@see self::$chunksProduced} counts how many chunks have actually
 * been pulled, which lets a test assert the driver consumes the transport
 * lazily — one delta per chunk — rather than draining it all up front.
 */
final class FakeOpenAiStreamHandle implements OpenAiStreamHandle
{
    /** Number of chunks pulled so far — evidence of incremental consumption. */
    public int $chunksProduced = 0;

    public bool $closed = false;

    /**
     * @param  array<string, string>  $headers  lower-cased header name => value
     * @param  list<string>  $chunks  raw body byte chunks, in arrival order
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly array $chunks,
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function chunks(): iterable
    {
        foreach ($this->chunks as $chunk) {
            $this->chunksProduced++;

            yield $chunk;
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
