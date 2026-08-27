/**
 * Shared HTTP helpers built on the global fetch — no dependency. A hard
 * per-request deadline via AbortController keeps a hung upstream (Gemini or the
 * tool endpoint) from stalling the whole run.
 */
export async function fetchWithTimeout(url: string, init: RequestInit, timeoutMs: number): Promise<Response> {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), timeoutMs)

    try {
        return await fetch(url, { ...init, signal: controller.signal })
    } finally {
        clearTimeout(timer)
    }
}

export function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms))
}
