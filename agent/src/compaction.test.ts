import assert from 'node:assert/strict'
import { test } from 'node:test'
import { compactToolHistory, SNIPPET_CHARS, type ToolEntry } from './compaction.ts'
import type { ChatMessage } from './llm.ts'

function searchResult(query: string, rows: Array<Record<string, unknown>>): unknown {
    return { query, count: rows.length, results: rows }
}

function toolMsg(content: string): ChatMessage {
    return { role: 'tool', tool_call_id: 'x', content }
}

test('keeps the most recent search full, snippets older ones, and dedups shared sources', () => {
    const long = 'H'.repeat(50) + ' ' + 'x'.repeat(2000) // > SNIPPET_CHARS
    const r0 = searchResult('q0', [
        { id: '1', source: 'ZAKON, чл. 55', text: long, score: 1 },
        { id: '2', source: 'ZAKON, чл. 145', text: long, score: 0.9 },
    ])
    const r1 = searchResult('q1', [
        { id: '1', source: 'ZAKON, чл. 55', text: long, score: 1 }, // duplicate of r0
        { id: '9', source: 'ZAKON, чл. 181', text: long, score: 0.8 },
    ])
    const messages: ChatMessage[] = [
        { role: 'system', content: 's' },
        { role: 'user', content: 'u' },
        { role: 'assistant', content: '', tool_calls: [] },
        toolMsg(JSON.stringify(r0)), // index 3, step 0 (older)
        { role: 'assistant', content: '', tool_calls: [] },
        toolMsg(JSON.stringify(r1)), // index 5, step 1 (most recent)
    ]
    const toolEntries: ToolEntry[] = [
        { msgIndex: 3, stepIndex: 0, result: r0 },
        { msgIndex: 5, stepIndex: 1, result: r1 },
    ]

    compactToolHistory(messages, toolEntries)

    const c0 = JSON.parse(messages[3]!.content as string)
    const c1 = JSON.parse(messages[5]!.content as string)

    // Every source is retained in both messages (grounding is preserved).
    assert.deepEqual(c0.results.map((p: { source: string }) => p.source), ['ZAKON, чл. 55', 'ZAKON, чл. 145'])
    assert.deepEqual(c1.results.map((p: { source: string }) => p.source), ['ZAKON, чл. 55', 'ZAKON, чл. 181'])

    // чл.55 is in both; its text lives in the later message, so the older copy is citation-only.
    assert.equal(c0.results.find((p: { source: string }) => p.source === 'ZAKON, чл. 55').text, undefined)

    // чл.145 is only in the older search → snippet (bounded, ellipsized).
    const older145 = c0.results.find((p: { source: string }) => p.source === 'ZAKON, чл. 145')
    assert.ok(older145.text.length <= SNIPPET_CHARS + 1)
    assert.ok(older145.text.endsWith('…'))

    // The most recent search keeps full text for both its passages.
    assert.equal(c1.results.find((p: { source: string }) => p.source === 'ZAKON, чл. 55').text, long)
    assert.equal(c1.results.find((p: { source: string }) => p.source === 'ZAKON, чл. 181').text, long)
})

test('leaves follow_references and error results untouched', () => {
    const refResult = { found: true, source_citation: 'X', references: [{ citation: 'Y', heading: 'h', confidence: 1 }] }
    const errResult = { error: 'boom' }
    const messages: ChatMessage[] = [toolMsg(JSON.stringify(refResult)), toolMsg(JSON.stringify(errResult))]
    const before = messages.map((m) => m.content)

    compactToolHistory(messages, [
        { msgIndex: 0, stepIndex: 0, result: refResult },
        { msgIndex: 1, stepIndex: 1, result: errResult },
    ])

    assert.deepEqual(messages.map((m) => m.content), before)
})

test('a lone search is kept full — it is the most recent', () => {
    const long = 'x'.repeat(2000)
    const r0 = searchResult('q', [{ id: '1', source: 'A', text: long, score: 1 }])
    const messages: ChatMessage[] = [toolMsg(JSON.stringify(r0))]

    compactToolHistory(messages, [{ msgIndex: 0, stepIndex: 0, result: r0 }])

    assert.equal(JSON.parse(messages[0]!.content as string).results[0].text, long)
})
