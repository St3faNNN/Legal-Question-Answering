import assert from 'node:assert/strict'
import { mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { test } from 'node:test'
import type { AssistantMessage, ToolCall } from './llm.ts'
import { Trace, type TraceEvent } from './trace.ts'

/**
 * The progress listener is the only change the HTTP service needed in the agent
 * itself — the loop in agent.ts is untouched. These cover the contract the
 * service relies on: a search event when the model asks for a tool call, a
 * result event carrying the citations it returned, and a listener that cannot
 * take the run down with it.
 */
function tempRunsDir(): string {
    return mkdtempSync(join(tmpdir(), 'trace-test-'))
}

function toolCall(id: string, name: string, args: string): ToolCall {
    return { id, type: 'function', function: { name, arguments: args } }
}

function assistantWith(calls: ToolCall[]): AssistantMessage {
    return { role: 'assistant', content: null, tool_calls: calls }
}

test('emits a search event when the model asks for a tool call', () => {
    const dir = tempRunsDir()
    const events: TraceEvent[] = []

    try {
        const trace = new Trace(dir, 'Прашање?', 'test-model', 8, (event) => events.push(event))
        const call = toolCall('c1', 'search_law', '{"query":"технолошки вишок"}')

        trace.recordAssistant(0, assistantWith([call]), undefined, 120)

        assert.deepEqual(events, [{ type: 'search', step: 0, tool: 'search_law', args: '{"query":"технолошки вишок"}' }])
    } finally {
        rmSync(dir, { recursive: true, force: true })
    }
})

test('emits the citations a tool result returned, for search and for traversal', () => {
    const dir = tempRunsDir()
    const events: TraceEvent[] = []

    try {
        const trace = new Trace(dir, 'Прашање?', 'test-model', 8, (event) => events.push(event))
        const search = toolCall('c1', 'search_law', '{"query":"испратнина"}')
        const follow = toolCall('c2', 'follow_references', '{"law":"ZAKON-ZA-RABOTNI","article":"96"}')

        trace.recordAssistant(0, assistantWith([search, follow]), undefined, 120)
        trace.recordToolResult(0, search, {
            results: [{ source: 'ZAKON-ZA-RABOTNI, чл. 97', text: '…' }, { source: 'ZAKON-ZA-RABOTNI, чл. 96', text: '…' }],
        })
        trace.recordToolResult(0, follow, {
            found: true,
            references: [{ citation: 'ZAKON-ZA-RABOTNI, чл. 88', heading: '…' }],
        })

        const results = events.filter((event) => event.type === 'result')

        assert.deepEqual(results, [
            { type: 'result', step: 0, tool: 'search_law', sources: ['ZAKON-ZA-RABOTNI, чл. 97', 'ZAKON-ZA-RABOTNI, чл. 96'], error: null },
            { type: 'result', step: 0, tool: 'follow_references', sources: ['ZAKON-ZA-RABOTNI, чл. 88'], error: null },
        ])
    } finally {
        rmSync(dir, { recursive: true, force: true })
    }
})

test('reports a failed tool call as an error rather than dropping the event', () => {
    const dir = tempRunsDir()
    const events: TraceEvent[] = []

    try {
        const trace = new Trace(dir, 'Прашање?', 'test-model', 8, (event) => events.push(event))
        const call = toolCall('c1', 'search_law', '{"query":"x"}')

        trace.recordAssistant(0, assistantWith([call]), undefined, 10)
        trace.recordToolResult(0, call, { error: 'tool endpoint returned HTTP 503', status: 503 })

        const result = events.find((event) => event.type === 'result')

        assert.deepEqual(result, { type: 'result', step: 0, tool: 'search_law', sources: [], error: 'tool endpoint returned HTTP 503' })
    } finally {
        rmSync(dir, { recursive: true, force: true })
    }
})

test('a listener that throws does not take the run down with it', () => {
    const dir = tempRunsDir()

    try {
        // A disconnected HTTP client is not the run's problem: the run has already
        // spent tokens and must still finish and persist its trace.
        const trace = new Trace(dir, 'Прашање?', 'test-model', 8, () => {
            throw new Error('client went away')
        })
        const call = toolCall('c1', 'search_law', '{"query":"x"}')

        trace.recordAssistant(0, assistantWith([call]), undefined, 10)
        trace.recordToolResult(0, call, { results: [] })
        const finished = trace.finishAnswered('Одговор.')

        assert.equal(finished.outcome, 'answered')
        assert.equal(finished.steps.length, 1)
    } finally {
        rmSync(dir, { recursive: true, force: true })
    }
})

test('a run with no listener behaves exactly as before', () => {
    const dir = tempRunsDir()

    try {
        const trace = new Trace(dir, 'Прашање?', 'test-model', 8)
        const call = toolCall('c1', 'search_law', '{"query":"x"}')

        trace.recordAssistant(0, assistantWith([call]), { prompt_tokens: 10, completion_tokens: 2, total_tokens: 12 }, 10)
        trace.recordToolResult(0, call, { results: [{ source: 'ZAKON-A, чл. 1', text: '…' }] })

        assert.equal(trace.data.totals.totalTokens, 12)
        assert.equal(trace.data.steps[0]?.toolResults.length, 1)
    } finally {
        rmSync(dir, { recursive: true, force: true })
    }
})
