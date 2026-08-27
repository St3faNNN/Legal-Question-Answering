import { defineStore } from 'pinia'
import { ref } from 'vue'
import { chatApi, type GraphTriple, type Message, type RetrievedChunk } from '@/api'

/**
 * The refusal marker the generation prompt emits (must match
 * ComposesGraphRagReply::REFUSAL_MARKER). It streams into the bubble like any
 * token, so it is stripped on `done`; the provisions panel is already suppressed
 * server-side, which sends empty `top_chunks`.
 */
const REFUSAL_MARKER = /\s*\[\[NO_SOURCE\]\]\s*/g

export const useChatStore = defineStore('chat', () => {
    const messages = ref<Message[]>([])
    const sending = ref(false)
    const error = ref<string | null>(null)
    /**
     * What the worker is doing right now, from the stream's `status` events.
     * Retrieval alone runs for tens of seconds on CPU, so a bare spinner leaves
     * the user unable to tell progress from a hang.
     */
    const status = ref<string | null>(null)

    /**
     * Send a message and stream the assistant reply token-by-token. The POST
     * returns immediately with a stream id; an EventSource then relays the
     * generated chunks (produced by the queued job), which we append to a live
     * assistant bubble.
     */
    async function send(message: string, conversationId?: number) {
        sending.value = true
        error.value = null
        status.value = null

        // Optimistic user bubble (negative id = local-only) so the thread and
        // the "Thinking..." indicator appear immediately.
        const optimistic: Message = {
            id: -Date.now(),
            conversation_id: conversationId ?? 0,
            role: 'user',
            content: message,
            model: null,
            tokens: null,
            latency_ms: null,
            metadata: null,
            created_at: new Date().toISOString(),
        }
        messages.value.push(optimistic)

        try {
            const { conversation, stream_id } = await chatApi.stream(message, conversationId)
            optimistic.conversation_id = conversation.id
            await streamReply(stream_id)
            return { conversation }
        } catch (e: any) {
            // Roll back the optimistic user bubble so the user can retry (the
            // streaming assistant bubble cleans itself up in streamReply).
            messages.value = messages.value.filter((m) => m.id !== optimistic.id)
            error.value = e?.message ?? 'Failed to send message'
            throw e
        } finally {
            sending.value = false
            status.value = null
        }
    }

    /**
     * Subscribe to the SSE relay for a stream id. Resolves on `done`, rejects on
     * `error`. Chunks are appended to an assistant bubble created lazily on the
     * first token (so "Thinking..." shows until then). The bubble carries a
     * unique negative id so it can be cleaned up on failure.
     */
    function streamReply(streamId: string): Promise<void> {
        return new Promise<void>((resolve, reject) => {
            const source = new EventSource(`/api/v1/chat/stream/${streamId}`, { withCredentials: true })
            const bubbleId = -Date.now() - 1

            const bubbleIndex = (): number => {
                let idx = messages.value.findIndex((m) => m.id === bubbleId)
                if (idx === -1) {
                    messages.value.push({
                        id: bubbleId,
                        conversation_id: 0,
                        role: 'assistant',
                        content: '',
                        model: null,
                        tokens: null,
                        latency_ms: null,
                        metadata: null,
                        created_at: new Date().toISOString(),
                    })
                    idx = messages.value.length - 1
                }
                return idx
            }

            const fail = (err: Error): void => {
                source.close()
                messages.value = messages.value.filter((m) => m.id !== bubbleId)
                reject(err)
            }

            source.onmessage = (event: MessageEvent) => {
                const data = JSON.parse(event.data as string)
                if (data.type === 'status') {
                    status.value =
                        data.stage === 'generating'
                            ? `Се подготвува резиме од ${data.chunk_count ?? 0} одредби...`
                            : 'Пребарување на прописите...'
                } else if (data.type === 'chunk') {
                    status.value = null
                    messages.value[bubbleIndex()].content += data.text
                } else if (data.type === 'done') {
                    const live = messages.value[bubbleIndex()]
                    live.id = data.message_id ?? live.id
                    live.model = data.model ?? null
                    live.tokens = data.tokens ?? null
                    // A refusal streamed the marker into the bubble; strip it so it
                    // never shows. The panel is suppressed via empty top_chunks below.
                    live.content = live.content.replace(REFUSAL_MARKER, ' ').trim()
                    live.metadata = {
                        top_triples: (data.top_triples ?? []) as GraphTriple[],
                        top_chunks: (data.top_chunks ?? []) as RetrievedChunk[],
                        ttft_ms: (data.ttft_ms ?? null) as number | null,
                        retrieval_ms: (data.retrieval_ms ?? null) as number | null,
                        total_ms: (data.total_ms ?? null) as number | null,
                        total_latency_ms: (data.total_ms ?? null) as number | null,
                    }
                    source.close()
                    resolve()
                } else if (data.type === 'error') {
                    fail(new Error(data.message ?? 'The AI service is currently unavailable.'))
                }
            }

            source.onerror = () => fail(new Error('Connection to the assistant was lost.'))
        })
    }

    function setMessages(msgs: Message[]) {
        messages.value = msgs
    }

    function reset() {
        messages.value = []
        error.value = null
    }

    return { messages, sending, error, status, send, setMessages, reset }
})