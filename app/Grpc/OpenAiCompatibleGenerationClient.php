<?php

namespace App\Grpc;

use App\Exceptions\AiServiceException;
use App\Jobs\StreamChatReply;
use App\Services\Logging\AiLogger;
use RuntimeException;

/**
 * {@see GenerationClient} backed by any OpenAI-compatible /chat/completions
 * endpoint (first target: Gemini's OpenAI compatibility layer). It streams
 * tokens with the identical {text, done, tokens, model} chunk shape the gRPC
 * client yields, so {@see StreamChatReply} consumes it without knowing
 * which backend it is talking to.
 *
 * The system prompt is read from the shared file the generation service also
 * loads (prompts/legal_mk.txt), so both backends answer with the same
 * instruction. History is intentionally omitted from the prompt (small models
 * copied prior answers; see the generators).
 *
 * Transport: a raw cURL streaming POST ({@see CurlOpenAiStreamTransport}) whose
 * body is pulled chunk-by-chunk as it arrives. This replaced a Guzzle
 * `stream => true` + `$body->read()` loop that coalesced the endpoint's trickled
 * writes into one or two coarse reads, so every token landed at the end of the
 * call (time-to-first-token == total). The parser ({@see OpenAiSseParser}) is
 * reused unchanged; only the byte source moved. ext-curl only — no new
 * dependency. The transport is injectable so tests replay chunks without a
 * socket.
 */
class OpenAiCompatibleGenerationClient implements GenerationClient
{
    private readonly OpenAiStreamTransport $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeout,
        private readonly string $promptPath,
        private readonly AiLogger $logger,
        ?OpenAiStreamTransport $transport = null,
    ) {
        $this->transport = $transport ?? new CurlOpenAiStreamTransport;
    }

    public function generate(string $question, string $context, ?string $entity, array $history): array
    {
        $start = hrtime(true);
        $full = '';
        $model = $this->model;
        $tokens = 0;

        foreach ($this->generateStream($question, $context, $entity, $history) as $chunk) {
            if ($chunk['done']) {
                $model = $chunk['model'] !== '' ? $chunk['model'] : $model;
                $tokens = $chunk['tokens'];

                break;
            }

            $full .= $chunk['text'];
        }

        return [
            'answer' => $full,
            'model' => $model,
            'tokens' => $tokens,
            'latency_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
        ];
    }

    public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
    {
        // $entity and $history are deliberately unused: the chat API has no
        // entity slot, and prior turns made the model copy earlier answers.
        $handle = $this->openStream($question, $context);

        try {
            $carry = '';

            // chunks() yields raw body bytes the instant the transport receives
            // them; each read is parsed and any completed deltas are yielded
            // immediately, so tokens reach the browser progressively.
            foreach ($handle->chunks() as $chunk) {
                if ($chunk === '') {
                    continue;
                }

                $parsed = OpenAiSseParser::parse($chunk, $carry);
                $carry = $parsed['remainder'];

                foreach ($parsed['deltas'] as $delta) {
                    yield ['text' => $delta, 'done' => false, 'tokens' => 0, 'model' => $this->model];
                }

                if ($parsed['done']) {
                    yield $this->doneChunk();

                    return;
                }
            }

            // EOF without an explicit [DONE]: flush any buffered final line, then
            // emit the terminal chunk so the consumer always sees a clean close.
            $final = OpenAiSseParser::parse("\n", $carry);
            foreach ($final['deltas'] as $delta) {
                yield ['text' => $delta, 'done' => false, 'tokens' => 0, 'model' => $this->model];
            }

            yield $this->doneChunk();
        } catch (AiServiceException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            // A mid-stream transport failure surfaced by chunks(). Raised as the
            // same AiServiceException the job turns into a terminal error event,
            // so a severed connection never leaves the browser spinner hanging.
            throw AiServiceException::connectionFailed('generation', $exception);
        } finally {
            $handle->close();
        }
    }

    /**
     * Open the streaming call, retrying once on HTTP 429. Returns a 2xx handle
     * whose body has not yet been read; throws AiServiceException otherwise.
     */
    private function openStream(string $question, string $context): OpenAiStreamHandle
    {
        $payload = json_encode([
            'model' => $this->model,
            'stream' => true,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userMessage($question, $context)],
            ],
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            // Suppress the 100-continue handshake — a needless round trip that
            // only delays the first byte.
            'Expect:',
        ];

        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer '.$this->apiKey;
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $handle = $this->transport->post(
                    $this->endpoint(),
                    $headers,
                    $payload,
                    min(10, $this->timeout),
                    $this->timeout,
                );
            } catch (RuntimeException $exception) {
                $this->logger->error('generation.request_failed', [
                    'provider' => 'openai_compatible',
                    'model' => $this->model,
                    'status' => null,
                    'error' => $exception->getMessage(),
                ]);

                throw AiServiceException::connectionFailed('generation', $exception);
            }

            $status = $handle->status();

            if ($status === 429 && $attempt === 0) {
                $wait = $this->retryAfterSeconds($handle);
                $this->logger->warning('generation.rate_limited', [
                    'provider' => 'openai_compatible',
                    'model' => $this->model,
                    'retry_after_s' => $wait,
                    'request_id' => $this->requestId($handle),
                ]);
                $handle->close();
                sleep($wait);

                continue;
            }

            if ($status >= 400) {
                $this->logger->error('generation.request_failed', [
                    'provider' => 'openai_compatible',
                    'model' => $this->model,
                    'status' => $status,
                    'request_id' => $this->requestId($handle),
                ]);
                $handle->close();

                throw AiServiceException::httpError('generation', $status);
            }

            return $handle;
        }
    }

    /** @return array{text: string, done: bool, tokens: int, model: string} */
    private function doneChunk(): array
    {
        return ['text' => '', 'done' => true, 'tokens' => 0, 'model' => $this->model];
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }

    private function systemPrompt(): string
    {
        $prompt = @file_get_contents($this->promptPath);

        if ($prompt === false) {
            throw AiServiceException::invalidResponse(
                'generation',
                "system prompt file is unreadable: {$this->promptPath}",
            );
        }

        // Match the generation service's loader: strip the file's conventional
        // trailing newline so both backends send the identical system prompt.
        return rtrim($prompt, "\n");
    }

    /**
     * The user turn, mirroring services/generation/app/prompt.py's
     * CHAT_USER_WITH_CONTEXT / CHAT_USER_NO_CONTEXT byte-for-byte.
     */
    private function userMessage(string $question, string $context): string
    {
        if (trim($context) === '') {
            return "Прашање: {$question}\n\n(Нема пронајдени извори во документите.)";
        }

        return "Извори:\n{$context}\n\nПрашање: {$question}";
    }

    private function retryAfterSeconds(OpenAiStreamHandle $handle): int
    {
        $header = $handle->header('Retry-After');
        $seconds = is_numeric($header) ? (int) $header : 1;

        // Bound the block: the worker must not sit on a single retry longer than
        // a person would wait, and never longer than the request timeout.
        return max(0, min($seconds, 30, $this->timeout));
    }

    private function requestId(OpenAiStreamHandle $handle): ?string
    {
        return $handle->header('x-request-id')
            ?: ($handle->header('x-goog-request-id') ?: null);
    }
}
