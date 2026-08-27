import { closeSync, mkdirSync, openSync, readFileSync, renameSync, unlinkSync, writeFileSync, writeSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Multi-turn sessions, file-backed. A session is just the conversation
 * transcript — the user's questions and the agent's final answers. The
 * investigation showed a follow-up re-retrieves what it needs (the strict
 * prompt makes it), so intermediate tool calls and passage text are NOT carried;
 * only the transcript, which the model uses to resolve references ("those
 * deadlines", "that procedure") and to phrase the next search.
 *
 * Storage is a plain JSON file per session (agent/sessions/<id>.json), chosen
 * over Redis because the agent is a single-user local CLI with zero runtime
 * dependencies — a Redis client would be the project's only npm dependency, for
 * state a file holds just as well. The file also gives reconnection for free: a
 * fresh process resumes a session id by reading it back.
 */

export type SessionTurn = {
    question: string
    answer: string
    runId: string
    citations: string[]
    at: string
}

export type Session = {
    id: string
    createdAt: string
    lastActiveAt: string
    turns: SessionTurn[]
}

const SESSION_ID = /^[A-Za-z0-9_-]{1,64}$/
const LOCK_STALE_MS = 900_000 // 15 min > the 8-step × 60s worst-case turn

function assertId(id: string): void {
    if (!SESSION_ID.test(id)) {
        throw new Error(`invalid session id: ${JSON.stringify(id)} (allowed: letters, digits, "-", "_", ≤64 chars)`)
    }
}

function sessionPath(dir: string, id: string): string {
    return resolve(dir, `${id}.json`)
}

function lockPath(dir: string, id: string): string {
    return resolve(dir, `${id}.lock`)
}

/**
 * Load a session, or null if absent or expired. Expiry is lazy: a session
 * untouched for longer than ttlMs is deleted on access rather than lingering as
 * a leak (a periodic prune would also work, but lazy expiry needs no scheduler).
 */
export function loadSession(dir: string, id: string, ttlMs: number): Session | null {
    assertId(id)
    let session: Session
    try {
        session = JSON.parse(readFileSync(sessionPath(dir, id), 'utf8')) as Session
    } catch {
        return null
    }

    if (Date.now() - Date.parse(session.lastActiveAt) > ttlMs) {
        try {
            unlinkSync(sessionPath(dir, id))
        } catch {
            /* already gone */
        }
        return null
    }

    return session
}

export function newSession(id: string): Session {
    assertId(id)
    const now = new Date().toISOString()

    return { id, createdAt: now, lastActiveAt: now, turns: [] }
}

/** Append a turn and persist atomically (temp file + rename). */
export function saveSession(dir: string, session: Session): void {
    assertId(session.id)
    session.lastActiveAt = new Date().toISOString()
    mkdirSync(dir, { recursive: true })

    const temp = sessionPath(dir, `${session.id}.tmp-${process.pid}`)
    writeFileSync(temp, JSON.stringify(session, null, 2), 'utf8')
    renameSync(temp, sessionPath(dir, session.id))
}

/** Prior turns as {question, answer} pairs — what the next turn carries forward. */
export function transcript(session: Session): Array<{ question: string; answer: string }> {
    return session.turns.map((turn) => ({ question: turn.question, answer: turn.answer }))
}

/**
 * Concurrency policy: REJECT a second turn on a session already running one.
 * A conversation is inherently serial — two turns racing on the same id would
 * both read the same history and both append, silently losing one (last-write-
 * wins). Rejecting is safe and matches how a person actually converses. A
 * classic exclusive-create lockfile; a lock older than LOCK_STALE_MS is assumed
 * abandoned by a crashed process and broken.
 *
 * Returns true if the lock was acquired, false if a live turn holds it.
 */
export function acquireLock(dir: string, id: string): boolean {
    assertId(id)
    mkdirSync(dir, { recursive: true })
    const path = lockPath(dir, id)

    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            const fd = openSync(path, 'wx') // exclusive create — fails if it exists
            writeSync(fd, JSON.stringify({ pid: process.pid, at: Date.now() }))
            closeSync(fd)

            return true
        } catch {
            // Lock exists — break it only if stale, otherwise a live turn holds it.
            let heldAt = Date.now()
            try {
                heldAt = (JSON.parse(readFileSync(path, 'utf8')) as { at?: number }).at ?? 0
            } catch {
                heldAt = 0 // corrupt lock — treat as stale
            }
            if (Date.now() - heldAt > LOCK_STALE_MS) {
                try {
                    unlinkSync(path)
                } catch {
                    /* someone else broke it first */
                }
                continue
            }

            return false
        }
    }

    return false
}

export function releaseLock(dir: string, id: string): void {
    try {
        unlinkSync(lockPath(dir, id))
    } catch {
        /* already released */
    }
}
