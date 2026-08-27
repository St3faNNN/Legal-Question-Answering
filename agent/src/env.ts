import { readFileSync } from 'node:fs'

/**
 * Minimal .env reader — no dotenv dependency. Parses KEY=VALUE lines from the
 * given file (a missing file is fine and yields an empty map), strips matching
 * surrounding quotes, and lets a real process.env value win over the file so an
 * inline override (`AGENT_MAX_STEPS=3 node …`) always takes effect.
 */
export function loadEnv(envPath: string): Record<string, string> {
    let raw = ''
    try {
        raw = readFileSync(envPath, 'utf8')
    } catch {
        raw = ''
    }

    const fromFile: Record<string, string> = {}
    for (const line of raw.split(/\r?\n/)) {
        const trimmed = line.trim()
        if (trimmed === '' || trimmed.startsWith('#')) {
            continue
        }

        const eq = trimmed.indexOf('=')
        if (eq === -1) {
            continue
        }

        const key = trimmed.slice(0, eq).trim()
        let value = trimmed.slice(eq + 1).trim()
        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1)
        }
        fromFile[key] = value
    }

    const merged: Record<string, string> = { ...fromFile }
    for (const [key, value] of Object.entries(process.env)) {
        if (value !== undefined) {
            merged[key] = value
        }
    }

    return merged
}
