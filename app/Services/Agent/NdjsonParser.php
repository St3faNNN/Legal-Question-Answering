<?php

namespace App\Services\Agent;

use App\Grpc\OpenAiSseParser;

/**
 * Pure, incremental parser for a newline-delimited JSON stream.
 *
 * The same shape as {@see OpenAiSseParser} and for the same reason:
 * reassembly is the bug-prone part — one socket read can split a line in half or
 * deliver five at once — and it is the piece worth unit-testing away from a live
 * endpoint.
 *
 * Feed each raw byte chunk with the leftover from the previous call; get back the
 * decoded objects found in the *complete* lines plus the still-incomplete
 * trailing bytes to carry into the next read.
 */
final class NdjsonParser
{
    /**
     * @return array{events: list<array<string, mixed>>, remainder: string}
     */
    public static function parse(string $chunk, string $carry = ''): array
    {
        $buffer = $carry.$chunk;

        $lastNewline = strrpos($buffer, "\n");
        if ($lastNewline === false) {
            return ['events' => [], 'remainder' => $buffer];
        }

        $complete = substr($buffer, 0, $lastNewline);
        $remainder = substr($buffer, $lastNewline + 1);

        $events = [];

        foreach (preg_split('/\r?\n/', $complete) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            // A malformed line is dropped rather than fatal — one lost progress
            // line beats an aborted run the user already paid tokens for. The
            // terminal `done` is what the caller checks for, and its absence is
            // reported by the client, so a dropped line cannot pass as success.
            if (is_array($decoded) && isset($decoded['type']) && is_string($decoded['type'])) {
                $events[] = $decoded;
            }
        }

        return ['events' => $events, 'remainder' => $remainder];
    }
}
