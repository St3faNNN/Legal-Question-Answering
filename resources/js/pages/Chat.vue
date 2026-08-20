<script setup lang="ts">
import { computed, onMounted, ref, nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import AssistantResponse from '@/components/AssistantResponse.vue'
import { useChatStore } from '@/stores/chat'
import { useConversationStore } from '@/stores/conversations'
import type { Message } from '@/api'

const route = useRoute()
const router = useRouter()
const chat = useChatStore()
const conversations = useConversationStore()

const input = ref('')
const thread = ref<HTMLElement | null>(null)
const dropdownOpen = ref(false)
const currentConversationId = ref<number | undefined>(undefined)

// Show "Thinking..." only until the first streamed token arrives — once the
// assistant bubble starts filling, it becomes the progress indicator.
const awaitingFirstToken = computed(
    () => chat.sending && (chat.messages.length === 0 || chat.messages[chat.messages.length - 1].role === 'user'),
)

// Keep the newest tokens in view as the reply streams in.
watch(() => chat.messages[chat.messages.length - 1]?.content, () => {
    if (chat.sending) scrollToBottom()
})

async function loadConversation(id: number) {
    currentConversationId.value = id
    chat.reset()
    const conv = await conversations.show(id)
    if (conv.messages) {
        chat.setMessages(conv.messages)
    }
    await scrollToBottom()
}

function startNewChat() {
    dropdownOpen.value = false
    currentConversationId.value = undefined
    conversations.current = null
    chat.reset()
    router.push({ name: 'chat' })
}

async function switchConversation(id: number) {
    dropdownOpen.value = false
    router.push({ name: 'chat-conversation', params: { id } })
}

// Guard: if the conversation we're viewing gets deleted, reset.
watch(() => conversations.conversations, (list) => {
    if (currentConversationId.value !== undefined) {
        const stillExists = list.some((c) => c.id === currentConversationId.value)
        if (!stillExists) {
            startNewChat()
        }
    }
}, { deep: true })

onMounted(async () => {
    await conversations.reload()
    if (route.params.id) {
        const exists = conversations.conversations.some((c) => c.id === Number(route.params.id))
        if (exists) {
            await loadConversation(Number(route.params.id))
        } else {
            startNewChat()
        }
    } else {
        currentConversationId.value = undefined
        chat.reset()
    }
})

// Only reload from the API when the route param actually changes to a
// *different* conversation. When we just sent the first message and
// set currentConversationId ourselves, the watcher sees it already
// matches and skips the reset — preserving the optimistic messages.
watch(() => route.params.id, async (newId) => {
    const id = newId ? Number(newId) : undefined
    if (id === currentConversationId.value) return

    if (id) {
        await loadConversation(id)
    } else {
        currentConversationId.value = undefined
        conversations.current = null
        chat.reset()
    }
})

async function handleSubmit() {
    if (!input.value.trim() || chat.sending) return
    const message = input.value
    input.value = ''

    // Kick off the send, then scroll immediately: the optimistic user bubble
    // is pushed synchronously, so it (and the "Thinking..." indicator) are in
    // the DOM before the reply arrives.
    const pending = chat.send(message, currentConversationId.value)
    await scrollToBottom()

    try {
        const result = await pending
        if (!currentConversationId.value) {
            // First message in a new chat — a conversation was created.
            // Set the id BEFORE navigating so the route watcher sees it
            // already matches and doesn't reset the chat messages.
            currentConversationId.value = result.conversation.id
            conversations.current = result.conversation
            router.replace({ name: 'chat-conversation', params: { id: result.conversation.id } })
            // Refresh the sidebar list (non-blocking)
            conversations.reload()
        }
        await scrollToBottom()
    } catch {
        // Send failed and the optimistic bubble was rolled back — restore the
        // text so the user can retry. Error is shown via chat.error.
        input.value = message
    }
}

async function scrollToBottom() {
    await nextTick()
    if (thread.value) {
        thread.value.scrollTop = thread.value.scrollHeight
    }
}

function conversationLabel(conv: { title: string | null; display_title?: string; id: number }) {
    return conv.title ?? conv.display_title ?? `Conversation ${conv.id}`
}

// The actively-streaming assistant bubble: the last message, still filling, with
// no metadata yet (retrieval results land with the `done` event). Passed to
// AssistantResponse so it shows a caret and the summary-only streaming state.
function isStreaming(msg: Message): boolean {
    if (msg.role !== 'assistant') {
        return false
    }
    const last = chat.messages[chat.messages.length - 1]
    return chat.sending && last?.id === msg.id && !msg.metadata
}
</script>

<template>
    <AppShell>
        <div class="max-w-4xl mx-auto h-[calc(100vh-8rem)] flex flex-col">
            <!-- Header with conversation dropdown -->
            <div class="flex items-center justify-between mb-4">
                <div class="relative">
                    <button
                        @click="dropdownOpen = !dropdownOpen"
                        class="flex items-center gap-2 text-2xl font-bold text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
                    >
                        {{ conversations.current?.title ?? (currentConversationId ? `Conversation ${currentConversationId}` : 'New Chat') }}
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="dropdownOpen ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
                        </svg>
                    </button>

                    <!-- Dropdown -->
                    <div
                        v-if="dropdownOpen"
                        class="absolute top-full left-0 mt-2 w-80 max-h-96 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg z-40"
                    >
                        <button
                            @click="startNewChat"
                            class="w-full flex items-center gap-3 px-4 py-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors border-b border-gray-100 dark:border-gray-800"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            New Chat
                        </button>

                        <div v-if="conversations.conversations.length === 0" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No conversations yet
                        </div>
                        <button
                            v-for="conv in conversations.conversations"
                            :key="conv.id"
                            @click="switchConversation(conv.id)"
                            class="w-full text-left px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors border-b border-gray-50 dark:border-gray-800/50 last:border-0"
                            :class="conv.id === currentConversationId ? 'bg-indigo-50 dark:bg-indigo-900/20' : ''"
                        >
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ conversationLabel(conv) }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ conv.updated_at }}</p>
                        </button>
                    </div>
                </div>

                <button
                    v-if="currentConversationId"
                    @click="startNewChat"
                    class="px-4 py-2 rounded-lg text-sm font-semibold text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition-colors"
                >
                    + New Chat
                </button>
            </div>

            <!-- Click-away backdrop for dropdown -->
            <div
                v-if="dropdownOpen"
                class="fixed inset-0 z-30"
                @click="dropdownOpen = false"
            />

            <!-- Chat thread -->
            <div ref="thread" class="flex-1 overflow-y-auto space-y-4 pb-4">
                <div v-if="chat.messages.length === 0" class="h-full flex items-center justify-center">
                    <p class="text-gray-500 dark:text-gray-400 text-sm">Send a message to start the conversation.</p>
                </div>
                <div
                    v-for="msg in chat.messages"
                    :key="msg.id"
                    class="flex"
                    :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
                >
                    <!-- User turn: a plain chat bubble. -->
                    <div
                        v-if="msg.role === 'user'"
                        class="px-4 py-3 rounded-2xl max-w-xs sm:max-w-md bg-indigo-600 dark:bg-indigo-700 text-white rounded-br-sm"
                    >
                        <p class="text-sm whitespace-pre-wrap">{{ msg.content }}</p>
                    </div>

                    <!-- Assistant turn: the retrieved provisions lead, with the
                         machine-generated summary subordinate and a persistent
                         notice. Wider than the user bubble — the article text
                         shown open needs the room. -->
                    <AssistantResponse v-else :msg="msg" :streaming="isStreaming(msg)" />
                </div>
                <div v-if="awaitingFirstToken" class="flex justify-start">
                    <div class="bg-gray-200 dark:bg-gray-800 text-gray-500 dark:text-gray-400 px-4 py-3 rounded-2xl rounded-bl-sm">
                        <p class="text-sm">{{ chat.status ?? 'Се обработува...' }}</p>
                    </div>
                </div>
                <p v-if="chat.error" class="text-sm text-red-600 dark:text-red-400 text-center">{{ chat.error }}</p>
            </div>

            <!-- Message input -->
            <div class="border-t border-gray-200 dark:border-gray-800 pt-4">
                <form @submit.prevent="handleSubmit" class="flex gap-3">
                    <input
                        v-model="input"
                        type="text"
                        placeholder="Type your message..."
                        :disabled="chat.sending"
                        class="flex-1 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-2.5 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    />
                    <button
                        type="submit"
                        :disabled="chat.sending || !input.trim()"
                        class="px-6 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 dark:bg-indigo-500 hover:bg-indigo-700 dark:hover:bg-indigo-600 disabled:opacity-50 transition-colors"
                    >
                        Send
                    </button>
                </form>

                <!-- Permanent free-tier data-use notice, shown where the decision
                     to send is made (not only on the policy page). Not dismissable. -->
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400 text-center leading-relaxed">
                    Прашањата се обработуваат од Google (Gemini) и може да се користат за подобрување на нивните
                    модели. Не внесувајте доверливи или лични податоци.
                    <span class="block text-gray-400 dark:text-gray-500">
                        Questions are processed by Google (Gemini) and may be used to improve their models. Do not
                        enter confidential or personal information.
                    </span>
                </p>
            </div>
        </div>
    </AppShell>
</template>