import { createServer } from 'node:http'
import type { IncomingMessage, ServerResponse } from 'node:http'
import { resolve } from 'node:path'
import { runAgent } from './agent.ts'
import { loadConfig, repoRoot } from './config.ts'
import { makeModel } from './llm.ts'
import { SYSTEM_PROMPT } from './prompt.ts'
import { allToolSchemas, makeToolExecutor } from './tools.ts'
import { Trace, type TraceData, type TraceEvent } from './trace.ts'

/**
 * The agent as an HTTP service, so the Laravel app can escalate a question to it.
 *
 * This is a *transport* around the existing CLI, not a second implementation:
 * `POST /run` calls the same `runAgent` loop, with the same prompt, tools and
 * trace, and writes the same `agent/runs/<runId>.json`. Nothing in agent.ts
 * changed to make this work — the loop is the artefact and stays untouched.
 *
 * The response is NDJSON streamed as the run happens, because the caller needs
 * the searches while they are being issued (~7s of them) rather than only at
 * the end:
 *
 *   {"type":"search","step":0,"tool":"search_law","query":"технолошки вишок"}
 *   {"type":"result","step":0,"tool":"search_law","sources":["ZAKON-…, чл. 96", …]}
 *   {"type":"done","runId":"…","answer":"…","passages":[…],"totals":{…}}
 *
 * Zero dependencies, like the rest of the agent: node:http and the global fetch.
 */

const config = loadConfig()
const runsDir = resolve(repoRoot(), 'agent', 'runs')

/** Bound on a request body, well above the app's 10k-char message limit. */
const MAX_BODY_BYTES = 64 * 1024

const server = createServer((request, response) => {
    const url = new URL(request.url ?? '/', 'http://localhost')

    if (request.method === 'GET' && url.pathname === '/health') {
        sendJson(response, 200, { ok: true, model: config.llmModel, maxSteps: config.maxSteps })

        return
    }

    if (request.method !== 'POST' || url.pathname !== '/run') {
        sendJson(response, 404, { error: 'not found' })

        return
    }

    // The service is not published outside the compose network, but it can spend
    // real tokens, so it still refuses an unauthenticated caller.
    if (!authorized(request)) {
        sendJson(response, 401, { error: 'unauthorized' })

        return
    }

    readBody(request)
        .then((body) => handleRun(body, response))
        .catch((error: unknown) => sendJson(response, 400, { error: String(error) }))
})

server.listen(config.servicePort, () => {
    console.log(`agent service: http://0.0.0.0:${config.servicePort}`)
    console.log(`  model ${config.llmModel} · budget ${config.maxSteps} steps · tools at ${config.appUrl}`)
    console.log(`  writing traces to ${runsDir}`)
})

async function handleRun(body: string, response: ServerResponse): Promise<void> {
    let question: string
    let maxSteps: number
    try {
        const parsed = JSON.parse(body === '' ? '{}' : body) as { question?: unknown; maxSteps?: unknown }
        if (typeof parsed.question !== 'string' || parsed.question.trim() === '') {
            sendJson(response, 422, { error: 'the "question" field is required and must be a non-empty string' })

            return
        }
        question = parsed.question
        maxSteps = typeof parsed.maxSteps === 'number' && parsed.maxSteps > 0 ? Math.floor(parsed.maxSteps) : config.maxSteps
    } catch {
        sendJson(response, 400, { error: 'body was not valid JSON' })

        return
    }

    // Headers go out before the first model call, so the caller's read deadline
    // starts on a live connection rather than on a pending one.
    response.writeHead(200, {
        'Content-Type': 'application/x-ndjson; charset=utf-8',
        'Cache-Control': 'no-cache',
        'X-Accel-Buffering': 'no',
    })

    const write = (event: unknown): void => {
        response.write(`${JSON.stringify(event)}\n`)
    }

    try {
        const trace = new Trace(runsDir, question, config.llmModel, maxSteps, (event: TraceEvent) => {
            // search events carry the raw JSON argument string; unwrap it to the
            // one field a progress line needs, so the caller never parses tool
            // arguments itself.
            write(event.type === 'search' ? { ...event, query: describeArgs(event.args) } : event)
        })

        const result = await runAgent({
            question,
            systemPrompt: SYSTEM_PROMPT,
            tools: allToolSchemas,
            callModel: makeModel(config),
            executeTool: makeToolExecutor(config),
            maxSteps,
            trace,
            compact: config.compactHistory,
        })

        write({
            type: 'done',
            runId: result.runId,
            outcome: result.outcome,
            answer: result.finalAnswer ?? '',
            model: result.model,
            totals: result.totals,
            passages: passagesOf(result),
            steps: stepSummary(result),
        })
    } catch (error) {
        // A thrown run (the model endpoint is down, a 429 that outlived its one
        // retry) still terminates the NDJSON stream with a readable event, so the
        // caller fails on data rather than on a bare disconnect.
        write({ type: 'error', message: error instanceof Error ? error.message : String(error) })
    } finally {
        response.end()
    }
}

