<?php

use App\Exceptions\AiServiceException;
use App\Services\Agent\HttpAgentClient;
use App\Services\Agent\NdjsonParser;
use Tests\Support\FakeOpenAiStreamTransport;

/**
 * The PHP-to-TypeScript bridge. The agent stays TypeScript and runs as its own
 * process; this client reads its NDJSON stream. The transport seam is the one the
 * OpenAI-compatible driver already uses, so these tests drive the real client
 * without a socket — and they exercise the case that actually happens on a wire:
 * a JSON object split across two reads.
 */
function agentClient(FakeOpenAiStreamTransport $transport): HttpAgentClient
{
    return new HttpAgentClient(
        baseUrl: 'http://agent:8002',
        token: 'test-secret',
        timeout: 120,
        maxSteps: 8,
        transport: $transport,
    );
}

describe('HttpAgentClient', function (): void {
    it('yields progress as it arrives and posts the question to /run', function (): void {
        $transport = new FakeOpenAiStreamTransport([
            'status' => 200,
            'chunks' => [
                '{"type":"search","step":0,"tool":"search_law","query":"технолошки вишок"}'."\n",
                '{"type":"result","step":0,"tool":"search_law","sources":["ZAKON-ZA-RABOTNI, чл. 97"],"error":null}'."\n",
                '{"type":"done","runId":"r1","outcome":"answered","answer":"Одговор.","totals":{"totalTokens":25397},"passages":[],"steps":[]}'."\n",
            ],
        ]);

        $events = iterator_to_array(agentClient($transport)->run('Кои се основите за отказ?'), false);

        expect(array_column($events, 'type'))->toBe(['search', 'result', 'done'])
            ->and($events[0]['query'])->toBe('технолошки вишок')
            ->and($events[2]['totals']['totalTokens'])->toBe(25397);

        $request = $transport->requests[0];

        expect($request['url'])->toBe('http://agent:8002/run')
            ->and($request['headers'])->toContain('Authorization: Bearer test-secret')
            ->and(json_decode($request['body'], true))
            ->toBe(['question' => 'Кои се основите за отказ?', 'maxSteps' => 8]);
    });

    it('reassembles an event split across two reads', function (): void {
        $transport = new FakeOpenAiStreamTransport([
            'status' => 200,
            'chunks' => [
                '{"type":"search","step":0,"tool":"search_l',
                'aw","query":"испратнина"}'."\n".'{"type":"done","runId":"r2","outcome":"answered","answer":"A.","totals":{},"passages":[],"steps":[]}'."\n",
            ],
        ]);

        $events = iterator_to_array(agentClient($transport)->run('Прашање?'), false);

        expect(array_column($events, 'type'))->toBe(['search', 'done'])
            ->and($events[0]['query'])->toBe('испратнина');
    });

    it('emits a final event the stream ended without a trailing newline on', function (): void {
        $transport = new FakeOpenAiStreamTransport([
            'status' => 200,
            'chunks' => ['{"type":"done","runId":"r3","outcome":"answered","answer":"A.","totals":{},"passages":[],"steps":[]}'],
        ]);

        $events = iterator_to_array(agentClient($transport)->run('Прашање?'), false);

        expect(array_column($events, 'type'))->toBe(['done']);
    });

    it('raises the run\'s own error event as a service failure', function (): void {
        $transport = new FakeOpenAiStreamTransport([
            'status' => 200,
            'chunks' => ['{"type":"error","message":"LLM HTTP 429"}'."\n"],
        ]);

        expect(fn () => iterator_to_array(agentClient($transport)->run('Прашање?'), false))
            ->toThrow(AiServiceException::class, 'LLM HTTP 429');
    });

    it('refuses a stream that ends without a final answer, so nothing partial is persisted', function (): void {
        $transport = new FakeOpenAiStreamTransport([
            'status' => 200,
            'chunks' => ['{"type":"search","step":0,"tool":"search_law","query":"нешто"}'."\n"],
        ]);

        expect(fn () => iterator_to_array(agentClient($transport)->run('Прашање?'), false))
            ->toThrow(AiServiceException::class, 'ended without a final answer');
    });

    it('surfaces a non-200 from the service as a service failure', function (): void {
        $transport = new FakeOpenAiStreamTransport(['status' => 401, 'chunks' => []]);

        expect(fn () => iterator_to_array(agentClient($transport)->run('Прашање?'), false))
            ->toThrow(AiServiceException::class, 'HTTP 401');
    });
});

describe('NdjsonParser', function (): void {
    it('returns only complete lines and carries the partial one forward', function (): void {
        $first = NdjsonParser::parse('{"type":"a"}'."\n".'{"type":"b"');

        expect($first['events'])->toBe([['type' => 'a']])
            ->and($first['remainder'])->toBe('{"type":"b"');

        $second = NdjsonParser::parse('}'."\n", $first['remainder']);

        expect($second['events'])->toBe([['type' => 'b']])
            ->and($second['remainder'])->toBe('');
    });

    it('drops a malformed line rather than aborting a run already paid for', function (): void {
        $parsed = NdjsonParser::parse('not json'."\n".'{"no_type":1}'."\n".'{"type":"done"}'."\n");

        expect($parsed['events'])->toBe([['type' => 'done']]);
    });
});
