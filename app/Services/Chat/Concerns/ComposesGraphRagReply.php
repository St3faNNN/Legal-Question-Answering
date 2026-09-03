<?php

namespace App\Services\Chat\Concerns;

use App\Exceptions\AiServiceException;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Shared orchestration for the GraphRAG reply generators. Both the HTTP and
 * gRPC drivers link a question to knowledge-graph context, generate a reply,
 * and assemble the same GeneratesReplies payload — only the transport differs.
 */
trait ComposesGraphRagReply
{
    private const CONTEXT_METADATA_LIMIT = 1000;

    private const TOP_TRIPLES_LIMIT = 5;

    /**
     * Fallback panel size when ai.retrieval_top_n is missing or nonsensical.
     * Matches the retrieval service's own default (config.py, _resolve_top_n).
     */
    private const DEFAULT_PANEL_LIMIT = 10;

    private const CHUNK_TEXT_LIMIT = 600;

    private const HISTORY_MESSAGE_LIMIT = 6;

    private const HISTORY_CONTENT_LIMIT = 500;

    /**
     * Machine-readable refusal marker the generation prompt appends when the
     * answer is not in the sources (see prompts/legal_mk.txt). Detecting it is
     * how a refusal suppresses the provisions panel — far more reliable than
     * matching the refusal sentence, which the model phrases many ways. The
     * frontend strips the same literal from the streamed bubble.
     */
    private const REFUSAL_MARKER = '[[NO_SOURCE]]';

    /**
     * The openings the prompt requires a refusal to begin with. Used as a second
     * signal beside the marker: the marker alone is unreliable — the model
     * sometimes appends it to a real (e.g. negative) answer — so the panel is
     * suppressed only when both agree, and a substantive answer never loses its
     * citations.
     */
    private const REFUSAL_OPENERS = '/^\s*(Системот не ја опфаќа|Информацијата не е достапна|Оваа тема не е опфатена)/u';

    /**
     * Assemble the GeneratesReplies payload from the two services' responses.
     * Retrieval output (entities, triple count, truncated context) is stored in
     * the message metadata so retrieval quality is inspectable per message.
     *
     * `top_chunks` carries every passage the generator read, not a shorter
     * display slice — see {@see self::panelLimit()} for why that matters.
     *
     * @param  array{context?: string, entities?: list<string>, triples?: array<int, mixed>, chunks?: array<int, mixed>, context_items?: int|null, latency_ms?: int|null}  $retrieval
     * @param  array{answer?: string, model?: string|null, tokens?: int|null, latency_ms?: int|null}  $generation
     * @return array{answer: string, model: ?string, tokens: ?int, latency_ms: ?int, metadata: array<string, mixed>}
     */
    private function buildReplyPayload(array $retrieval, array $generation, int $startNs): array
    {
        if (! isset($generation['answer']) || ! is_string($generation['answer']) || $generation['answer'] === '') {
            throw AiServiceException::invalidResponse('generation', 'missing answer field');
        }

        // Refusal handling (see prompts/legal_mk.txt). Always strip the marker so
        // it never shows — the model sometimes appends it in error. Suppress the
        // provisions panel only when the marker AND a refusal-shaped opening agree:
        // showing a page of unrelated articles above a refusal implies they are
        // relevant, but a marker mistakenly added to a real (negative) answer must
        // not strip that answer's citations. False positives are the failure mode
        // to avoid; a missed suppression only leaves the current behaviour.
        $hadMarker = str_contains($generation['answer'], self::REFUSAL_MARKER);
        $answer = $hadMarker ? $this->stripRefusalMarker($generation['answer']) : $generation['answer'];
        $refused = $hadMarker && preg_match(self::REFUSAL_OPENERS, $answer) === 1;
        if ($refused) {
            $retrieval = [
                'context' => '',
                'entities' => [],
                'triples' => [],
                'chunks' => [],
                'context_items' => 0,
                'latency_ms' => $retrieval['latency_ms'] ?? null,
            ];
        }

        return [
            'answer' => $answer,
            // Null, not a model name. This used to fall back to
            // 'qwen2.5-1.5b-instruct' — a model retired on 2026-08-08 and now
            // impossible to run at all. Two callers hand this an explicit null
            // (StreamChatReply converts an empty model string to null; the
            // generator does the same for a missing field), so the fallback was
            // reachable, and it would have stamped a retired local model onto a
            // hosted answer. MetricsService::latencyByModel() groups by this
            // column, so such a row would have joined the genuine historical
            // bucket and been indistinguishable from it.
            //
            // Null is honest and, unlike a plausible name, it is countable: the
            // dashboard's Latency section shows how many answers arrived without
            // a model, so a driver that stops reporting one stays visible.
            'model' => $generation['model'] ?? null,
            'tokens' => $generation['tokens'] ?? null,
            'latency_ms' => (int) ((hrtime(true) - $startNs) / 1_000_000),
            'metadata' => [
                'refused' => $refused,
                'entities' => $retrieval['entities'] ?? [],
                'triple_count' => count($retrieval['triples'] ?? []),
                'top_triples' => $this->topTriples($retrieval['triples'] ?? []),
                'chunk_count' => count($retrieval['chunks'] ?? []),
                'top_chunks' => $this->topChunks($retrieval['chunks'] ?? []),
                'context_items' => $retrieval['context_items'] ?? null,
                'context' => Str::limit($retrieval['context'] ?? '', self::CONTEXT_METADATA_LIMIT),
                'retrieval_latency_ms' => $retrieval['latency_ms'] ?? null,
                'generation_latency_ms' => $generation['latency_ms'] ?? null,
            ],
        ];
    }

