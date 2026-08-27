import type { Config } from './config.ts'
import { fetchWithTimeout, sleep } from './http.ts'

export type ToolCall = {
    id: string
    type: 'function'
    function: { name: string; arguments: string }
}

export type AssistantMessage = {
    role: 'assistant'
    content: string | null
    tool_calls?: ToolCall[]
}

export type ChatMessage =
    | { role: 'system'; content: string }
    | { role: 'user'; content: string }
    | AssistantMessage
    | { role: 'tool'; tool_call_id: string; content: string }

export type Usage = {
    prompt_tokens: number
    completion_tokens: number
    total_tokens: number
}

export type ChatResponse = {
    choices: Array<{ message: AssistantMessage; finish_reason?: string }>
    usage?: Usage
    model?: string
}

export type ToolSchema = {
    type: 'function'
    function: { name: string; description: string; parameters: unknown }
}

/** The one seam the loop needs: turn messages + tools into a model response. */
export type ModelFn = (messages: ChatMessage[], tools: ToolSchema[]) => Promise<ChatResponse>

/**
 * One NON-streaming call to an OpenAI-compatible /chat/completions endpoint,
 * with tools. Non-streaming on purpose: the loop needs the complete tool_calls
 * array and the token usage before it can act — there is nothing to show
 * progressively. Retries once on HTTP 429 honoring Retry-After, mirroring the
 * app's OpenAiCompatibleGenerationClient.
 */
export function makeModel(config: Config): ModelFn {
    const endpoint = `${config.llmBaseUrl}/chat/completions`

    return async (messages, tools) => {
        const body = JSON.stringify({
            model: config.llmModel,
            messages,
            tools,
            tool_choice: 'auto',
        })

        for (let attempt = 0; ; attempt++) {
            const response = await fetchWithTimeout(
                endpoint,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        Authorization: `Bearer ${config.llmApiKey}`,
                    },
                    body,
                },
                config.llmTimeoutMs,
            )

            if (response.status === 429 && attempt === 0) {
                await sleep(retryAfterMs(response))
                continue
            }

            const text = await response.text()
            if (!response.ok) {
                throw new Error(`LLM HTTP ${response.status}: ${text.slice(0, 500)}`)
            }

            return JSON.parse(text) as ChatResponse
        }
    }
}

function retryAfterMs(response: Response): number {
    const header = response.headers.get('retry-after')
    const seconds = header !== null && /^\d+$/.test(header) ? Number.parseInt(header, 10) : 1

    return Math.min(Math.max(seconds, 0), 30) * 1000
}
