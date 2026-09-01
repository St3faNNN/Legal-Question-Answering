<?php

namespace App\Services\Chat;

/**
 * A pre-retrieval gate that short-circuits input which is not a legal question
 * at all — greetings, thanks, small talk, questions about the assistant, empty
 * or near-empty input — so "Како си" no longer embeds with BGE-M3, searches
 * 1,831 chunks, expands the citation graph and calls Gemini only to render a
 * page of irrelevant provisions above a refusal.
 *
 * Deliberately NOT an LLM (that defeats the purpose) and NOT a fused-score
 * threshold: RRF scores do not separate answerable from unanswerable — the
 * known-unanswerable eval rows sit inside the answerable range, several at the
 * single-signal floor — so a threshold either fires on real questions or never
 * fires.
 *
 * The scope is narrow on purpose: "is this a legal question at all", not "is the
 * answer in the corpus" (that stays with the generation prompt, which already
 * refuses correctly — including for in-scope-domain misses like the army
 * question). The design is biased hard toward false negatives: it fires only
 * when the WHOLE normalized input is a known non-legal phrase, and never when
 * the input carries a legal signal, so a terse real query ("рок за жалба",
 * "чл. 132", "годишен одмор") always reaches retrieval.
 */
class NonLegalInputGate
{
    /**
     * Whole-input non-legal phrases (anchored ^…$), Cyrillic plus the common
     * Latin transliterations. Because each must match the ENTIRE normalized
     * input, any input carrying extra (legal) content falls through to retrieval.
     *
     * @var list<string>
     */
    private const NON_LEGAL_PATTERNS = [
        // greetings
        '/^(здраво|здр|ало|чао|еј|hi|hello|hey|hej|zdravo|cao|ej)$/u',
        '/^(добро утро|добар ден|добро попладне|добра вечер|добро вечер|good (morning|day|evening))$/u',
        '/^како (си|сте|е|оди|си денес|си ти)$/u',
        '/^kako (si|ste|e)$/u',
        '/^што (правиш|правите|има|ново|има ново)$/u',
        '/^(има ли нешто ново|дали имаш нешто ново|што има ново)$/u',
        '/^whats? (up|new)$/u',
        // thanks
        '/^(благодарам|фала|ти благодарам|многу благодарам|ви благодарам|благодарам многу)$/u',
        '/^(blagodaram|fala|tenks)$/u',
        '/^(thanks|thank you|thx|ty)$/u',
        // farewells
        '/^(довидување|пријатно|се гледаме|поздрав|bye|goodbye|see you)$/u',
        // questions about the assistant itself
        '/^(кој|што) си ти$/u',
        '/^кој те (создаде|направи|изработи|програмира)$/u',
        '/^што (можеш|знаеш|правиш) ти$/u',
        '/^(who (are|r) (you|u)|what (are|can|do) you( do)?|who made you)$/u',
        // bare affirmations / fillers as an entire message
        '/^(ок|океј|окej|добро|да|не|аха|мхм|море|епа|ok|okay|yes|no|yep|nope|тест|test|проба)$/u',
    ];

    /**
     * Legal signals that force a pass regardless of anything else — an article
     * or law reference is unambiguous. Belt-and-suspenders on top of the
     * whole-input anchoring above.
     *
     * @var list<string>
     */
    private const LEGAL_TOKENS = ['чл', 'член', 'членот', 'члена', 'членови', 'став', 'ставот', 'точка'];

    private const LEGAL_PREFIXES = ['закон', 'пропис', 'одредб'];

    public function shouldGate(string $input): bool
    {
        $normalized = $this->normalize($input);

        if ($normalized === '') {
            return true; // empty / whitespace / punctuation- or emoji-only
        }

        if ($this->hasLegalSignal($normalized)) {
            return false; // an explicit article/law reference — always let through
        }

        foreach (self::NON_LEGAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false; // default: reaches retrieval
    }

    /**
     * Lowercase, replace punctuation/emoji with spaces (keeping letters, digits
     * and spaces), then collapse whitespace. Latin and Cyrillic both survive; the
     * dot in "чл. 132" becomes "чл 132", still caught by {@see hasLegalSignal}.
     */
    private function normalize(string $input): string
    {
        $lower = mb_strtolower(trim($input));
        $lettersOnly = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $lettersOnly) ?? '');
    }

    private function hasLegalSignal(string $normalized): bool
    {
        foreach (explode(' ', $normalized) as $token) {
            if (in_array($token, self::LEGAL_TOKENS, true)) {
                return true;
            }
            foreach (self::LEGAL_PREFIXES as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The bilingual gated response — brief, and says what the tool is for (with
     * an example) rather than only refusing.
     */
    public function message(): string
    {
        return implode("\n\n", [
            'Оваа алатка одговара само на правни прашања поврзани со десет македонски закони '
                .'(меѓу кои: јавни набавки, работни односи, заштита на личните податоци, слободен пристап до '
                .'информации, општа управна постапка, авторско право и култура). Поставете правно прашање — '
                .'на пример: „Кој е рокот за изјавување жалба во постапка за јавна набавка?“',
            'This tool only answers legal questions about ten Macedonian laws (among them public procurement, '
                .'labour relations, personal-data protection, free access to information, general administrative '
                .'procedure, copyright and culture). Ask a legal question — for example: '
                .'“What is the deadline to file an appeal in a public-procurement procedure?”',
        ]);
    }

    /**
     * The reply payload for a gated input: the same shape the reply generators
     * return, but with no retrieval, no generation, and a `gated` marker plus
     * empty passages so the UI shows the message with no provisions panel.
     *
     * @return array{answer: string, model: null, tokens: int, latency_ms: int, metadata: array<string, mixed>}
     */
    public function gatedPayload(): array
    {
        return [
            'answer' => $this->message(),
            'model' => null,
            'tokens' => 0,
            'latency_ms' => 0,
            'metadata' => [
                'gated' => true,
                'entities' => [],
                'triple_count' => 0,
                'top_triples' => [],
                'chunk_count' => 0,
                'top_chunks' => [],
                'context_items' => 0,
                'context' => '',
                'retrieval_latency_ms' => 0,
                'generation_latency_ms' => 0,
            ],
        ];
    }
}
