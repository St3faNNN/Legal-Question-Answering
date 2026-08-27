import type { ChatMessage } from './llm.ts'

/** A search_law/follow_references result that was appended to the history. */
export type ToolEntry = { msgIndex: number; stepIndex: number; result: unknown }

/** Leading characters kept of an older passage (article header + first paragraph). */
export const SNIPPET_CHARS = 500

type SearchResult = {
    query?: unknown
    count?: unknown
    results: Array<{ id?: unknown; source?: unknown; text?: unknown; score?: unknown }>
}

function isSearchResult(value: unknown): value is SearchResult {
    return typeof value === 'object' && value !== null && Array.isArray((value as { results?: unknown }).results)
}

function snippet(text: string): string {
    return text.length <= SNIPPET_CHARS ? text : text.slice(0, SNIPPET_CHARS).trimEnd() + '…'
}

/**
 * Shrink what the loop carries forward, in place, before the next model call.
 *
 * Only search_law results (which carry ~1.2k chars of passage text each) are
 * touched; follow_references (identifier-only) and error results are left as-is.
 * Two levers, both of which ALWAYS keep the citation (`source`) so a later answer
 * can still be grounded in what was retrieved:
 *
 *   - dedup (lossless): a passage returned by several searches keeps its text
 *     once, at its most recent occurrence; the earlier copies become
 *     citation-only.
 *   - compact older (lossy): the most recent search keeps full text; older
 *     searches keep only a leading snippet. Multi-paragraph detail below the
 *     snippet is dropped from older results — the measured trade-off.
 */
export function compactToolHistory(messages: ChatMessage[], toolEntries: ToolEntry[]): void {
    const searches = toolEntries.filter((entry) => isSearchResult(entry.result))
    if (searches.length === 0) {
        return
    }

    const mostRecentStep = Math.max(...searches.map((entry) => entry.stepIndex))

    // The latest message index each source appears at — only that copy keeps text.
    const latestIndexOfSource = new Map<string, number>()
    for (const entry of searches) {
        for (const passage of (entry.result as SearchResult).results) {
            latestIndexOfSource.set(String(passage.source), entry.msgIndex)
        }
    }

    for (const entry of searches) {
        const result = entry.result as SearchResult
        const keepsFullText = entry.stepIndex === mostRecentStep

        const rows = result.results.map((passage) => {
            const source = String(passage.source ?? '')
            if (latestIndexOfSource.get(source) !== entry.msgIndex) {
                return { source, score: passage.score } // duplicate: text lives in a later message
            }
            const text = String(passage.text ?? '')
            return keepsFullText
                ? { id: passage.id, source, text, score: passage.score }
                : { source, score: passage.score, text: snippet(text) }
        })

        const message = messages[entry.msgIndex]
        if (message) {
            message.content = JSON.stringify({ query: result.query, count: result.count, results: rows })
        }
    }
}
