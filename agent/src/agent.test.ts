import assert from 'node:assert/strict'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { test } from 'node:test'
import { runAgent } from './agent.ts'
import type { AssistantMessage, ChatMessage, ChatResponse, ModelFn, ToolCall } from './llm.ts'
import { Trace } from './trace.ts'

/**
 * Loop mechanics, exercised with a stub model and stub tool — no network, no
 * Gemini. These pin the four things the loop exists to get right.
 */

function toolCall(id: string, query: string): ToolCall {
    return { id, type: 'function', function: { name: 'search_law', arguments: JSON.stringify({ query }) } }
}

function callsResponse(calls: ToolCall[], finishReason?: string): ChatResponse {
    return {
        choices: [{
            message: { role: 'assistant', content: null, tool_calls: calls },
            ...(finishReason !== undefined ? { finish_reason: finishReason } : {}),
        }],
        usage: { prompt_tokens: 10, completion_tokens: 5, total_tokens: 15 },
    }
}

function answerResponse(text: string): ChatResponse {
    return {
        choices: [{ message: { role: 'assistant', content: text } }],
        usage: { prompt_tokens: 20, completion_tokens: 8, total_tokens: 28 },
    }
}

function newTrace(): Trace {
    return new Trace(mkdtempSync(resolve(tmpdir(), 'agent-trace-')), 'Q', 'stub-model', 8)
}

test('appends the assistant message before tool results, with matching tool_call_id', async () => {
    const scripted = [
        callsResponse([toolCall('call_a', 'обврска')]),
        callsResponse([toolCall('call_b', 'рок')]),
        answerResponse('Готово. [ZAKON, чл. 1]'),
    ]
    const captured: ChatMessage[][] = []
    let index = 0
    const callModel: ModelFn = async (messages) => {
        captured.push(structuredClone(messages))
        return scripted[index++]!
    }
    const executeTool = async (): Promise<unknown> => ({
        results: [{ id: '1', source: 'ZAKON, чл. 1', text: 't', score: 1 }],
    })

    const result = await runAgent({
        question: 'Кои се последиците и кои рокови важат?',
        systemPrompt: 'S',
        tools: [],
        callModel,
        executeTool,
        maxSteps: 8,
        trace: newTrace(),
        compact: false,
    })

    assert.equal(result.outcome, 'answered')
    assert.equal(result.steps.length, 3)
    assert.equal(result.totals.apiCalls, 3)

    // The 2nd model call must see history ending: assistant(tool_calls) then tool.
    const second = captured[1]!
    const assistantMsg = second[second.length - 2] as AssistantMessage
    const toolMsg = second[second.length - 1] as { role: string; tool_call_id: string }
    assert.equal(assistantMsg.role, 'assistant')
    assert.equal(assistantMsg.tool_calls?.[0]?.id, 'call_a')
    assert.equal(toolMsg.role, 'tool')
    assert.equal(toolMsg.tool_call_id, 'call_a') // (2) result is paired to its call
})

test('a thrown tool executor becomes a tool result, not a crash', async () => {
    const scripted = [callsResponse([toolCall('c1', 'x')]), answerResponse('done')]
    let index = 0
    const callModel: ModelFn = async () => scripted[index++]!
    const executeTool = async (): Promise<unknown> => {
        throw new Error('boom')
    }

    const result = await runAgent({
        question: 'q',
        systemPrompt: 's',
        tools: [],
        callModel,
        executeTool,
        maxSteps: 8,
        trace: newTrace(),
        compact: false,
    })

    assert.equal(result.outcome, 'answered')
    const toolResult = result.steps[0]!.toolResults[0]!
    assert.equal(toolResult.tool_call_id, 'c1')
    assert.match(JSON.stringify(toolResult.result), /boom/)
})

test('does not execute a tool call from a turn truncated at the token limit', async () => {
    // finish_reason 'length' means the turn was cut off, so the call's JSON
    // arguments may be a half-written string. Running it would search for the
    // fragment and hand the model a confident answer to a question it never
    // finished asking.
    const scripted = [
        callsResponse([toolCall('cut', 'работно вре')], 'length'),
        answerResponse('done'),
    ]
    let index = 0
    let executed = 0
    const callModel: ModelFn = async () => scripted[index++]!
    const executeTool = async (): Promise<unknown> => {
        executed++

        return { results: [] }
    }

    const result = await runAgent({
        question: 'q',
        systemPrompt: 's',
        tools: [],
        callModel,
        executeTool,
        maxSteps: 8,
        trace: newTrace(),
        compact: false,
    })

    assert.equal(executed, 0)

    // The failure is reported back as a tool result, paired to its call, so the
    // model can reissue rather than the run dying.
    const toolResult = result.steps[0]!.toolResults[0]!
    assert.equal(toolResult.tool_call_id, 'cut')
    assert.match(JSON.stringify(toolResult.result), /output token limit/)
    assert.equal(result.outcome, 'answered')
})

test('stops at the step budget when the model never answers', async () => {
    const callModel: ModelFn = async () => callsResponse([toolCall('c', 'x')])
    const executeTool = async (): Promise<unknown> => ({ ok: true })

    const result = await runAgent({
        question: 'q',
        systemPrompt: 's',
        tools: [],
        callModel,
        executeTool,
        maxSteps: 3,
        trace: newTrace(),
        compact: false,
    })

    assert.equal(result.outcome, 'exhausted')
    assert.equal(result.steps.length, 3)
    // Exhaustion no longer returns a bare null — it carries an out-of-scope message.
    assert.match(result.finalAnswer ?? '', /корпус/)
})
