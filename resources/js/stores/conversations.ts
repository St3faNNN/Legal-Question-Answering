import { defineStore } from 'pinia'
import { ref } from 'vue'
import { conversationApi, type Conversation } from '@/api'

export const useConversationStore = defineStore('conversations', () => {
    const conversations = ref<Conversation[]>([])
    const current = ref<Conversation | null>(null)
    const loading = ref(false)
    const nextCursor = ref<string | null>(null)

    async function fetch() {
        loading.value = true
        try {
            const res = await conversationApi.list(nextCursor.value ? { cursor: nextCursor.value } : undefined)
            conversations.value.push(...res.data)
            nextCursor.value = res.meta.next_cursor
        } finally {
            loading.value = false
        }
    }

    async function reload() {
        nextCursor.value = null
        loading.value = true
        try {
            const res = await conversationApi.list()
            // Replace the array in one shot (not clear-then-fill) to avoid
            // triggering watchers with an empty list that would make the
            // chat view think the current conversation was deleted.
            conversations.value = res.data
            nextCursor.value = res.meta.next_cursor
        } finally {
            loading.value = false
        }
    }

    async function create(title?: string): Promise<Conversation> {
        const conversation = await conversationApi.create(title)
        conversations.value.unshift(conversation)
        return conversation
    }

    async function show(id: number) {
        current.value = await conversationApi.show(id)
        return current.value
    }

    async function rename(id: number, title: string) {
        const updated = await conversationApi.rename(id, title)
        const idx = conversations.value.findIndex((c) => c.id === id)
        if (idx !== -1) conversations.value[idx] = updated
        if (current.value?.id === id) current.value = updated
    }

    async function remove(id: number) {
        await conversationApi.delete(id)
        conversations.value = conversations.value.filter((c) => c.id !== id)
        if (current.value?.id === id) current.value = null
    }

    function reset() {
        conversations.value = []
        current.value = null
        nextCursor.value = null
    }

    return { conversations, current, loading, nextCursor, fetch, reload, create, show, rename, remove, reset }
})