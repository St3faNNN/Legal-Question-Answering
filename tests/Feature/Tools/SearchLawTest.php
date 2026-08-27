<?php

use App\Exceptions\AiServiceException;
use App\Grpc\RetrievalClient;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * Bind a fake RetrievalClient whose retrieve() delegates to $handler, mirroring
 * the seam used by the reply-generator tests — no gRPC extension, no network.
 */
function bindSearchLawRetrieval(Closure $handler): void
{
    app()->instance(RetrievalClient::class, new class($handler) implements RetrievalClient
    {
        public function __construct(private readonly Closure $handler) {}

        public function retrieve(string $question): array
        {
            return ($this->handler)($question);
        }

        public function references(string $law, string $article): array
        {
            return ['found' => false, 'source_citation' => '', 'references' => []];
        }
    });
}

function searchLawResult(): array
{
    return [
        'context' => "[ZAKON-A, чл. 1]\nРаботно време",
        'entities' => ['ZAKON-A, чл. 1', 'ZAKON-A, чл. 3'],
        'triples' => [],
        'chunks' => [
            ['id' => '1', 'text' => 'Работно време и распоред', 'source' => 'ZAKON-A, чл. 1', 'score' => 7.5],
            ['id' => '3', 'text' => 'Плата и надоместоци', 'source' => 'ZAKON-A, чл. 3', 'score' => 4.25],
        ],
        'context_items' => 2,
        'latency_ms' => 12,
    ];
}

it('rejects an unauthenticated request with 401 JSON', function (): void {
    postJson('/api/v1/tools/search-law', ['query' => 'рок за жалба'])
        ->assertStatus(401)
        ->assertJsonStructure(['message']);
});

it('requires a query', function (): void {
    actingAs(User::factory()->create());

    postJson('/api/v1/tools/search-law', [])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('query');
});

it('returns the retrieved passages for a query', function (): void {
    actingAs(User::factory()->create());

    $received = null;
    bindSearchLawRetrieval(function (string $question) use (&$received): array {
        $received = $question;

        return searchLawResult();
    });

    postJson('/api/v1/tools/search-law', ['query' => 'рок за жалба јавна набавка'])
        ->assertOk()
        ->assertJson([
            'query' => 'рок за жалба јавна набавка',
            'count' => 2,
            'results' => [
                ['id' => '1', 'source' => 'ZAKON-A, чл. 1', 'text' => 'Работно време и распоред', 'score' => 7.5],
                ['id' => '3', 'source' => 'ZAKON-A, чл. 3', 'text' => 'Плата и надоместоци', 'score' => 4.25],
            ],
        ])
        ->assertJsonStructure(['query', 'count', 'results' => [['id', 'source', 'text', 'score']]]);

    expect($received)->toBe('рок за жалба јавна набавка');
});

it('surfaces a retrieval failure as 503 JSON', function (): void {
    actingAs(User::factory()->create());

    bindSearchLawRetrieval(function (): array {
        throw AiServiceException::connectionFailed('retrieval', new RuntimeException('peer down'));
    });

    postJson('/api/v1/tools/search-law', ['query' => 'рок за жалба'])
        ->assertStatus(503)
        ->assertJson(['error' => ['service' => 'retrieval', 'reason' => 'connection']]);
});
