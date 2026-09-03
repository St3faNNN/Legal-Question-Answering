<?php

use App\Services\Chat\NonLegalInputGate;

function gate(): NonLegalInputGate
{
    return new NonLegalInputGate;
}

// --- Inputs that MUST gate (non-legal) --------------------------------------

it('gates non-legal input', function (string $input): void {
    expect(gate()->shouldGate($input))->toBeTrue();
})->with([
    // the three from the reported session
    'Kako si',
    'Kako si',
    'Дали имаш нешто ново?',
    // other greetings / thanks / small talk / assistant questions
    'Здраво',
    'Како си?',
    'Добро утро',
    'Благодарам',
    'Фала',
    'thanks',
    'Кој си ти?',
    'Што можеш ти?',
    'Чао',
    // empty / near-empty / punctuation- or emoji-only
    '',
    '   ',
    '?!',
    '...',
    '👋',
    'ок',
]);

// --- Inputs that MUST NOT gate (real / terse legal queries) ------------------

it('never gates a terse real legal query', function (string $input): void {
    expect(gate()->shouldGate($input))->toBeFalse();
})->with([
    'рок за жалба',
    'чл. 132',
    'член 55',
    'годишен одмор',
    'жалба',
    'отказ',
    'испратнина',
    'закон за јавни набавки',
    // a legal question about an uncovered domain — the generation prompt handles it
    'Кои се условите за стекнување на офицерски чин во Армијата на Република Северна Македонија?',
]);

// --- The whole lawyer-authored eval set: ZERO false positives ---------------

it('never gates a real question from the eval set', function (): void {
    $yaml = file_get_contents(base_path('tests/eval/questions.yaml'));
    preg_match_all('/^\s+question:\s*"(.+)"\s*$/mu', (string) $yaml, $matches);
    $questions = $matches[1];

    expect($questions)->not->toBeEmpty();

    $blocked = array_values(array_filter($questions, fn (string $q): bool => gate()->shouldGate($q)));

    expect($blocked)->toBe([]); // every eval question must reach retrieval
});

// --- The gate short-circuits the pipeline: no retrieval, no generation -------

it('answers gated input from a canned bilingual payload with no model', function (): void {
    // The payload itself. That it reaches the browser with no retrieval and no
    // generation call is asserted on the streaming job, the only path that
    // consumes it — see StreamChatReplyTest, 'gates non-legal input in the
    // stream with no retrieval or generation'.
    $payload = gate()->gatedPayload();

    expect($payload['metadata']['gated'])->toBeTrue()
        ->and($payload['metadata']['top_chunks'])->toBe([])
        // No model ran, so no model is claimed: a name here would land in the
        // dashboard's by-model table as though something generated the answer.
        ->and($payload['model'])->toBeNull()
        ->and($payload['answer'])->toContain('правни прашања')
        ->and($payload['answer'])->toContain('legal questions');
});
