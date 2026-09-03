<?php

use App\Ai\GenerationClient;
use App\Ai\RetrievalClient;
use App\Exceptions\AiServiceException;
use App\Jobs\StreamChatReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\NonLegalInputGate;
use App\Services\Logging\AiLogger;
use Illuminate\Support\Facades\Redis;

/**
 * The reply composition rules — the provisions panel, refusal suppression, the
 * citation trail — exercised through the only code that applies them: the
 * streaming job.
 *
 * These tests used to drive POST /api/v1/chat, the non-streaming endpoint. That
 * endpoint is gone — the browser has streamed since the SSE relay landed, and
 * keeping a second orchestration around meant the composer's invariants were
 * verified on a path no user took. What they assert is unchanged: the panel
 * equals the generation context, a refusal blanks the panel, a marker on a real
 * answer does not, and passage text is truncated to a citation trail.
 *
 * What is deliberately NOT re-asserted here: the transport's failure contract.
 * A retrieval throw propagating to failed_jobs, the terminal SSE error, and the
 * post-first-token retry boundary all belong to the job rather than the
 * composer, and StreamChatReplyTest covers them.
 */
beforeEach(function (): void {
    config(['ai.llm.timeout' => 5]);
});

/**
 * Bind a fake RetrievalClient whose retrieve() delegates to $handler.
 */
function bindComposerRetrieval(Closure $handler): void
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

/**
 * Bind a fake GenerationClient whose generateStream() delegates to $handler.
 * The job only ever calls generateStream(); generate() exists to satisfy the
 * interface and throws so a wrong-path call is loud rather than silent.
 */
function bindComposerGeneration(Closure $streamHandler): void
{
    app()->instance(GenerationClient::class, new class($streamHandler) implements GenerationClient
    {
        public function __construct(private readonly Closure $streamHandler) {}

        public function generate(string $question, string $context, ?string $entity, array $history): array
        {
            throw new LogicException('the streaming path must not call generate()');
        }

        public function generateStream(string $question, string $context, ?string $entity, array $history): iterable
        {
            return ($this->streamHandler)($question, $context, $entity, $history);
        }
    });
}

/**
 * The common case: one answer delivered whole, then the terminating chunk that
 * carries the model and token count.
 */
function bindComposerAnswer(string $answer, string $model = 'gemini-flash-lite-latest', int $tokens = 14): void
{
    bindComposerGeneration(function () use ($answer, $model, $tokens): iterable {
        yield ['text' => $answer, 'done' => false, 'tokens' => 0, 'model' => ''];
        yield ['text' => '', 'done' => true, 'tokens' => $tokens, 'model' => $model];
    });
}

/**
 * A hybrid retrieval result: ranked passages from the vector store plus the
 * cross-references the citation graph found between them.
 */
function composerRetrievalSuccess(): array
{
    return [
        'context' => "[ZAKON-A, чл. 1]\nРаботно време и распоред\n\n[ZAKON-A, чл. 3]\nПлата и надоместоци",
        'entities' => ['ZAKON-A, чл. 1', 'ZAKON-A, чл. 3'],
        'triples' => [
            ['head' => 'ZAKON-A, чл. 1', 'relation' => 'упатува на', 'tail' => 'ZAKON-A, чл. 3', 'confidence' => 0.75],
        ],
        'chunks' => [
            ['id' => '1', 'text' => 'Работно време и распоред', 'source' => 'ZAKON-A, чл. 1', 'score' => 7.5],
            ['id' => '3', 'text' => 'Плата и надоместоци', 'source' => 'ZAKON-A, чл. 3', 'score' => 4.25],
        ],
        'context_items' => 2,
        'latency_ms' => 12,
    ];
}

/** A retrieval response carrying $count ranked passages. */
function composerRankedChunks(int $count): array
{
    return collect(range(1, $count))
        ->map(fn (int $i): array => [
            'id' => (string) $i,
            'text' => "текст {$i}",
            'source' => "ZAKON-A, чл. {$i}",
            'score' => 100.0 - $i,
        ])
        ->all();
}

/**
 * Run the streaming job against the currently bound fakes and return the
 * assistant message it persisted. Redis is mocked away: the composer's output is
 * the persisted row, and the published events are the job's concern.
 */