/** The searchable text of a tool call: the query, or the article being followed. */
function describeArgs(argsJson: string): string {
    try {
        const args = JSON.parse(argsJson === '' ? '{}' : argsJson) as { query?: unknown; law?: unknown; article?: unknown }
        if (typeof args.query === 'string') {
            return args.query
        }
        if (args.law !== undefined || args.article !== undefined) {
            return `${String(args.law ?? '')}, чл. ${String(args.article ?? '')}`
        }
    } catch {
        // fall through to the raw string
    }

    return argsJson
}

/**
 * Every distinct passage the run retrieved, best score first. This is the pool
 * the caller matches the answer's citations against — the agent cites per claim,
 * so the provisions it actually used are a subset of this, resolvable by
 * `source`. Deduplicated because a passage returned by two searches is one
 * provision, not two.
 */
function passagesOf(result: TraceData): Array<{ id: string; source: string; text: string; score: number | null }> {
    const best = new Map<string, { id: string; source: string; text: string; score: number | null }>()

    for (const step of result.steps) {
        for (const toolResult of step.toolResults) {
            const rows = (toolResult.result as { results?: unknown })?.results
            if (!Array.isArray(rows)) {
                continue
            }

            for (const row of rows as Array<Record<string, unknown>>) {
                const source = String(row.source ?? '')
                if (source === '') {
                    continue
                }

                const score = typeof row.score === 'number' ? row.score : null
                const existing = best.get(source)
                if (existing === undefined || (score ?? 0) > (existing.score ?? 0)) {
                    best.set(source, { id: String(row.id ?? ''), source, text: String(row.text ?? ''), score })
                }
            }
        }
    }

    return [...best.values()].sort((a, b) => (b.score ?? 0) - (a.score ?? 0))
}

/**
 * A per-step summary for the persisted trace: what was called, with what, and
 * which citations came back. Passage bodies are omitted here — they travel once,
 * in `passages`, rather than repeated per step.
 */
function stepSummary(result: TraceData): Array<Record<string, unknown>> {
    return result.steps.map((step) => ({
        index: step.index,
        durationMs: step.durationMs,
        tokens: step.usage?.total_tokens ?? null,
        reasoning: step.assistant.content,
        calls: step.assistant.tool_calls.map((call) => {
            const match = step.toolResults.find((toolResult) => toolResult.tool_call_id === call.id)
            const rows = (match?.result as { results?: unknown; references?: unknown }) ?? {}
            const passages = Array.isArray(rows.results) ? rows.results : []
            const references = Array.isArray(rows.references) ? rows.references : []

            return {
                tool: call.function.name,
                query: describeArgs(call.function.arguments),
                sources: [...passages, ...references].map((row: unknown) => {
                    const record = (row ?? {}) as { source?: unknown; citation?: unknown }

                    return String(record.source ?? record.citation ?? '')
                }),
                error: (match?.result as { error?: unknown })?.error !== undefined
                    ? String((match?.result as { error?: unknown }).error)
                    : null,
            }
        }),
    }))
}

function authorized(request: IncomingMessage): boolean {
    if (config.serviceToken === '') {
        return false // no secret configured = the service accepts nobody
    }

    return request.headers.authorization === `Bearer ${config.serviceToken}`
}

function readBody(request: IncomingMessage): Promise<string> {
    return new Promise((resolveBody, rejectBody) => {
        const chunks: Buffer[] = []
        let size = 0

        request.on('data', (chunk: Buffer) => {
            size += chunk.length
            if (size > MAX_BODY_BYTES) {
                rejectBody(new Error('request body too large'))
                request.destroy()

                return
            }
            chunks.push(chunk)
        })
        request.on('end', () => resolveBody(Buffer.concat(chunks).toString('utf8')))
        request.on('error', rejectBody)
    })
}

function sendJson(response: ServerResponse, status: number, body: unknown): void {
    response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' })
    response.end(JSON.stringify(body))
}
