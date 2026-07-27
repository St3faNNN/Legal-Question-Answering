import { defineStore } from 'pinia'
import { ref } from 'vue'
import { chatApi, type GraphTriple, type Message } from '@/api'

export const useChatStore = defineStore('chat', () => {
    const messages = ref<Message[]>([])
    const sending = ref(false)
    const error = ref<string | null>(null)

    /**
     * Send a message and stream the assistant reply token-by-token. The POST
     * returns immediately with a stream id; an EventSource then relays the
     * generated chunks (produced by the queued job), which we append to a live
     * assistant bubble.
     */
    async function send(message: string, conversationId?: number) {
        sending.value = true
        error.value = null

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
                if (data.type === 'chunk') {
                    messages.value[bubbleIndex()].content += data.text
                } else if (data.type === 'done') {
                    const live = messages.value[bubbleIndex()]
                    live.id = data.message_id ?? live.id
                    live.model = data.model ?? null
                    live.tokens = data.tokens ?? null
                    live.metadata = { top_triples: (data.top_triples ?? []) as GraphTriple[] }
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

    return { messages, sending, error, send, setMessages, reset }
})