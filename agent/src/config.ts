import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { loadEnv } from './env.ts'

export type Config = {
    /** OpenAI-compatible base URL, e.g. Gemini's compatibility layer. */
    llmBaseUrl: string
    llmApiKey: string
    llmModel: string
    /** Laravel app base URL that serves /api/v1/tools/search-law. */
    appUrl: string
    /** Sanctum personal access token for the tool endpoint. */
    apiToken: string
    maxSteps: number
    llmTimeoutMs: number
    toolTimeoutMs: number
    /** Compress older tool results carried between steps. Default on. */
    compactHistory: boolean
    /** How long a session survives without activity, in milliseconds. */
    sessionTtlMs: number
    /** Cross-session user memory injected into the system prompt; empty = off. */
    memoryNote: string
    /** Shared secret the HTTP service (server.ts) requires; empty = service off. */
    serviceToken: string
    /** Port the HTTP service listens on. */
    servicePort: number
}

const DEFAULT_MAX_STEPS = 8
const DEFAULT_MODEL = 'gemini-2.5-flash-lite'
const DEFAULT_APP_URL = 'http://localhost:8000'

/** The repository root: two levels up from agent/src. */
export function repoRoot(): string {
    return resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
}

/**
 * Resolve configuration from the repo-root .env (overlaid by process.env).
 * Reuses the app's LLM_* keys so the agent talks to the same endpoint the chat
 * flow does. Throws a single, actionable error listing everything missing.
 */
export function loadConfig(overrides: { maxSteps?: number } = {}): Config {
    const env = loadEnv(resolve(repoRoot(), '.env'))

    const llmBaseUrl = (env.LLM_BASE_URL ?? '').replace(/\/+$/, '')
    const llmApiKey = env.LLM_API_KEY ?? ''
    const llmModel = env.LLM_MODEL ?? DEFAULT_MODEL
    const appUrl = (env.AGENT_APP_URL ?? env.APP_URL ?? DEFAULT_APP_URL).replace(/\/+$/, '')
    const apiToken = env.AGENT_API_TOKEN ?? ''
    const maxSteps = overrides.maxSteps ?? toPositiveInt(env.AGENT_MAX_STEPS, DEFAULT_MAX_STEPS)

    const missing: string[] = []
    if (llmBaseUrl === '') {
        missing.push('LLM_BASE_URL')
    }
    if (llmApiKey === '') {
        missing.push('LLM_API_KEY')
    }
    if (apiToken === '') {
        missing.push('AGENT_API_TOKEN (mint one: see agent/README.md)')
    }
    if (missing.length > 0) {
        throw new Error(`Missing configuration: ${missing.join(', ')}. Set them in the repo-root .env or the environment.`)
    }

    return {
        llmBaseUrl,
        llmApiKey,
        llmModel,
        appUrl,
        apiToken,
        maxSteps,
        llmTimeoutMs: toPositiveInt(env.AGENT_LLM_TIMEOUT_MS, 60_000),
        toolTimeoutMs: toPositiveInt(env.AGENT_TOOL_TIMEOUT_MS, 60_000),
        // On unless AGENT_COMPACT_HISTORY is explicitly "0"/"false" — lets the
        // before/after measurement toggle it without code changes.
        compactHistory: !/^(0|false|off|no)$/i.test((env.AGENT_COMPACT_HISTORY ?? '').trim()),
        sessionTtlMs: toPositiveInt(env.AGENT_SESSION_TTL_HOURS, 72) * 3_600_000,
        // Experimental cross-session memory (see the long-term-memory
        // investigation). A profile prepended to the system prompt when
        // AGENT_MEMORY_NOTE is set (inline, or "@path" to read a UTF-8 file);
        // off by default.
        memoryNote: resolveMemoryNote(env.AGENT_MEMORY_NOTE ?? ''),
        // The HTTP service's bearer secret. Only server.ts reads it; the CLI
        // does not need it, so loadConfig never treats it as required.
        serviceToken: (env.AGENT_SERVICE_TOKEN ?? '').trim(),
        // 8002, not 5060. SIP's 5060/5061 are on the WHATWG Fetch blocked-ports
        // list that undici enforces, so a Node client — a health check, a smoke
        // test — cannot fetch this service at all there: it rejects with "bad
        // port" before opening a socket. Any replacement must stay off that list.
        servicePort: toPositiveInt(env.AGENT_SERVICE_PORT, 8002),
    }
}

function resolveMemoryNote(raw: string): string {
    const value = raw.trim()
    if (!value.startsWith('@')) {
        return value
    }
    try {
        return readFileSync(resolve(repoRoot(), value.slice(1)), 'utf8').trim()
    } catch {
        return ''
    }
}

function toPositiveInt(value: string | undefined, fallback: number): number {
    const parsed = Number.parseInt(value ?? '', 10)

    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback
}
