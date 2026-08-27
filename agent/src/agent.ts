import { compactToolHistory, type ToolEntry } from './compaction.ts'
import type { ChatMessage, ModelFn, ToolSchema } from './llm.ts'
import type { ToolExecutor } from './tools.ts'
import type { Trace, TraceData } from './trace.ts'

export type RunOptions = {
    question: string
    systemPrompt: string
    tools: ToolSchema[]
    callModel: ModelFn
    executeTool: ToolExecutor
    maxSteps: number
    trace: Trace
    /** Compress older tool results in the carried history (see compaction.ts). */
    compact: boolean
    /** Prior turns of a session, carried forward as plain Q&A (see session.ts). */
    history?: Array<{ question: string; answer: string }>
}

/**
 * The hand-written agent loop. No framework — the four things that actually
 * break in a tool-calling loop are all explicit and commented here:
 *   (1) the assistant message is appended BEFORE its tool results,
 *   (2) each tool result carries the tool_call_id it answers,
 *   (3) a failing tool becomes a tool result, never a thrown exception,
 *   (4) a step budget bounds the run so a looping model cannot burn quota.
 */
export async function runAgent(options: RunOptions): Promise<TraceData> {
    const { question, systemPrompt, tools, callModel, executeTool, maxSteps, trace, compact, history } = options

    // Start from the session transcript: system, then each prior turn as a plain
    // user/assistant pair (final answers only — no carried tool calls or
    // passages), then the new question. This turn's own tool loop runs fresh.
    const messages: ChatMessage[] = [{ role: 'system', content: systemPrompt }]
    for (const turn of history ?? []) {
        messages.push({ role: 'user', content: turn.question })
        messages.push({ role: 'assistant', content: turn.answer })
    }
    messages.push({ role: 'user', content: question })
    // Records where each tool result sits in `messages`, so older ones can be
    // shrunk in place before the next call without re-deriving them.
    const toolEntries: ToolEntry[] = []

    for (let step = 0; step < maxSteps; step++) {
        // Shrink the carried history before spending tokens on the next call.
        // Runs before the call, so the newest result (appended last iteration)
        // is now "older" only once a further result supersedes it.
        if (compact && toolEntries.length > 0) {
            compactToolHistory(messages, toolEntries)
        }

        const startedAt = performance.now()
        const response = await callModel(messages, tools)
        const durationMs = performance.now() - startedAt

        const choice = response.choices[0]?.message
        if (!choice) {
            throw new Error('model response contained no choices')
        }

        const toolCalls = choice.tool_calls ?? []

        // (1) Append the assistant turn BEFORE any tool results. The API requires
        //     the message carrying tool_calls to immediately precede the tool
        //     replies that answer them.
        messages.push({
            role: 'assistant',
            content: choice.content ?? '',
            ...(toolCalls.length > 0 ? { tool_calls: toolCalls } : {}),
        })
        trace.recordAssistant(step, choice, response.usage, durationMs)

        // No tool calls means the model is answering rather than searching — done.
        if (toolCalls.length === 0) {
            return trace.finishAnswered(choice.content ?? '')
        }

        for (const call of toolCalls) {
            let result: unknown
            try {
                // (3) A tool failure is data for the model, not a crash: it reads
                //     the error and can rephrase. executeTool already returns
                //     `{ error }` for expected failures; this catch is the backstop.
                result = await executeTool(call.function.name, call.function.arguments)
            } catch (error) {
                result = { error: String(error) }
            }

            // (2) tool_call_id must match the call it answers, or parallel calls
            //     get paired with the wrong replies.
            const msgIndex = messages.length
            messages.push({
                role: 'tool',
                tool_call_id: call.id,
                content: JSON.stringify(result),
            })
            toolEntries.push({ msgIndex, stepIndex: step, result })
            trace.recordToolResult(step, call, result)
        }
    }

    // (4) Step budget spent without a final answer.
    return trace.finishExhausted()
}
