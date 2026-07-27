<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import { useConversationStore } from '@/stores/conversations'

const router = useRouter()
const conversations = useConversationStore()
const editingId = ref<number | null>(null)
const editTitle = ref('')

onMounted(async () => {
    await conversations.reload()
})

function startEdit(id: number, currentTitle: string) {
    editingId.value = id
    editTitle.value = currentTitle
}

async function saveEdit(id: number) {
    if (editTitle.value.trim()) {
        await conversations.rename(id, editTitle.value.trim())
    }
    editingId.value = null
}

async function handleDelete(id: number) {
    if (confirm('Delete this conversation? This action cannot be undone.')) {
        await conversations.remove(id)
    }
}
</script>

<template>
    <AppShell>
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Conversation History</h1>
                <RouterLink :to="{ name: 'chat' }"
                    class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors">
                    New Chat
                </RouterLink>
            </div>

            <div v-if="conversations.loading" class="text-sm text-gray-500">Loading...</div>
            <div v-else-if="conversations.conversations.length === 0"
                class="p-8 text-center border border-dashed border-gray-300 dark:border-gray-700 rounded-xl">
                <p class="text-sm text-gray-500 dark:text-gray-400">No conversations yet. Start a new chat to get going!</p>
            </div>
            <div v-else class="space-y-2">
                <div
                    v-for="conv in conversations.conversations"
                    :key="conv.id"
                    class="flex items-center justify-between p-4 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:shadow-sm transition-shadow"
                >
                    <div class="flex-1 min-w-0">
                        <div v-if="editingId === conv.id">
                            <input
                                v-model="editTitle"
                                @keyup.enter="saveEdit(conv.id)"
                                @keyup.esc="editingId = null"
                                class="w-full rounded border border-indigo-300 dark:border-indigo-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-2 py-1 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                autofocus
                            />
                        </div>
                        <div v-else>
                            <RouterLink :to="{ name: 'chat-conversation', params: { id: conv.id } }"
                                class="block text-sm font-medium text-gray-900 dark:text-gray-100 truncate hover:text-indigo-600 dark:hover:text-indigo-400">
                                {{ conv.title ?? conv.display_title ?? `Conversation ${conv.id}` }}
                            </RouterLink>
                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-0.5">{{ conv.updated_at }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 ml-4">
                        <button v-if="editingId === conv.id" @click="saveEdit(conv.id)"
                            class="text-sm text-green-600 hover:text-green-700">Save</button>
                        <button v-else @click="startEdit(conv.id, conv.title ?? conv.display_title ?? `Conversation ${conv.id}`)"
                            class="text-sm text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400">Rename</button>
                        <button @click="handleDelete(conv.id)"
                            class="text-sm text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400">Delete</button>
                    </div>
                </div>
                <button
                    v-if="conversations.nextCursor"
                    @click="conversations.fetch()"
                    :disabled="conversations.loading"
                    class="w-full py-3 text-sm text-indigo-600 hover:text-indigo-700 disabled:opacity-50"
                >
                    Load more
                </button>
            </div>
        </div>
    </AppShell>
</template>