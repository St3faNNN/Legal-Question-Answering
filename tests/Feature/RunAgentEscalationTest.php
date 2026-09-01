<?php

use App\Contracts\RunsLegalResearch;
use App\Exceptions\AiServiceException;
use App\Jobs\RunAgentEscalation;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Agent\AgentReplyComposer;
use App\Services\Chat\NonLegalInputGate;
use App\Services\Logging\AiLogger;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeAgentClient;

/**
 * The escalation job. It publishes onto the same Redis stream the pipeline uses,
 * so the browser's existing SSE relay carries it — what differs is the payload:
 * one `agent_search` status per query as it is issued (there are no token deltas
 * to show; the agent's model calls are non-streaming by construction), then the
 * finished answer as a single chunk.
 */
function captureAgentStream(): ArrayObject
{
    // An ArrayObject rather than an array: the collector is handed back to the
    // test, and a by-value array copy would never see the job's appends.
    $published = new ArrayObject;

    Redis::shouldReceive('rpush')->andReturnUsing(function (string $key, string $json) use ($published): int {
        $published->append(json_decode($json, true));

        return 1;
    });
    Redis::shouldReceive('expire')->andReturnTrue();

    return $published;
}

/** The terminal event a successful run of the severance question produces. */
function severanceDoneEvent(): array
{
    return [
        'type' => 'done',
        'runId' => '20260829-124011-gbwlbb',
        'outcome' => 'answered',
        'model' => 'gemini-flash-lite-latest',
        'answer' => 'Отказ од деловни причини постои кога престанува потребата од работата '
            .'(ZAKON-ZA-RABOTNI, чл. 96 ст. 1). Испратнината се движи од една до седум нето плати '
            .'(ZAKON-ZA-RABOTNI, чл. 97 ст. 1).',
        'totals' => ['apiCalls' => 4, 'promptTokens' => 24089, 'completionTokens' => 1308, 'totalTokens' => 25397],
        'passages' => [
            ['id' => '10', 'source' => 'ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)', 'text' => 'Испратнина…', 'score' => 0.0164],
            ['id' => '11', 'source' => 'ZAKON-ZA-RABOTNI, чл. 96', 'text' => 'Деловни причини…', 'score' => 0.0161],
            ['id' => '12', 'source' => 'ZAKON-ZA-AVTORSK, чл. 54', 'text' => 'Неповрзано…', 'score' => 0.0102],
        ],
        'steps' => [
            ['index' => 0, 'calls' => [['tool' => 'search_law', 'query' => 'технолошки вишок отказ испратнина', 'sources' => ['ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)'], 'error' => null]]],
            ['index' => 1, 'calls' => [['tool' => 'search_law', 'query' => 'деловни причини отказ', 'sources' => ['ZAKON-ZA-RABOTNI, чл. 96'], 'error' => null]]],
            ['index' => 2, 'calls' => []],
        ],
    ];
}

function runEscalationJob(int $conversationId, string $streamId, string $question, RunsLegalResearch $agent): RunAgentEscalation
{
    $job = new RunAgentEscalation($conversationId, $streamId, $question, 7, microtime(true));

    $job->handle($agent, app(AgentReplyComposer::class), app(AiLogger::class), app(NonLegalInputGate::class));

    return $job;
}