function composeReply(string $question = 'Колку е работното време?'): Message
{
    $conversation = Conversation::factory()->for(User::factory())->create();

    Redis::shouldReceive('rpush')->andReturn(1);
    Redis::shouldReceive('expire')->andReturnTrue();
    Redis::shouldReceive('exists')->andReturn(0);
    Redis::shouldReceive('setex')->andReturnTrue();

    (new StreamChatReply($conversation->id, 'stream-compose', $question, microtime(true)))->handle(
        app(RetrievalClient::class),
        app(GenerationClient::class),
        app(AiLogger::class),
        app(NonLegalInputGate::class),
    );

    return $conversation->messages()->where('role', 'assistant')->firstOrFail();
}

describe('GraphRAG reply composition', function (): void {
    it('returns the generated reply and stores retrieval metadata', function (): void {
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerAnswer('Работното време е уредено во [ZAKON-A, чл. 1].');

        $message = composeReply();

        expect($message->role)->toBe('assistant')
            ->and($message->content)->toBe('Работното време е уредено во [ZAKON-A, чл. 1].')
            ->and($message->model)->toBe('gemini-flash-lite-latest')
            ->and($message->tokens)->toBe(14);

        expect($message->metadata['entities'])->toBe(['ZAKON-A, чл. 1', 'ZAKON-A, чл. 3'])
            ->and($message->metadata['triple_count'])->toBe(1)
            ->and($message->metadata['top_triples'])->toBe([
                ['head' => 'ZAKON-A, чл. 1', 'relation' => 'упатува на', 'tail' => 'ZAKON-A, чл. 3', 'confidence' => 0.75],
            ])
            ->and($message->metadata['context'])->toContain('Работно време и распоред')
            // Retrieval reports its own service time; generation's is measured by
            // the job around the stream, so it is a duration rather than a value
            // the fake supplies.
            ->and($message->metadata['retrieval_latency_ms'])->toBe(12)
            ->and($message->metadata['generation_latency_ms'])->toBeGreaterThanOrEqual(0);
    });

    it('stores the returned passages as citations', function (): void {
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerAnswer('Работното време е уредено во [ZAKON-A, чл. 1].');

        $metadata = composeReply()->metadata;

        expect($metadata['chunk_count'])->toBe(2)
            ->and($metadata['context_items'])->toBe(2)
            ->and($metadata['top_chunks'])->toBe([
                ['id' => '1', 'text' => 'Работно време и распоред', 'source' => 'ZAKON-A, чл. 1', 'score' => 7.5],
                ['id' => '3', 'text' => 'Плата и надоместоци', 'source' => 'ZAKON-A, чл. 3', 'score' => 4.25],
            ]);
    });

    it('shows every passage the generator read, not a shorter display slice', function (): void {
        // The bug this asserts against: the panel showed 5 while retrieval
        // returned 10, so an answer grounded in the passage at rank 6 cited a
        // provision the reader never saw (чл. 97, the severance case). Nothing
        // compared the two numbers, so nothing caught it.
        config(['ai.retrieval_top_n' => 10]);

        bindComposerRetrieval(fn (): array => [...composerRetrievalSuccess(), 'chunks' => composerRankedChunks(10)]);
        bindComposerAnswer('Одговор.');

        $metadata = composeReply('Прашање?')->metadata;

        expect($metadata['chunk_count'])->toBe(10)
            ->and($metadata['top_chunks'])->toHaveCount(10)
            ->and(array_column($metadata['top_chunks'], 'id'))
            ->toBe(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);
    });

    it('sizes the panel from ai.retrieval_top_n, so tuning retrieval moves the panel with it', function (): void {
        // The coupling itself. If someone raises RETRIEVAL_TOP_N and the panel
        // stays put, the gap reopens silently — this is what stops that.
        config(['ai.retrieval_top_n' => 16]);

        bindComposerRetrieval(fn (): array => [...composerRetrievalSuccess(), 'chunks' => composerRankedChunks(16)]);
        bindComposerAnswer('Одговор.');

        $metadata = composeReply('Прашање?')->metadata;

        // The panel equals the configured window: everything read is shown.
        expect($metadata['top_chunks'])->toHaveCount(config('ai.retrieval_top_n'))
            ->and($metadata['top_chunks'])->toHaveCount($metadata['chunk_count']);
    });

    it('never shows fewer provisions than the generator read', function (int $returned): void {
        // The invariant, stated directly: whatever retrieval returns within the
        // configured window is displayed. A panel shorter than the context is the
        // provenance bug, whichever way the two drift.
        config(['ai.retrieval_top_n' => 10]);

        bindComposerRetrieval(fn (): array => [...composerRetrievalSuccess(), 'chunks' => composerRankedChunks($returned)]);
        bindComposerAnswer('Одговор.');

        $metadata = composeReply('Прашање?')->metadata;

        expect($metadata['top_chunks'])->toHaveCount($returned)
            ->and($metadata['chunk_count'])->toBe($returned);
    })->with([1, 5, 9, 10]);

    it('falls back to the retrieval service default when the config value is nonsense', function (): void {
        // A misconfigured 0 must not blank the panel — that would be the same
        // provenance failure by another route.
        config(['ai.retrieval_top_n' => 0]);

        bindComposerRetrieval(fn (): array => [...composerRetrievalSuccess(), 'chunks' => composerRankedChunks(10)]);
        bindComposerAnswer('Одговор.');

        expect(composeReply('Прашање?')->metadata['top_chunks'])->toHaveCount(10);
    });

    it('truncates long passage text in the citation trail', function (): void {
        bindComposerRetrieval(fn (): array => [
            ...composerRetrievalSuccess(),
            'chunks' => [['id' => '1', 'text' => str_repeat('а', 2000), 'source' => 'ZAKON-A, чл. 1', 'score' => 1.0]],
        ]);
        bindComposerAnswer('Одговор.');

        $metadata = composeReply('Прашање?')->metadata;

        // The metadata is a citation trail, not a second copy of the corpus.
        expect(mb_strlen($metadata['top_chunks'][0]['text']))->toBeLessThan(2000);
    });

    it('handles a retrieval response that carries no chunks', function (): void {
        // A retrieval build that returns only triples: the payload must still
        // assemble rather than blowing up on a missing key.
        bindComposerRetrieval(fn (): array => [
            'context' => 'некој контекст',
            'entities' => ['ZAKON-A, чл. 1'],
            'triples' => [],
            'latency_ms' => 5,
        ]);
        bindComposerAnswer('Одговор.');

        $metadata = composeReply('Прашање?')->metadata;

        expect($metadata['chunk_count'])->toBe(0)
            ->and($metadata['top_chunks'])->toBe([])
            ->and($metadata['context_items'])->toBeNull();
    });

    it('forwards question, context and entity to the generation client', function (): void {
        $captured = [];
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerGeneration(function (string $question, string $context, ?string $entity, array $history) use (&$captured): iterable {
            $captured = compact('question', 'context', 'entity', 'history');

            yield ['text' => 'Одговор.', 'done' => false, 'tokens' => 0, 'model' => ''];
            yield ['text' => '', 'done' => true, 'tokens' => 3, 'model' => 'gemini-flash-lite-latest'];
        });

        composeReply();

        expect($captured['question'])->toBe('Колку е работното време?')
            ->and($captured['context'])->toBe("[ZAKON-A, чл. 1]\nРаботно време и распоред\n\n[ZAKON-A, чл. 3]\nПлата и надоместоци")
            ->and($captured['entity'])->toBe('ZAKON-A, чл. 1')
            ->and($captured['history'])->toBe([]);
    });

    it('never sends conversation history to the generator (avoids answer copying)', function (): void {
        // Each question is answered from its own retrieved context, so the model
        // cannot copy a prior answer. The job passes a literal [] rather than
        // trimming history, so this holds even mid-conversation.
        $captured = [];
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerGeneration(function (string $question, string $context, ?string $entity, array $history) use (&$captured): iterable {
            $captured[] = compact('question', 'history');

            yield ['text' => 'Одговор.', 'done' => false, 'tokens' => 0, 'model' => ''];
            yield ['text' => '', 'done' => true, 'tokens' => 3, 'model' => 'gemini-flash-lite-latest'];
        });

        $conversation = Conversation::factory()->for(User::factory())->create();
        $conversation->messages()->create(['role' => 'user', 'content' => 'Што е јавна набавка?']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Претходен одговор.']);

        Redis::shouldReceive('rpush')->andReturn(1);
        Redis::shouldReceive('expire')->andReturnTrue();
        Redis::shouldReceive('exists')->andReturn(0);
        Redis::shouldReceive('setex')->andReturnTrue();

        (new StreamChatReply($conversation->id, 'stream-followup', 'А кој ја спроведува?', microtime(true)))->handle(
            app(RetrievalClient::class),
            app(GenerationClient::class),
            app(AiLogger::class),
            app(NonLegalInputGate::class),
        );

        expect($captured[0]['question'])->toBe('А кој ја спроведува?')
            ->and($captured[0]['history'])->toBe([]);
    });

    it('still generates a reply when retrieval finds no context', function (): void {
        $captured = [];
        bindComposerRetrieval(fn (): array => [
            'context' => '', 'entities' => [], 'triples' => [], 'chunks' => [], 'context_items' => 0, 'latency_ms' => 3,
        ]);
        bindComposerGeneration(function (string $question, string $context, ?string $entity, array $history) use (&$captured): iterable {
            $captured = compact('context', 'entity');

            yield ['text' => 'Одговор.', 'done' => false, 'tokens' => 0, 'model' => ''];
            yield ['text' => '', 'done' => true, 'tokens' => 3, 'model' => 'gemini-flash-lite-latest'];
        });

        $metadata = composeReply('Нешто што го нема во документите')->metadata;

        expect($captured['context'])->toBe('')
            ->and($captured['entity'])->toBeNull();

        expect($metadata['triple_count'])->toBe(0)
            ->and($metadata['entities'])->toBe([])
            ->and($metadata['top_triples'])->toBe([])
            ->and($metadata['top_chunks'])->toBe([]);
    });

    it('refuses to compose a message from an empty answer', function (): void {
        // A stream that terminates with nothing said is a generation failure, not
        // an empty reply. Composing it would persist a blank assistant bubble and
        // report success; instead it raises, and the job's pre-token branch lets
        // the queue retry.
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerGeneration(function (): iterable {
            yield ['text' => '', 'done' => true, 'tokens' => 0, 'model' => 'gemini-flash-lite-latest'];
        });

        expect(fn () => composeReply())
            ->toThrow(AiServiceException::class);

        expect(Message::query()->where('role', 'assistant')->count())->toBe(0);
    });
});

describe('refusal handling', function (): void {
    it('suppresses the provisions panel when generation refuses (marker present)', function (): void {
        // Retrieval DID return chunks — but the answer carries the refusal marker
        // and a refusal-shaped opening, so the panel must be blanked and the
        // marker stripped from the text.
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerAnswer('Оваа тема не е опфатена со достапните закони. [[NO_SOURCE]]', 'gemini', 5);

        $message = composeReply('Колку години се служи воен рок?');

        expect($message->metadata['refused'])->toBeTrue()
            ->and($message->metadata['top_chunks'])->toBe([])
            ->and($message->metadata['chunk_count'])->toBe(0)
            ->and($message->metadata['top_triples'])->toBe([])
            ->and($message->metadata['entities'])->toBe([])
            ->and($message->content)->not->toContain('[[NO_SOURCE]]')
            ->and($message->content)->toContain('не е опфатена');
    });

    it('keeps the provisions panel for a normal answer (no marker)', function (): void {
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerAnswer('Работното време е уредено во [ZAKON-A, чл. 1].');

        $metadata = composeReply()->metadata;

        expect($metadata['refused'])->toBeFalse()
            ->and($metadata['top_chunks'])->not->toBe([]);
    });

    it('keeps the panel (but strips the marker) when a real answer wrongly carries it', function (): void {
        // The model sometimes appends the marker to a substantive negative answer.
        // Because the text is not refusal-shaped, the panel must survive — only the
        // stray marker is removed. This is the false positive that must never fire.
        bindComposerRetrieval(fn (): array => composerRetrievalSuccess());
        bindComposerAnswer(
            'Вработен со средна стручна спрема не ги исполнува условите за лице за јавни набавки. [[NO_SOURCE]]',
            'gemini',
            5,
        );

        $message = composeReply('Дали вработен со средна стручна спрема може да биде лице за јавни набавки?');

        expect($message->metadata['refused'])->toBeFalse()
            ->and($message->metadata['top_chunks'])->not->toBe([])
            ->and($message->content)->not->toContain('[[NO_SOURCE]]');
    });
});
