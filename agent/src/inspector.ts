import { readFileSync } from 'node:fs'
import { createServer } from 'node:http'
import type { ServerResponse } from 'node:http'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { listRuns, readRun } from './trace.ts'

const here = dirname(fileURLToPath(import.meta.url))
const runsDir = resolve(here, '..', 'runs')
const htmlPath = resolve(here, '..', 'public', 'inspector.html')
const port = Number.parseInt(process.env.INSPECTOR_PORT ?? '5055', 10)

/**
 * A tiny read-only server for reading trajectories. No build step, no
 * framework: it serves one static HTML page plus two JSON routes it reads from.
 */
const server = createServer((request, response) => {
    const url = new URL(request.url ?? '/', 'http://localhost')

    if (url.pathname === '/' || url.pathname === '/index.html') {
        sendFile(response, htmlPath)
        return
    }

    if (url.pathname === '/api/runs') {
        sendJson(response, 200, { runs: listRuns(runsDir) })
        return
    }

    if (url.pathname === '/api/run') {
        const run = readRun(runsDir, url.searchParams.get('id') ?? '')
        if (run) {
            sendJson(response, 200, run)
        } else {
            sendJson(response, 404, { error: 'run not found' })
        }
        return
    }

    sendJson(response, 404, { error: 'not found' })
})

server.listen(port, () => {
    console.log(`trace inspector: http://localhost:${port}`)
    console.log(`reading runs from ${runsDir}`)
})

function sendJson(response: ServerResponse, status: number, body: unknown): void {
    response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' })
    response.end(JSON.stringify(body))
}

function sendFile(response: ServerResponse, path: string): void {
    try {
        const html = readFileSync(path)
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
        response.end(html)
    } catch {
        response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
        response.end('inspector.html not found')
    }
}