    /** Remove the refusal marker (and the whitespace around it) from the answer. */
    private function stripRefusalMarker(string $answer): string
    {
        $cleaned = preg_replace('/\s*'.preg_quote(self::REFUSAL_MARKER, '/').'\s*/u', ' ', $answer);

        return trim($cleaned ?? $answer);
    }

    /**
     * The highest-ranked knowledge-graph triples the answer was grounded in,
     * capped at TOP_TRIPLES_LIMIT. Retrieval already returns them in descending
     * relevance order, so the leading slice is the "top" set surfaced in the UI.
     *
     * @param  array<int, mixed>  $triples
     * @return list<array{head: string, relation: string, tail: string, confidence: float|null}>
     */
    private function topTriples(array $triples): array
    {
        return collect($triples)
            ->take(self::TOP_TRIPLES_LIMIT)
            ->filter(fn ($triple): bool => is_array($triple))
            ->map(fn (array $triple): array => [
                'head' => (string) ($triple['head'] ?? ''),
                'relation' => (string) ($triple['relation'] ?? ''),
                'tail' => (string) ($triple['tail'] ?? ''),
                'confidence' => isset($triple['confidence']) ? (float) $triple['confidence'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * How many provisions the panel shows: every passage the generator read.
     *
     * Derived from ai.retrieval_top_n rather than chosen independently, because
     * an independently chosen number is exactly what went wrong. The panel used
     * to show 5 while retrieval returned 10; for "основите за отказ поради
     * технолошки вишок и колкава испратнина", the answer was grounded in чл. 97
     * at rank 6, and the provision it drew its figures from was never displayed.
     * Retrieval was fine, generation was fine, and a practitioner's correctness
     * review passed — nothing in the system compared what was displayed against
     * what was read.
     *
     * Capping the *context* to 5 instead was measured and rejected: it costs 20%
     * of the answerable fraction on the eval set (recall over the returned window
     * 0.7292 -> 0.5833) and turns that same severance question into a refusal.
     * See the eval runs 20260829-173503-top10-pool24 and -173131-top5-pool24.
     */
    private function panelLimit(): int
    {
        $configured = (int) config('ai.retrieval_top_n', self::DEFAULT_PANEL_LIMIT);

        return $configured > 0 ? $configured : self::DEFAULT_PANEL_LIMIT;
    }

    /**
     * The retrieved passages the answer was grounded in — all of them, in
     * retrieval's rank order. The cap equals what retrieval returns
     * ({@see self::panelLimit()}), so the panel and the generation context hold
     * the same provisions and provenance is complete by construction. Passage
     * text is still truncated: the metadata is a citation trail, not a second
     * copy of the corpus.
     *
     * @param  array<int, mixed>  $chunks
     * @return list<array{id: string, text: string, source: string, score: float|null}>
     */
    private function topChunks(array $chunks): array
    {
        return collect($chunks)
            ->take($this->panelLimit())
            ->filter(fn ($chunk): bool => is_array($chunk))
            ->map(fn (array $chunk): array => [
                'id' => (string) ($chunk['id'] ?? ''),
                'text' => Str::limit((string) ($chunk['text'] ?? ''), self::CHUNK_TEXT_LIMIT),
                'source' => (string) ($chunk['source'] ?? ''),
                'score' => isset($chunk['score']) ? (float) $chunk['score'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The conversation's most recent messages in chronological order, so the
     * Generation Service can resolve follow-up references ("Where was he
     * born?"). Skips the just-persisted current user message.
     *
     * @return list<array{role: string, content: string}>
     */
    private function conversationHistory(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderByDesc('id')
            ->skip(1)
            ->take(self::HISTORY_MESSAGE_LIMIT)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $message): array => [
                'role' => $message->role,
                'content' => Str::limit($message->content, self::HISTORY_CONTENT_LIMIT),
            ])
            ->all();
    }
}
