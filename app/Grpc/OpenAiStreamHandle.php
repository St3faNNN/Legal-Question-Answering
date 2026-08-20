<?php

namespace App\Grpc;

/**
 * A single in-flight streaming response opened by {@see OpenAiStreamTransport}.
 *
 * The status and headers are ready as soon as the handle is returned; the body
 * is pulled lazily through {@see self::chunks()}, which yields raw bytes as the
 * transport receives them so the driver can emit tokens progressively rather
 * than all at once at the end of the call.
 */
interface OpenAiStreamHandle
{
    /** The HTTP response status code (already available when the handle is returned). */
    public function status(): int;

    /** A response header by (case-insensitive) name, or null when absent. */
    public function header(string $name): ?string;

    /**
     * Pull the response body as it arrives. Each element is a raw byte chunk in
     * receipt order — one element per transport read, not one per line — to be
     * fed to {@see OpenAiSseParser}, which reassembles lines split across reads.
     *
     * @return iterable<string>
     *
     * @throws \RuntimeException on a mid-stream transport failure, so the driver
     *                           surfaces it as the same terminal error a stalled
     *                           connection would.
     */
    public function chunks(): iterable;

    /** Release the underlying transport resources. Safe to call more than once. */
    public function close(): void;
}
