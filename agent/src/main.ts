import { resolve } from 'node:path'
import { runAgent } from './agent.ts'
import { loadConfig, repoRoot } from './config.ts'
import { makeModel } from './llm.ts'
import { SYSTEM_PROMPT } from './prompt.ts'
import {
    acquireLock,
    loadSession,
    newSession,
    releaseLock,
    saveSession,
    transcript,
    type Session,
} from './session.ts'
import { allToolSchemas, makeToolExecutor } from './tools.ts'
import { Trace, type TraceData } from './trace.ts'

async function main(): Promise<void> {
    const { question, maxSteps, sessionId } = parseArgs(process.argv.slice(2))
    if (question === null) {
        console.error('Usage: node src/main.ts "<question>" [--session <id>] [--max-steps N]')
        process.exit(1)
    }

    const config = loadConfig(maxSteps !== undefined ? { maxSteps } : {})
    const runsDir = resolve(repoRoot(), 'agent', 'runs')
    const sessionsDir = resolve(repoRoot(), 'agent', 'sessions')

    // Cross-session memory (experimental): a user profile prepended to the
    // system prompt. Off unless AGENT_MEMORY_NOTE is set. See the investigation.
    const systemPrompt = config.memoryNote
        ? `[Долгорочна меморија за корисникот]\n${config.memoryNote}\n\n${SYSTEM_PROMPT}`
        : SYSTEM_PROMPT

    // Single-shot mode (no --session): unchanged behaviour, no session file.
    if (sessionId === null) {
        const trace = new Trace(runsDir, question, config.llmModel, config.maxSteps)
        console.error(`run ${trace.data.runId}  ·  model ${config.llmModel}  ·  budget ${config.maxSteps} steps`)
        const result = await runAgent({
            question,
            systemPrompt,
            tools: allToolSchemas,
            callModel: makeModel(config),
            executeTool: makeToolExecutor(config),
            maxSteps: config.maxSteps,
            trace,
            compact: config.compactHistory,
        })
        printSummary(trace, result)
        console.log(result.finalAnswer ?? '(no answer)')
        return
    }

    // Session mode: reject a concurrent turn on the same id (see session.ts).
    if (!acquireLock(sessionsDir, sessionId)) {
        console.error(`session ${sessionId} is busy — another turn is in progress. Try again when it finishes.`)
        process.exit(1)
    }

    try {
        // Reconnection: a fresh process resumes the id by reading the file back.
        const session: Session = loadSession(sessionsDir, sessionId, config.sessionTtlMs) ?? newSession(sessionId)
        const turnNumber = session.turns.length + 1
        const trace = new Trace(runsDir, question, config.llmModel, config.maxSteps)
        console.error(
            `session ${sessionId}  ·  turn ${turnNumber}  ·  ${session.turns.length} prior turn(s) carried  ·  run ${trace.data.runId}`,
        )

        const result = await runAgent({
            question,
            systemPrompt,
            tools: allToolSchemas,
            callModel: makeModel(config),
            executeTool: makeToolExecutor(config),
            maxSteps: config.maxSteps,
            trace,
            compact: config.compactHistory,
            history: transcript(session),
        })

        const answer = result.finalAnswer ?? '(no answer)'
        session.turns.push({
            question,
            answer,
            runId: trace.data.runId,
            citations: citationsIn(answer),
            at: new Date().toISOString(),
        })
        saveSession(sessionsDir, session)

        printSummary(trace, result)
        console.error(`session ${sessionId}  ·  saved turn ${turnNumber}`)
        console.log(answer)
    } finally {
        releaseLock(sessionsDir, sessionId)
    }
}

function printSummary(trace: Trace, result: TraceData): void {
    console.error('')
    console.error(
        `outcome ${result.outcome}  ·  steps ${result.steps.length}` +
            `  ·  Gemini calls ${result.totals.apiCalls}  ·  tokens ${result.totals.totalTokens}`,
    )
    console.error(`trace   ${trace.path()}`)
    console.error(`inspect node src/inspector.ts, then open http://localhost:5055/#${trace.data.runId}`)
    console.error('')
}

/** Distinct article citations named in an answer — recorded for inspection. */
function citationsIn(answer: string): string[] {
    return [...new Set([...answer.matchAll(/чл\.\s?\d+[^,)\s]*/g)].map((match) => match[0]))]
}

function parseArgs(argv: string[]): { question: string | null; maxSteps: number | undefined; sessionId: string | null } {
    let question: string | null = null
    let maxSteps: number | undefined
    let sessionId: string | null = null

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i] ?? ''
        if (arg === '--max-steps') {
            maxSteps = toPositiveInt(argv[++i])
        } else if (arg.startsWith('--max-steps=')) {
            maxSteps = toPositiveInt(arg.slice('--max-steps='.length))
        } else if (arg === '--session') {
            sessionId = argv[++i] ?? null
        } else if (arg.startsWith('--session=')) {
            sessionId = arg.slice('--session='.length)
        } else if (question === null) {
            question = arg
        }
    }

    return { question, maxSteps, sessionId }
}

function toPositiveInt(value: string | undefined): number | undefined {
    const parsed = Number.parseInt(value ?? '', 10)

    return Number.isFinite(parsed) && parsed > 0 ? parsed : undefined
}

main().catch((error: unknown) => {
    console.error(error instanceof Error ? error.stack ?? error.message : String(error))
    process.exit(1)
})
