<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import NewsSection from '@/components/NewsSection.vue'
import TodoSection from '@/components/TodoSection.vue'
import { useAuthStore } from '@/stores/auth'
import { useConversationStore } from '@/stores/conversations'

const auth = useAuthStore()
const conversations = useConversationStore()

onMounted(async () => {
    await conversations.reload()
})
</script>

<template>
    <AppShell>
        <div class="max-w-4xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Dashboard</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Welcome back, {{ auth.user?.name }}.</p>

            <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <RouterLink :to="{ name: 'chat' }"
                    class="block p-6 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:shadow-md transition-shadow">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-lg bg-indigo-100 flex items-center justify-center">
                            <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Start a chat</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">New conversation</p>
                        </div>
                    </div>
                </RouterLink>

                <RouterLink :to="{ name: 'history' }"
                    class="block p-6 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:shadow-md transition-shadow">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">History</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ conversations.conversations.length }} conversations</p>
                        </div>
                    </div>
                </RouterLink>

                <RouterLink :to="{ name: 'settings' }"
                    class="block p-6 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:shadow-md transition-shadow">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-lg bg-amber-100 flex items-center justify-center">
                            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Settings</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Manage preferences</p>
                        </div>
                    </div>
                </RouterLink>
            </div>

            <div class="mt-8">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Conversations</h2>
                <div v-if="conversations.loading" class="mt-3 text-sm text-gray-500">Loading...</div>
                <div v-else-if="conversations.conversations.length === 0" class="mt-3 p-8 text-center border border-dashed border-gray-300 dark:border-gray-700 rounded-xl">
                    <p class="text-sm text-gray-500 dark:text-gray-400">No conversations yet. <RouterLink :to="{name: 'chat'}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Start your first chat &rarr;</RouterLink></p>
                </div>
                <div v-else class="mt-3 space-y-2">
                    <RouterLink
                        v-for="conv in conversations.conversations.slice(0, 5)" :key="conv.id"
                        :to="{ name: 'chat-conversation', params: { id: conv.id } }"
                        class="block p-4 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                    >
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ conv.title ?? conv.display_title ?? `Conversation ${conv.id}` }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-500 mt-0.5">{{ conv.updated_at }}</p>
                    </RouterLink>
                </div>
            </div>

            <div class="mt-8">
                <NewsSection />
            </div>

            <div class="mt-8">
                <TodoSection />
            </div>
        </div>
    </AppShell>
</template>