describe('RunAgentEscalation', function (): void {
    it('publishes each search as it is issued, then the answer', function (): void {
        $published = captureAgentStream();

        $conversation = Conversation::factory()->for(User::factory())->create();
        $agent = new FakeAgentClient([
            ['type' => 'search', 'step' => 0, 'tool' => 'search_law', 'query' => 'технолошки вишок отказ испратнина'],
            ['type' => 'result', 'step' => 0, 'tool' => 'search_law', 'sources' => ['ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)'], 'error' => null],
            ['type' => 'search', 'step' => 1, 'tool' => 'search_law', 'query' => 'деловни причини отказ'],
            ['type' => 'result', 'step' => 1, 'tool' => 'search_law', 'sources' => ['ZAKON-ZA-RABOTNI, чл. 96'], 'error' => null],
            severanceDoneEvent(),
        ]);

        runEscalationJob($conversation->id, 'esc-1', 'Кои се основите за отказ поради технолошки вишок?', $agent);

        expect(collect($published->getArrayCopy())->pluck('type')->all())
            ->toBe(['status', 'status', 'status', 'status', 'status', 'chunk', 'done']);

        $searches = collect($published->getArrayCopy())->where('stage', 'agent_search')->pluck('query')->all();

        // This is the progress the user sees — the queries themselves, in order,
        // not a spinner.
        expect($searches)->toBe(['технолошки вишок отказ испратнина', 'деловни причини отказ'])
            ->and($published->getArrayCopy()[0]['stage'])->toBe('agent_started');
    });

    it('persists the answer with its provisions and an inspectable trace', function (): void {
        captureAgentStream();

        $conversation = Conversation::factory()->for(User::factory())->create();

        runEscalationJob($conversation->id, 'esc-2', 'Кои се основите за отказ?', new FakeAgentClient([severanceDoneEvent()]));

        $message = $conversation->messages()->where('role', 'assistant')->first();

        expect($message)->not->toBeNull()
            ->and($message->metadata['agent'])->toBeTrue()
            ->and($message->metadata['run_id'])->toBe('20260829-124011-gbwlbb')
            ->and($message->metadata['escalated_from_message_id'])->toBe(7)
            ->and($message->tokens)->toBe(25397);

        // The provisions panel is exactly the articles the answer cites, in the
        // order it cites them — чл. 96 first, then чл. 97 — and the unrelated
        // copyright passage the run also saw is not shown as a source.
        expect(array_column($message->metadata['top_chunks'], 'source'))
            ->toBe(['ZAKON-ZA-RABOTNI, чл. 96', 'ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)'])
            ->and($message->metadata['uncited_articles'])->toBe([]);

        $run = $message->agentRun;

        expect($run)->not->toBeNull()
            ->and($run->run_id)->toBe('20260829-124011-gbwlbb')
            ->and($run->api_calls)->toBe(4)
            ->and($run->total_tokens)->toBe(25397)
            ->and($run->steps)->toHaveCount(3)
            ->and($run->passages)->toHaveCount(3);
    });

    it('never lets a greeting reach the agent, even if dispatched directly', function (): void {
        $published = captureAgentStream();

        $conversation = Conversation::factory()->for(User::factory())->create();
        $agent = new FakeAgentClient([severanceDoneEvent()]);

        runEscalationJob($conversation->id, 'esc-gate', 'Здраво', $agent);

        expect($agent->called())->toBeFalse()
            ->and(collect($published->getArrayCopy())->pluck('type')->all())->toBe(['error'])
            ->and($published->getArrayCopy()[0]['reason'])->toBe('gated')
            ->and($conversation->messages()->count())->toBe(0);
    });

    it('skips a stream that already produced an answer rather than paying for it twice', function (): void {
        $published = captureAgentStream();

        $conversation = Conversation::factory()->for(User::factory())->create();
        $conversation->messages()->create([
            'role' => 'assistant',
            'stream_id' => 'esc-dup',
            'content' => 'Веќе одговорено.',
        ]);

        $agent = new FakeAgentClient([severanceDoneEvent()]);
        runEscalationJob($conversation->id, 'esc-dup', 'Кои се основите за отказ?', $agent);

        expect($agent->called())->toBeFalse()
            ->and($published)->toBeEmpty()
            ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1);
    });

    it('persists nothing when the run ends without a final answer', function (): void {
        captureAgentStream();

        $conversation = Conversation::factory()->for(User::factory())->create();

        // Progress, then the stream ends: no `done`, so there is no answer.
        $agent = new FakeAgentClient([
            ['type' => 'search', 'step' => 0, 'tool' => 'search_law', 'query' => 'нешто'],
        ]);

        runEscalationJob($conversation->id, 'esc-noanswer', 'Кој е рокот за жалба?', $agent);

        expect($conversation->messages()->where('role', 'assistant')->count())->toBe(0);
    });

    it('publishes a terminal error from failed() so the browser unblocks', function (): void {
        $published = captureAgentStream();

        $job = new RunAgentEscalation(1, 'esc-fail', 'Кој е рокот?', null, microtime(true));
        $job->failed(AiServiceException::connectionFailed('agent', new RuntimeException('connection refused')));

        expect($published->getArrayCopy())->toHaveCount(1)
            ->and($published->getArrayCopy()[0]['type'])->toBe('error')
            ->and($published->getArrayCopy()[0]['service'])->toBe('agent')
            ->and($published->getArrayCopy()[0]['reason'])->toBe('connection');
    });

    it('does not retry, because a run is billed from its first model call', function (): void {
        $job = new RunAgentEscalation(1, 'esc-cfg', 'Прашање?', null, microtime(true));

        expect($job->tries)->toBe(1);
    });

    it('sizes the job timeout above the service\'s own whole-run deadline', function (): void {
        config(['agent.timeout' => 120]);

        expect((new RunAgentEscalation(1, 'esc-t', 'Прашање?', null, microtime(true)))->timeout)->toBe(140);
    });
});
