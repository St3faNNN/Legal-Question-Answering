<?php

namespace App\Grpc;

/**
 * Pure, incremental parser for an OpenAI-compatible chat-completions SSE stream.
 *
 * Isolated from the HTTP driver ({@see OpenAiCompatibleGenerationClient}) because
 * SSE reassembly is the fiddly, bug-prone part — a single socket read can split
 * a `data:` line in half or deliver several at once — and it is the one piece
 * cleanly unit-testable without a live endpoint.
 *
 * Feed each raw byte chunk together with the leftover from the previous call;
 * get back the content deltas found in the *complete* lines, whether the
 * terminating `data: [DONE]` was seen, and the still-incomplete trailing bytes
 * to carry into the next read.
 */
final class OpenAiSseParser
{
    /**
     * @return array{deltas: list<string>, done: bool, remainder: string}
     */
    public static function parse(string $chunk, string $carry = ''): array
    {
        $buffer = $carry.$chunk;

        // Only newline-terminated lines are complete; the trailing partial (if
        // any) is returned as the remainder to prepend to the next read.
        $lastNewline = strrpos($buffer, "\n");
        if ($lastNewline === false) {
            return ['deltas' => [], 'done' => false, 'remainder' => $buffer];
        }

        $complete = substr($buffer, 0, $lastNewline);
        $remainder = substr($buffer, $lastNewline + 1);

        $deltas = [];
        $done = false;

        foreach (preg_split('/\r?\n/', $complete) as $line) {
            $line = trim($line);

            // Skip blank lines, SSE comments (": ..."), and non-data fields.
            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5)); // drop the leading "data:"

            if ($payload === '[DONE]') {
                $done = true;

                continue;
            }

            $decoded = json_decode($payload, true);

            // A malformed data line is dropped, not fatal: a lost token beats a
            // crashed stream that leaves the browser spinner hanging forever.
            if (! is_array($decoded)) {
                continue;
            }

            $delta = $decoded['choices'][0]['delta']['content'] ?? null;
            if (is_string($delta) && $delta !== '') {
                $deltas[] = $delta;
            }
        }

        return ['deltas' => $deltas, 'done' => $done, 'remainder' => $remainder];
    }
}
