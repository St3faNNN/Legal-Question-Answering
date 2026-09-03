<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth';
import { legalDocList } from '@/legal/documents'
import ThemeToggle from '@/components/ThemeToggle.vue'

const auth = useAuthStore()
const router = useRouter()
const mobileOpen = ref(false)

async function logout() {
    await auth.logout()
    router.push({ name: 'login' })
}

// The Operations link is rendered only for an allowlisted user. This is a
// convenience, not access control: the admin endpoints 404 for everybody else
// regardless of what the client renders.
const navItems = computed(() => [
    { name: 'dashboard', label: 'Dashboard', icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6' },
    { name: 'chat', label: 'Chat', icon: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z' },
    { name: 'history', label: 'History', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' },
    { name: 'settings', label: 'Settings', icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z' },
    { name: 'profile', label: 'Profile', icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' },
    ...(auth.user?.features?.admin === true
        ? [{ name: 'admin', label: 'Operations', icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' }]
        : []),
])
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-50 w-64 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 transform transition-transform duration-200 lg:translate-x-0"
            :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex items-center justify-between h-16 px-6 border-b border-gray-200 dark:border-gray-800">
                <span class="text-lg font-semibold text-gray-900 dark:text-gray-100">GraphRAG</span>
            </div>
            <nav class="px-3 py-4 space-y-1">
                <RouterLink
                    v-for="item in navItems"
                    :key="item.name"
                    :to="{ name: item.name }"
                    class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                    :class="$route.name === item.name
                        ? 'bg-indigo-50 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300'
                        : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'"
                >
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                    </svg>
                    {{ item.label }}
                </RouterLink>
            </nav>
            <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-200 dark:border-gray-800">
                <div class="mb-3">
                    <ThemeToggle />
                </div>
                <nav class="flex flex-wrap gap-x-3 gap-y-1 mb-3">
                    <RouterLink
                        v-for="doc in legalDocList"
                        :key="doc.id"
                        :to="doc.route"
                        class="text-[11px] text-gray-400 dark:text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400"
                    >
                        {{ doc.titleMk }}
                    </RouterLink>
                </nav>
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-sm font-medium text-indigo-700 dark:text-indigo-300">
                            {{ auth.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                        </div>
                        <span class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300 truncate max-w-24">{{ auth.user?.name }}</span>
                    </div>
                    <button
                        @click="logout"
                        class="text-sm text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                        title="Logout"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Mobile backdrop -->
        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-40 bg-black/30 lg:hidden"
            @click="mobileOpen = false"
        />

        <!-- Main content -->
        <div class="flex-1 lg:ml-64">
            <header class="lg:hidden flex items-center h-16 px-4 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800">
                <button @click="mobileOpen = true" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">
                    <svg class="w-6 h-6 text-gray-700 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <span class="ml-3 text-lg font-semibold text-gray-900 dark:text-gray-100">GraphRAG</span>
                <ThemeToggle class="ml-auto" />
            </header>
            <main class="p-6">
                <slot />
            </main>
        </div>
    </div>
</template>