<?php

use App\Exceptions\AiServiceException;
use App\Grpc\GenerationClient;
use App\Grpc\OpenAiCompatibleGenerationClient;
use App\Grpc\OpenAiSseParser;
use App\Grpc\OpenAiStreamHandle;
use App\Grpc\OpenAiStreamTransport;
use App\Services\Logging\AiLogger;
use Tests\Support\FakeOpenAiStreamTransport;
use Tests\Support\InertAiClients;

function openAiDriver(?OpenAiStreamTransport $transport = null): OpenAiCompatibleGenerationClient
{
    return new OpenAiCompatibleGenerationClient(
        baseUrl: 'https://llm.test/v1/',
        apiKey: 'test-key',
        model: 'gemini-2.5-flash-lite',
        maxTokens: 1024,
        timeout: 60,
        promptPath: base_path('prompts/legal_mk.txt'),
        logger: new AiLogger,
        transport: $transport ?? new FakeOpenAiStreamTransport(['status' => 200, 'chunks' => []]),
    );
}

/** One OpenAI-style SSE `data:` line for a single content delta. */
function sseData(string $delta): string
{
    return 'data: '.json_encode(['choices' => [['delta' => ['content' => $delta]]]])."\n\n";
}

/** A whole SSE body built from content deltas, terminated by [DONE]. */
function sseBody(array $deltas, bool $done = true): string
{
    $body = implode('', array_map('sseData', $deltas));

    return $done ? $body."data: [DONE]\n\n" : $body;
}

/** @return list<array{text: string, done: bool, tokens: int, model: string}> */
function drain(iterable $stream): array
{
    return iterator_to_array($stream, false);
}

describe('OpenAiSseParser', function (): void {
    it('extracts content deltas from complete data lines', function (): void {
        $result = OpenAiSseParser::parse(sseBody(['Работно ', 'време'], done: false));

        expect($result['deltas'])->toBe(['Работно ', 'време'])
            ->and($result['done'])->toBeFalse();
    });

    it('reassembles a data line split across two reads', function (): void {
        $first = OpenAiSseParser::parse('data: {"choices":[{"delta":{"con');
        expect($first['deltas'])->toBe([]); // incomplete line — nothing emitted yet

        $second = OpenAiSseParser::parse("tent\":\"hi\"}}]}\n", $first['remainder']);
        expect($second['deltas'])->toBe(['hi']);
    });

    it('reports the terminating [DONE] sentinel', function (): void {
        $result = OpenAiSseParser::parse("data: [DONE]\n");

        expect($result['done'])->toBeTrue()
            ->and($result['deltas'])->toBe([]);
    });

    it('drops a malformed json line without dropping the valid ones', function (): void {
        $result = OpenAiSseParser::parse("data: not-json\ndata: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n");

        expect($result['deltas'])->toBe(['ok']);
    });

    it('yields nothing for an empty delta', function (): void {
        $result = OpenAiSseParser::parse("data: {\"choices\":[{\"delta\":{}}]}\n");

        expect($result['deltas'])->toBe([]);
    });
});

