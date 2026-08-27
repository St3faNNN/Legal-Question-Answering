<?php

use App\Exceptions\AiServiceException;
use App\Grpc\RetrievalClient;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * Bind a fake RetrievalClient whose references() delegates to $handler. The
 * search path is stubbed empty — this endpoint only exercises references().
 */
function bindReferencesRetrieval(Closure $handler): void
{
    app()->instance(RetrievalClient::class, new class($handler) implements RetrievalClient
    {
        public function __construct(private readonly Closure $handler) {}

        public function retrieve(string $question): array
        {
            return ['context' => '', 'entities' => [], 'triples' => [], 'chunks' => [], 'context_items' => 0, 'latency_ms' => 0];
        }

        public function references(string $law, string $article): array
        {
            return ($this->handler)($law, $article);
        }
    });
}

it('rejects an unauthenticated request with 401 JSON', function (): void {
    postJson('/api/v1/tools/follow-references', ['law' => 'ZAKON-ZA-JAVNITE', 'article' => '181'])
        ->assertStatus(401)
        ->assertJsonStructure(['message']);
});

it('requires law and article', function (): void {
    actingAs(User::factory()->create());

    postJson('/api/v1/tools/follow-references', ['law' => 'ZAKON-ZA-JAVNITE'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('article');
});

it('returns the outgoing references of an article', function (): void {
    actingAs(User::factory()->create());

    $received = null;
    bindReferencesRetrieval(function (string $law, string $article) use (&$received): array {
        $received = [$law, $article];

        return [
            'found' => true,
            'source_citation' => 'ZAKON-ZA-JAVNITE, чл. 181',
            'references' => [
                ['citation' => 'ZAKON-ZA-JAVNITE, чл. 55', 'heading' => 'Постапка со преговарање', 'confidence' => 1.0],
                ['citation' => 'ZAKON-ZA-JAVNITE, чл. 145', 'heading' => 'Рокови за жалба', 'confidence' => 0.8],
            ],
        ];
    });

    postJson('/api/v1/tools/follow-references', ['law' => 'ZAKON-ZA-JAVNITE', 'article' => '181'])
        ->assertOk()
        ->assertJson([
            'law' => 'ZAKON-ZA-JAVNITE',
            'article' => '181',
            'found' => true,
            'source_citation' => 'ZAKON-ZA-JAVNITE, чл. 181',
            'count' => 2,
            'references' => [
                ['citation' => 'ZAKON-ZA-JAVNITE, чл. 55', 'heading' => 'Постапка со преговарање', 'confidence' => 1.0],
                ['citation' => 'ZAKON-ZA-JAVNITE, чл. 145', 'heading' => 'Рокови за жалба', 'confidence' => 0.8],
            ],
        ])
        ->assertJsonStructure(['found', 'source_citation', 'count', 'references' => [['citation', 'heading', 'confidence']]]);

    expect($received)->toBe(['ZAKON-ZA-JAVNITE', '181']);
});

it('reports found=false with an empty list for an article not in the corpus', function (): void {
    actingAs(User::factory()->create());

    bindReferencesRetrieval(fn (): array => ['found' => false, 'source_citation' => '', 'references' => []]);

    postJson('/api/v1/tools/follow-references', ['law' => 'NEMA-ZAKON', 'article' => '999'])
        ->assertOk()
        ->assertJson(['found' => false, 'count' => 0, 'references' => []]);
});

it('surfaces a retrieval failure as 503 JSON', function (): void {
    actingAs(User::factory()->create());

    bindReferencesRetrieval(function (): array {
        throw AiServiceException::connectionFailed('retrieval', new RuntimeException('peer down'));
    });

    postJson('/api/v1/tools/follow-references', ['law' => 'ZAKON-ZA-JAVNITE', 'article' => '181'])
        ->assertStatus(503)
        ->assertJson(['error' => ['service' => 'retrieval', 'reason' => 'connection']]);
});
