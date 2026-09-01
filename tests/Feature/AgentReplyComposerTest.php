<?php

use App\Services\Agent\AgentReplyComposer;

/**
 * The agent cites per claim, which lets the provisions panel be the articles the
 * answer actually leans on — and lets an article the answer names but never read
 * be spotted, which is the one groundedness check the pipeline cannot make (its
 * prompt asks for no citations at all).
 */
function composed(array $overrides = []): array
{
    $done = array_merge([
        'type' => 'done',
        'runId' => 'r1',
        'outcome' => 'answered',
        'model' => 'gemini-flash-lite-latest',
        'answer' => 'Испратнина (ZAKON-ZA-RABOTNI, чл. 97 ст. 1). Основ (ZAKON-ZA-RABOTNI, чл. 96 ст. 1).',
        'totals' => ['apiCalls' => 4, 'totalTokens' => 25397],
        'passages' => [
            ['id' => '1', 'source' => 'ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)', 'text' => 'Испратнина…', 'score' => 0.0164],
            ['id' => '2', 'source' => 'ZAKON-ZA-RABOTNI, чл. 96', 'text' => 'Деловни причини…', 'score' => 0.0161],
        ],
        'steps' => [['index' => 0, 'calls' => [['tool' => 'search_law', 'query' => 'испратнина']]]],
    ], $overrides);

    return app(AgentReplyComposer::class)->compose($done, 7565);
}

it('shows the cited provisions in the order the answer cites them', function (): void {
    $payload = composed();

    expect(array_column($payload['metadata']['top_chunks'], 'source'))
        ->toBe(['ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)', 'ZAKON-ZA-RABOTNI, чл. 96']);
});

it('matches a citation to its passage even when the passage covers a range of paragraphs', function (): void {
    // The answer cites "чл. 97 ст. 1"; the passage is "чл. 97 ст. (1)-(5)". The
    // article is the join key, because a passage spans paragraphs.
    $payload = composed(['answer' => 'Само ова (ZAKON-ZA-RABOTNI, чл. 97 ст. 4).']);

    expect(array_column($payload['metadata']['top_chunks'], 'source'))
        ->toBe(['ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)']);
});

it('records an article the answer names that no retrieved passage backs', function (): void {
    $payload = composed([
        'answer' => 'Испратнина (ZAKON-ZA-RABOTNI, чл. 97 ст. 1), а рокот е друг (ZAKON-ZA-JAVNITE, чл. 149).',
    ]);

    expect($payload['metadata']['uncited_articles'])->toBe(['ZAKON-ZA-JAVNITE, чл. 149'])
        ->and(array_column($payload['metadata']['top_chunks'], 'source'))
        ->toBe(['ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)']);
});

it('falls back to the best-scoring passages when the answer cites nothing resolvable', function (): void {
    $payload = composed(['answer' => 'Не пронајдов одговор во корпусот.']);

    expect(array_column($payload['metadata']['top_chunks'], 'source'))
        ->toBe(['ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)', 'ZAKON-ZA-RABOTNI, чл. 96']);
});

it('marks the answer as the agent\'s and carries its cost', function (): void {
    $payload = composed();

    expect($payload['metadata']['agent'])->toBeTrue()
        ->and($payload['metadata']['run_id'])->toBe('r1')
        ->and($payload['metadata']['agent_tokens'])->toBe(25397)
        ->and($payload['metadata']['agent_api_calls'])->toBe(4)
        ->and($payload['tokens'])->toBe(25397)
        ->and($payload['metadata']['total_ms'])->toBe(7565);
});

it('keeps the searches on the message so they survive a reload', function (): void {
    expect(composed()['metadata']['searches'])
        ->toBe([['tool' => 'search_law', 'query' => 'испратнина']]);
});

it('treats a spent step budget as a refusal, so the answer is not offered as findings', function (): void {
    $payload = composed(['outcome' => 'exhausted', 'answer' => 'Не пронајдов одговор во корпусот по 8 пребарувања.']);

    expect($payload['metadata']['refused'])->toBeTrue()
        ->and($payload['metadata']['outcome'])->toBe('exhausted');
});
