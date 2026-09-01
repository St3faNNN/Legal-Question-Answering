import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { AssistantMessage, ToolCall, Usage } from './llm.ts'

export type ToolResultRecord = {
    tool_call_id: string
    name: string
    args: string
    result: unknown
}

export type StepRecord = {
    index: number
    durationMs: number
    usage: Usage | null
    assistant: { content: string | null; tool_calls: ToolCall[] }
    toolResults: ToolResultRecord[]
}

export type Outcome = 'running' | 'answered' | 'exhausted'

/**
 * Progress emitted as the run happens, for a caller that cannot wait for the
 * finished trace — the HTTP service (server.ts) forwards these so the web app
 * can show each search as it is issued rather than a spinner. The shapes mirror
 * what the inspector renders from a finished trace: the tool call, then the
 * citations it returned.
 */
export type TraceEvent =
    | { type: 'search'; step: number; tool: string; args: string }
    | { type: 'result'; step: number; tool: string; sources: string[]; error: string | null }

export type TraceListener = (event: TraceEvent) => void

export type TraceData = {
    runId: string
    question: string
    model: string
    maxSteps: number
    startedAt: string
    finishedAt: string | null
    outcome: Outcome
    finalAnswer: string | null
    totals: { apiCalls: number; promptTokens: number; completionTokens: number; totalTokens: number }
    steps: StepRecord[]
}

const RUN_ID_PATTERN = /^[0-9]{8}-[0-9]{6}-[a-z0-9]{4,8}$/

/**
 * The persisted record of one run. Every mutation flushes the full JSON to
 * runs/<runId>.json, so a crash mid-run still leaves an inspectable partial
 * trace. Tool results are stored whole — the point of the trace is to read what
 * the agent actually saw.
 */
export class Trace {
    data: TraceData
    private readonly dir: string
    private readonly listener: TraceListener | null

    constructor(runsDir: string, question: string, model: string, maxSteps: number, onEvent?: TraceListener) {
        this.dir = runsDir
        this.listener = onEvent ?? null
        this.data = {
            runId: newRunId(),
            question,
            model,
            maxSteps,
            startedAt: new Date().toISOString(),
            finishedAt: null,
            outcome: 'running',
            finalAnswer: null,
            totals: { apiCalls: 0, promptTokens: 0, completionTokens: 0, totalTokens: 0 },
            steps: [],
        }
        mkdirSync(this.dir, { recursive: true })
        this.flush()
    }

    /** Record the model's turn for a step and accumulate token usage. */
    recordAssistant(index: number, assistant: AssistantMessage, usage: Usage | undefined, durationMs: number): void {
        this.data.totals.apiCalls += 1
        if (usage) {
            this.data.totals.promptTokens += usage.prompt_tokens ?? 0
            this.data.totals.completionTokens += usage.completion_tokens ?? 0
            this.data.totals.totalTokens += usage.total_tokens ?? 0
        }

        this.data.steps.push({
            index,
            durationMs: Math.round(durationMs),
            usage: usage ?? null,
            assistant: { content: assistant.content ?? null, tool_calls: assistant.tool_calls ?? [] },
            toolResults: [],
        })
        this.flush()

        for (const call of assistant.tool_calls ?? []) {
            this.emit({ type: 'search', step: index, tool: call.function.name, args: call.function.arguments })
        }
    }

    /** Record one tool result under the step that requested it. */
    recordToolResult(index: number, call: ToolCall, result: unknown): void {
        const step = this.data.steps.find((candidate) => candidate.index === index)
        if (!step) {
            return
        }

        step.toolResults.push({
            tool_call_id: call.id,
            name: call.function.name,
            args: call.function.arguments,
            result,
        })
        this.flush()

        this.emit({
            type: 'result',
            step: index,
            tool: call.function.name,
            sources: citationsOf(result),
            error: typeof result === 'object' && result !== null && 'error' in result ? String(result.error) : null,
        })
    }

    finishAnswered(answer: string | null): TraceData {
        this.data.outcome = 'answered'
        this.data.finalAnswer = answer
        this.data.finishedAt = new Date().toISOString()
        this.flush()

        return this.data
    }

    /**
     * The step budget was spent without the model producing a final answer.
     * Rather than return a bare "exhausted" with no answer, synthesise a clear
     * out-of-scope response: reaching the budget means the model kept searching
     * without concluding — almost always because the topic is not in the
     * corpus. The outcome stays 'exhausted' so the trace remains honest about
     * what happened, but finalAnswer now carries something usable.
     */
    finishExhausted(): TraceData {
        this.data.outcome = 'exhausted'
        this.data.finalAnswer =
            `Не пронајдов одговор во корпусот по ${this.data.maxSteps} пребарувања. ` +
            'Најверојатно оваа тема не е опфатена со десетте закони во корпусот ' +
            '(јавни набавки, работни односи, лични податоци, слободен пристап до информации, ' +
            'општа управна постапка, административни службеници, вработени во јавниот сектор, ' +
            'авторско право, издавачка дејност и култура). ' +
            'Обидете се со прашање од некоја од овие области.'
        this.data.finishedAt = new Date().toISOString()
        this.flush()

        return this.data
    }

    path(): string {
        return resolve(this.dir, `${this.data.runId}.json`)
    }

    /** Notify the listener, if any. A listener failure must not kill the run. */
    private emit(event: TraceEvent): void {
        try {
            this.listener?.(event)
        } catch {
            // A broken consumer (a disconnected HTTP client) is not the run's problem.
        }
    }

    private flush(): void {
        writeFileSync(this.path(), JSON.stringify(this.data, null, 2), 'utf8')
    }
}

/**
 * The article citations a tool result names — passage `source` fields for
 * search_law, `citation` fields for follow_references. Identifiers only: this
 * feeds a progress event, not the answer.
 */
function citationsOf(result: unknown): string[] {
    if (typeof result !== 'object' || result === null) {
        return []
    }

    const rows = (result as { results?: unknown }).results ?? (result as { references?: unknown }).references
    if (!Array.isArray(rows)) {
        return []
    }

    return rows
        .map((row: unknown) => {
            if (typeof row !== 'object' || row === null) {
                return ''
            }
            const record = row as { source?: unknown; citation?: unknown }

            return String(record.source ?? record.citation ?? '')
        })
        .filter((citation) => citation !== '')
}

function newRunId(): string {
    const now = new Date()
    const pad = (value: number): string => String(value).padStart(2, '0')
    const stamp =
        `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}` +
        `-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`
    const random = Math.random().toString(36).slice(2, 8)

    return `${stamp}-${random}`
}

/** Run ids present on disk, newest first. */
export function listRuns(runsDir: string): string[] {
    try {
        return readdirSync(runsDir)
            .filter((file) => file.endsWith('.json'))
            .map((file) => file.replace(/\.json$/, ''))
            .sort()
            .reverse()
    } catch {
        return []
    }
}

/** Read one run by id. The id shape is validated to block path traversal. */
export function readRun(runsDir: string, runId: string): TraceData | null {
    if (!RUN_ID_PATTERN.test(runId)) {
        return null
    }

    try {
        return JSON.parse(readFileSync(resolve(runsDir, `${runId}.json`), 'utf8')) as TraceData
    } catch {
        return null
    }
}
