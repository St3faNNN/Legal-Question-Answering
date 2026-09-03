<?php

namespace App\Services\Agent;

use Illuminate\Support\Str;

/**
 * Turns a finished agent run into the reply payload the chat already knows how to
 * render — the same `top_chunks` provisions panel a pipeline answer produces, so
 * an escalated answer is displayed by the existing component rather than a
 * parallel one.
 *
 * The one thing this does that the pipeline cannot: it resolves the answer's
 * citations against the passages the run actually retrieved. The agent cites per
 * claim ("(ZAKON-ZA-RABOTNI, чл. 97 ст. 1 т. 1)"), so the provisions panel can be
 * exactly the articles the answer leans on, in the order it leans on them —
 * rather than the top N by score, which is what the pipeline has to settle for.
 *
 * A citation that matches nothing retrieved is counted, not silently dropped: it
 * means the answer named an article the run never read, which is the one failure
 * mode a per-claim citation style makes detectable.
 */
class AgentReplyComposer
{
    /** Passage text stored per provision. Mirrors StreamChatReply::CHUNK_TEXT_LIMIT. */
    private const CHUNK_TEXT_LIMIT = 600;

    /**
     * Provisions shown when the answer cites nothing resolvable — the run's
     * best-scoring passages, so the panel is never empty under a real answer.
     */
    private const FALLBACK_PASSAGE_LIMIT = 5;

    /**
     * A citation as the agent writes it: the law's short code, a comma, then the
     * article number. Anything after (ст., т.) is deliberately not matched — a
     * passage covers a range of paragraphs, so the article is the join key.
     */
    private const CITATION_PATTERN = '/([A-Z][A-Z0-9]*(?:-[A-Z0-9]+)+),\s*чл\.\s*(\d+[а-шњќѓџљжчћ]?)/u';

    /**
     * @param  array<string, mixed>  $done  the agent service's terminal event
     * @return array{answer: string, model: ?string, tokens: ?int, metadata: array<string, mixed>}
     */
    public function compose(array $done, int $durationMs): array
    {
        $answer = is_string($done['answer'] ?? null) ? trim($done['answer']) : '';
        $passages = $this->normalizePassages($done['passages'] ?? []);
        $cited = $this->citedArticles($answer);

        $matched = [];
        $unresolved = [];
        foreach ($cited as $key => $label) {
            if (isset($passages[$key])) {
                $matched[] = $passages[$key];
            } else {
                $unresolved[] = $label;
            }
        }

        // No resolvable citation (an out-of-corpus refusal, or a run that
        // answered without citing) — fall back to the best-scoring passages so
        // the panel reflects what was actually read.
        if ($matched === []) {
            $matched = array_slice(array_values($passages), 0, self::FALLBACK_PASSAGE_LIMIT);
        }

        $totals = is_array($done['totals'] ?? null) ? $done['totals'] : [];

        return [
            'answer' => $answer,
            'model' => is_string($done['model'] ?? null) ? $done['model'] : null,
            'tokens' => isset($totals['totalTokens']) ? (int) $totals['totalTokens'] : null,
            'metadata' => [
                // The marker the UI branches on: this answer came from the agent,
                // not the pipeline, and must be labelled as such.
                'agent' => true,
                'run_id' => is_string($done['runId'] ?? null) ? $done['runId'] : null,
                'outcome' => is_string($done['outcome'] ?? null) ? $done['outcome'] : null,
                // A refusal is an answer that rests on no retrieved provision.
                //
                // 'exhausted' is one way to get there: the step budget ran out and
                // trace.ts synthesised an out-of-scope message. The other, which
                // this flag used to miss, is a run that answers in a single step
                // because the agent's prompt recognised the topic as outside the
                // ten laws and declined to search at all. That outcome is
                // 'answered', so keying only on 'exhausted' left a scope refusal
                // marked as a normal answer and uncounted in the failures panel.
                //
                // The structural test is used rather than matching refusal text:
                // the agent and the pipeline have different prompts and phrase
                // refusals differently, and $matched already falls back to the
                // best-scoring passages whenever any exist -- so an empty panel
                // means nothing was retrieved, which no grounded answer can claim.
                'refused' => ($done['outcome'] ?? null) === 'exhausted' || $matched === [],
                'searches' => $this->searchQueries($done['steps'] ?? []),
                // Same keys a pipeline answer uses, so AssistantResponse renders
                // the provisions with no branching.
                'top_chunks' => array_values($matched),
                'top_triples' => [],
                'entities' => array_column($matched, 'source'),
                'chunk_count' => count($passages),
                'context_items' => count($passages),
                'triple_count' => 0,
                'agent_steps' => is_array($done['steps'] ?? null) ? count($done['steps']) : 0,
                'agent_api_calls' => isset($totals['apiCalls']) ? (int) $totals['apiCalls'] : 0,
                'agent_tokens' => isset($totals['totalTokens']) ? (int) $totals['totalTokens'] : 0,
                // Citations the answer names that no retrieved passage backs.
                'uncited_articles' => $unresolved,
                'total_ms' => $durationMs,
                'total_latency_ms' => $durationMs,
            ],
        ];
    }

    /**
     * The distinct articles the answer cites, keyed by "LAW|number" and mapped to
     * the citation as written, in first-mention order.
     *
     * @return array<string, string>
     */
    public function citedArticles(string $answer): array
    {
        preg_match_all(self::CITATION_PATTERN, $answer, $matches, PREG_SET_ORDER);

        $cited = [];
        foreach ($matches as $match) {
            $key = $this->articleKey($match[1], $match[2]);
            $cited[$key] ??= "{$match[1]}, чл. {$match[2]}";
        }

        return $cited;
    }

    /**
     * Index the run's passages by article, keeping the best-scoring passage for
     * an article that was returned by more than one search.
     *
     * @return array<string, array{id: string, text: string, source: string, score: float|null}>
     */
    private function normalizePassages(mixed $passages): array
    {
        if (! is_array($passages)) {
            return [];
        }

        $indexed = [];
        foreach ($passages as $passage) {
            if (! is_array($passage)) {
                continue;
            }

            $source = (string) ($passage['source'] ?? '');
            if ($source === '' || preg_match(self::CITATION_PATTERN, $source, $parts) !== 1) {
                continue;
            }

            $key = $this->articleKey($parts[1], $parts[2]);
            $score = isset($passage['score']) ? (float) $passage['score'] : null;

            if (isset($indexed[$key]) && ($indexed[$key]['score'] ?? 0) >= ($score ?? 0)) {
                continue;
            }

            $indexed[$key] = [
                'id' => (string) ($passage['id'] ?? ''),
                'text' => Str::limit((string) ($passage['text'] ?? ''), self::CHUNK_TEXT_LIMIT),
                'source' => $source,
                'score' => $score,
            ];
        }

        return $indexed;
    }

    /**
     * The queries the run issued, in order — the same list shown live while it
     * ran, kept on the message so it is still there on reload.
     *
     * @return list<array{tool: string, query: string}>
     */
    private function searchQueries(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        $queries = [];
        foreach ($steps as $step) {
            foreach ((is_array($step) ? $step['calls'] ?? [] : []) as $call) {
                if (is_array($call)) {
                    $queries[] = [
                        'tool' => (string) ($call['tool'] ?? ''),
                        'query' => (string) ($call['query'] ?? ''),
                    ];
                }
            }
        }

        return $queries;
    }

    private function articleKey(string $law, string $article): string
    {
        return mb_strtoupper($law).'|'.mb_strtolower($article);
    }
}