describe('OpenAiCompatibleGenerationClient', function (): void {
    it('posts to /chat/completions with stream:true and the shared system prompt', function (): void {
        $transport = new FakeOpenAiStreamTransport(['status' => 200, 'chunks' => [sseBody(['Одговор.'])]]);

        drain(openAiDriver($transport)->generateStream('Колку трае одморот?', '[ZAKON-A, чл. 2]', null, []));

        $expectedSystem = rtrim(file_get_contents(base_path('prompts/legal_mk.txt')), "\n");

        expect($transport->callCount())->toBe(1);

        $request = $transport->requests[0];
        expect($request['url'])->toEndWith('/chat/completions')
            ->and($request['headers'])->toContain('Authorization: Bearer test-key')
            ->and($request['headers'])->toContain('Accept: text/event-stream');

        $payload = $transport->lastPayload();
        expect($payload['stream'])->toBeTrue()
            ->and($payload['model'])->toBe('gemini-2.5-flash-lite')
            ->and($payload['messages'][0])->toBe(['role' => 'system', 'content' => $expectedSystem])
            ->and($payload['messages'][1]['content'])->toContain('Извори:')
            ->and($payload['messages'][1]['content'])->toContain('[ZAKON-A, чл. 2]')
            ->and($payload['messages'][1]['content'])->toContain('Прашање: Колку трае одморот?');
    });

    it('streams chunks in order and ends with a terminal done chunk', function (): void {
        $transport = new FakeOpenAiStreamTransport([
            'status' => 200,
            'chunks' => [sseBody(['Работно ', 'време ', 'и распоред.'])],
        ]);

        $chunks = drain(openAiDriver($transport)->generateStream('Прашање?', 'контекст', null, []));

        $text = collect($chunks)->reject(fn (array $c): bool => $c['done'])->pluck('text')->implode('');
        $last = end($chunks);

        expect($text)->toBe('Работно време и распоред.')
            ->and($last['done'])->toBeTrue()
            ->and($last['model'])->toBe('gemini-2.5-flash-lite');
    });

    it('yields each delta as its chunk arrives rather than batching them at the end', function (): void {
        // One complete SSE line per transport chunk. If the driver drained the
        // transport before yielding — the Guzzle read-loop bug this rewrite fixes
        // — the first delta would only surface after every chunk was consumed.
        $transport = new FakeOpenAiStreamTransport(['status' => 200, 'chunks' => [
            sseData('А'),
            sseData('Б'),
            sseData('В'),
            "data: [DONE]\n\n",
        ]]);

        $stream = openAiDriver($transport)->generateStream('Прашање?', 'контекст', null, []);

        // Advancing to the first token opens the transport and must pull exactly
        // one raw chunk — proof the driver interleaves reads with yields.
        expect($stream->current())->toMatchArray(['text' => 'А']);
        $handle = $transport->requests[0]['handle'];
        expect($handle->chunksProduced)->toBe(1);

        $stream->next();
        expect($stream->current())->toMatchArray(['text' => 'Б']);
        expect($handle->chunksProduced)->toBe(2);

        $stream->next();
        expect($stream->current())->toMatchArray(['text' => 'В']);
        expect($handle->chunksProduced)->toBe(3);

        $stream->next();
        expect($stream->current()['done'])->toBeTrue();
    });

    it('omits conversation history from the request', function (): void {
        $transport = new FakeOpenAiStreamTransport(['status' => 200, 'chunks' => [sseBody(['x'])]]);

        $history = [['role' => 'user', 'content' => 'претходно прашање']];
        drain(openAiDriver($transport)->generateStream('ново', 'контекст', null, $history));

        $payload = $transport->lastPayload();

        // Exactly system + one user message; nothing carried from history.
        expect($payload['messages'])->toHaveCount(2);
        foreach ($payload['messages'] as $message) {
            expect($message['content'])->not->toContain('претходно прашање');
        }
    });

    it('retries once on 429 and then succeeds', function (): void {
        $transport = new FakeOpenAiStreamTransport(
            ['status' => 429, 'headers' => ['retry-after' => '0']],
            ['status' => 200, 'chunks' => [sseBody(['ok'])]],
        );

        $chunks = drain(openAiDriver($transport)->generateStream('Прашање?', 'контекст', null, []));

        expect(collect($chunks)->reject(fn (array $c): bool => $c['done'])->pluck('text')->implode(''))->toBe('ok');
        expect($transport->callCount())->toBe(2);
    });

    it('fails after a persistent 429', function (): void {
        $transport = new FakeOpenAiStreamTransport(['status' => 429, 'headers' => ['retry-after' => '0']]);

        expect(fn () => drain(openAiDriver($transport)->generateStream('Прашање?', 'контекст', null, [])))
            ->toThrow(AiServiceException::class);

        expect($transport->callCount())->toBe(2); // original + one retry, then give up
    });

    it('raises AiServiceException on a 5xx so the job can emit a terminal error', function (): void {
        $transport = new FakeOpenAiStreamTransport(['status' => 502, 'chunks' => []]);

        expect(fn () => drain(openAiDriver($transport)->generateStream('Прашање?', 'контекст', null, [])))
            ->toThrow(AiServiceException::class);
    });

    it('raises AiServiceException when the transport connection fails', function (): void {
        $transport = new class implements OpenAiStreamTransport
        {
            public function post(string $url, array $headers, string $body, int $connectTimeout, int $timeout): OpenAiStreamHandle
            {
                throw new RuntimeException('connection refused');
            }
        };

        expect(fn () => drain(openAiDriver($transport)->generateStream('Прашање?', 'контекст', null, [])))
            ->toThrow(AiServiceException::class);
    });
});

describe('generation client binding', function (): void {
    it('resolves openai_compatible to the OpenAI-compatible client', function (): void {
        // Drop the suite-wide stand-in: this asserts the real container binding.
        InertAiClients::forgetGeneration();

        config([
            'ai.generator' => 'openai_compatible',
            'ai.llm.base_url' => 'https://llm.test/v1/',
            'ai.llm.prompt_path' => base_path('prompts/legal_mk.txt'),
        ]);

        expect(app(GenerationClient::class))->toBeInstanceOf(OpenAiCompatibleGenerationClient::class);
    });
});
