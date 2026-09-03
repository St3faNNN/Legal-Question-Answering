<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\MetricsService;

/**
 * The metrics themselves. The thing under test is not "does it produce a number"
 * but "does it refuse to produce a number it cannot stand behind" — the message
 * metadata schema grew field by field, so most of these assertions are about
 * denominators and absent keys.
 */
function conversationWith(array $answers): Conversation
{
    $conversation = Conversation::factory()->for(User::factory())->create();

    foreach ($answers as $answer) {
        $conversation->messages()->create(['role' => 'user', 'content' => 'Прашање?']);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Одговор.',
            'model' => $answer['model'] ?? 'gemini-flash-lite-latest',
            'tokens' => $answer['tokens'] ?? null,
            'latency_ms' => $answer['latency_ms'] ?? 100,
            'metadata' => $answer['metadata'] ?? [],
        ]);
    }

    return $conversation;
}

it('never counts an absent flag as false', function (): void {
    // Three answers: one refused, one explicitly not refused, one from before the
    // flag existed. Only the middle one is a "pipeline" answer we can vouch for.
    conversationWith([
        ['metadata' => ['refused' => true]],
        ['metadata' => ['refused' => false, 'chunk_count' => 10]],
        ['metadata' => ['entities' => [], 'triple_count' => 0]],   // pre-flag era
    ]);

    $paths = app(MetricsService::class)->overview()['volume']['by_path'];

    expect($paths['refused'])->toBe(1)
        ->and($paths['pipeline'])->toBe(1)
        ->and($paths['unclassified'])->toBe(1)
        ->and($paths['gated'])->toBe(0)
        ->and($paths['escalated'])->toBe(0);
});

it('reports the sample a latency distribution was computed from', function (): void {
    // Four answers, two carrying ttft_ms. A p50 over half the rows must not read
    // as a p50 over all of them.
    conversationWith([
        ['metadata' => ['ttft_ms' => 1000, 'chunk_count' => 10]],
        ['metadata' => ['ttft_ms' => 3000, 'chunk_count' => 10]],
        ['metadata' => ['chunk_count' => 10]],
        ['metadata' => ['chunk_count' => 10]],
    ]);

    $ttft = app(MetricsService::class)->overview()['latency']['ttft_ms'];

    expect($ttft['available'])->toBeTrue()
        ->and($ttft['n'])->toBe(2)
        ->and($ttft['out_of'])->toBe(4)
        ->and($ttft['coverage'])->toBe(0.5)
        ->and($ttft['p50'])->toBe(1000)
        ->and($ttft['max'])->toBe(3000);
});

it('marks a distribution unavailable rather than returning zeroes', function (): void {
    conversationWith([['metadata' => ['chunk_count' => 10]]]);

    $retrieval = app(MetricsService::class)->overview()['latency']['retrieval_ms'];

    expect($retrieval['available'])->toBeFalse()
        ->and($retrieval['n'])->toBe(0)
        ->and($retrieval)->not->toHaveKey('p50');
});

it('reports pipeline token cost as unavailable rather than estimating it', function (): void {
    // Legacy rows DO carry token counts, from the retired local-model era. They
    // must not be presented as the current pipeline's cost.
    conversationWith([
        ['model' => 'lamini-flan-t5-248m', 'tokens' => 29, 'metadata' => ['chunk_count' => 5]],
        ['model' => 'gemini-flash-lite-latest', 'tokens' => 0, 'metadata' => ['chunk_count' => 10]],
    ]);

    $tokens = app(MetricsService::class)->overview()['tokens'];

    expect($tokens['pipeline']['available'])->toBeFalse()
        ->and($tokens['pipeline']['reason'])->toContain('no usage block')
        // The legacy rows are still counted, so the claim is auditable.
        ->and($tokens['pipeline']['legacy_rows_with_tokens'])->toBe(1);
});

it('reports escalation token cost exactly, from the persisted runs', function (): void {
    $conversation = conversationWith([['metadata' => ['agent' => true, 'agent_tokens' => 8964]]]);
    /** @var Message $message */
    $message = $conversation->messages()->where('role', 'assistant')->first();
    $message->agentRun()->create([
        'run_id' => 'r1', 'question' => 'Q', 'outcome' => 'answered', 'model' => 'm',
        'steps_count' => 2, 'api_calls' => 2, 'total_tokens' => 8964, 'duration_ms' => 12137,
        'steps' => [['index' => 0, 'calls' => [['tool' => 'search_law', 'query' => 'x', 'sources' => []]]]],
        'passages' => [],
    ]);

    $overview = app(MetricsService::class)->overview();

    expect($overview['tokens']['escalation']['available'])->toBeTrue()
        ->and($overview['tokens']['escalation']['total'])->toBe(8964)
        ->and($overview['agent']['runs'])->toBe(1)
        ->and($overview['agent']['by_outcome'])->toBe(['answered' => 1])
        ->and($overview['agent']['searches_per_run']['mean'])->toBe(1.0);
});

it('counts questions and answers separately, because an escalation adds an answer without a question', function (): void {
    $conversation = Conversation::factory()->for(User::factory())->create();
    $conversation->messages()->create(['role' => 'user', 'content' => 'Прашање?']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'A', 'metadata' => ['chunk_count' => 10]]);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'B', 'metadata' => ['agent' => true]]);

    $volume = app(MetricsService::class)->overview()['volume'];

    expect($volume['questions'])->toBe(1)
        ->and($volume['answers'])->toBe(2)
        ->and($volume['by_path']['escalated'])->toBe(1);
});

it('excludes rows outside the configured window', function (): void {
    config(['admin.window_days' => 7]);

    $conversation = conversationWith([['metadata' => ['chunk_count' => 10]]]);
    // created_at is not fillable on Message, so it is set after the insert rather
    // than mass-assigned — otherwise the row silently lands inside the window and
    // the test passes for the wrong reason.
    $old = $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Old.',
        'metadata' => ['chunk_count' => 10],
    ]);
    $old->forceFill(['created_at' => now()->subDays(30)])->save();

    expect(app(MetricsService::class)->overview()['volume']['answers'])->toBe(1);
});

it('scopes the refusal rate to the rows that carry the flag', function (): void {
    conversationWith([
        ['metadata' => ['refused' => true]],
        ['metadata' => ['refused' => false, 'chunk_count' => 10]],
        ['metadata' => ['entities' => []]],   // no flag: outside the denominator
    ]);

    $refusals = app(MetricsService::class)->overview()['failures']['refusals'];

    expect($refusals['count'])->toBe(1)
        ->and($refusals['out_of'])->toBe(2);
});
