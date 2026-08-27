<?php

use App\Grpc\GenerationClient;
use App\Grpc\RetrievalClient;
use App\Models\Conversation;
use App\Services\Chat\GraphRagGrpcReplyGenerator;
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

it('answers gated input with no retrieval and no generation call', function (): void {
    app()->instance(RetrievalClient::class, new class implements RetrievalClient
    {
        public function retrieve(string $question): array
        {
            throw new RuntimeException('retrieval must not run for gated input');
        }

        public function references(string $law, string $article): array
        {
            throw new RuntimeException('references must not run for gated input');
        }
    });
    app()->instance(GenerationClient::class, new class implements GenerationClient
    {
        public function generate(string $question, string $context, ?string $entity, array $history): array
        {
            throw new RuntimeException('generation must not run for gated input');
        }

        public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
        {
            throw new RuntimeException('generation must not run for gated input');
        }
    });

    $conversation = Conversation::factory()->create();

    $result = app(GraphRagGrpcReplyGenerator::class)->reply($conversation, 'Како си?');

    expect($result['metadata']['gated'])->toBeTrue()
        ->and($result['metadata']['top_chunks'])->toBe([])
        ->and($result['model'])->toBeNull()
        ->and($result['answer'])->toContain('правни прашања')
        ->and($result['answer'])->toContain('legal questions');
});
