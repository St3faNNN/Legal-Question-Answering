<script setup lang="ts">
import { useThemeStore, type Theme } from '@/stores/theme'

const theme = useThemeStore()

const options: { value: Theme; label: string }[] = [
    { value: 'light', label: 'Light theme' },
    { value: 'system', label: 'System theme' },
    { value: 'dark', label: 'Dark theme' },
]
</script>

<template>
    <div
        role="group"
        aria-label="Theme"
        class="inline-flex items-center gap-0.5 rounded-full border border-gray-200 dark:border-gray-700 bg-white/90 dark:bg-gray-900/90 shadow-sm p-0.5"
    >
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            :title="opt.label"
            :aria-label="opt.label"
            :aria-pressed="theme.theme === opt.value"
            @click="theme.setTheme(opt.value)"
            class="flex h-7 w-7 items-center justify-center rounded-full transition-colors"
            :class="theme.theme === opt.value
                ? 'bg-indigo-600 text-white'
                : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100'"
        >
            <!-- Light -->
            <svg v-if="opt.value === 'light'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="4" />
                <path stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
            </svg>
            <!-- System -->
            <svg v-else-if="opt.value === 'system'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <rect x="2.5" y="3.5" width="19" height="13" rx="2" />
                <path stroke-linecap="round" d="M8 21h8M12 17v4" />
            </svg>
            <!-- Dark -->
            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
            </svg>
        </button>
    </div>
</template>
