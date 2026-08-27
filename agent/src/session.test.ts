import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { test } from 'node:test'
import { acquireLock, loadSession, newSession, releaseLock, saveSession, transcript, type Session } from './session.ts'

function tempDir(): string {
    return mkdtempSync(resolve(tmpdir(), 'agent-session-'))
}

const HOUR = 3_600_000

test('saves and reloads a session (reconnection from a fresh read)', () => {
    const dir = tempDir()
    const session = newSession('matter-1')
    session.turns.push({ question: 'Q1', answer: 'A1', runId: 'r1', citations: ['чл. 55'], at: new Date().toISOString() })
    saveSession(dir, session)

    const loaded = loadSession(dir, 'matter-1', 72 * HOUR)
    assert.ok(loaded)
    assert.equal(loaded!.turns.length, 1)
    assert.equal(loaded!.turns[0]!.answer, 'A1')
})

test('transcript carries prior questions and answers only', () => {
    const session: Session = newSession('m')
    session.turns.push({ question: 'Q1', answer: 'A1', runId: 'r1', citations: [], at: '' })
    session.turns.push({ question: 'Q2', answer: 'A2', runId: 'r2', citations: [], at: '' })

    assert.deepEqual(transcript(session), [
        { question: 'Q1', answer: 'A1' },
        { question: 'Q2', answer: 'A2' },
    ])
})

test('an expired session is dropped on load', () => {
    const dir = tempDir()
    // saveSession always stamps lastActiveAt to now, so write a stale file directly.
    const stale: Session = {
        id: 'old',
        createdAt: new Date(Date.now() - 100 * HOUR).toISOString(),
        lastActiveAt: new Date(Date.now() - 100 * HOUR).toISOString(),
        turns: [{ question: 'Q', answer: 'A', runId: 'r', citations: [], at: '' }],
    }
    writeFileSync(resolve(dir, 'old.json'), JSON.stringify(stale), 'utf8')

    assert.equal(loadSession(dir, 'old', 72 * HOUR), null)
})

test('missing session loads as null; unknown ids are never created implicitly', () => {
    assert.equal(loadSession(tempDir(), 'nope', 72 * HOUR), null)
})

test('rejects an invalid session id (path-traversal guard)', () => {
    assert.throws(() => loadSession(tempDir(), '../evil', 72 * HOUR), /invalid session id/)
})

test('lock rejects a concurrent turn, and releases cleanly', () => {
    const dir = tempDir()
    assert.equal(acquireLock(dir, 's'), true) // first turn holds it
    assert.equal(acquireLock(dir, 's'), false) // second turn is rejected
    releaseLock(dir, 's')
    assert.equal(acquireLock(dir, 's'), true) // free again
    releaseLock(dir, 's')
})
