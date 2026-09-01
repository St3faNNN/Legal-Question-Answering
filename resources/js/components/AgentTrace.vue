<script setup lang="ts">
import { ref } from 'vue'
import { chatApi, type AgentTrace } from '@/api'

/**
 * The stored trajectory of one escalation, fetched on demand.
 *
 * Same question the CLI inspector was built to answer — *did the agent decompose
 * the query into several searches, or search once and answer?* — but for a run
 * that happened in the app, days ago. Loaded only when opened: a trace carries
 * every passage the run read, which is far too much to ship with a conversation.
 */
const props = defineProps<{
    conversationId: number
    messageId: number
}>()

const open = ref(false)
const trace = ref<AgentTrace | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function toggle() {
    open.value = !open.value

    if (!open.value || trace.value !== null || loading.value) {
        return
    }

    loading.value = true
    error.value = null
    try {
        trace.value = await chatApi.trace(props.conversationId, props.messageId)
    } catch {
        error.value = 'Траекторијата не е достапна за оваа порака.'
    } finally {
        loading.value = false
    }
}

function formatDuration(ms: number): string {
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`
}
</script>

<template>
    <div class="rounded-lg border border-gray-200 dark:border-gray-800">
        <button
            type="button"
            @click="toggle"
            class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left"
        >
            <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Траекторија на истражувањето
            </span>
            <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="open ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
            </svg>
        </button>

        <div v-if="open" class="px-3 pb-3 space-y-3 border-t border-gray-100 dark:border-gray-800/60 pt-3">
            <p v-if="loading" class="text-xs text-gray-500 dark:text-gray-400">Се вчитува...</p>
            <p v-else-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>

            <template v-else-if="trace">
                <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-500 dark:text-gray-400">
                    <span><b class="text-gray-700 dark:text-gray-300">{{ trace.totals.steps }}</b> чекори</span>
                    <span><b class="text-gray-700 dark:text-gray-300">{{ trace.totals.api_calls }}</b> повици до моделот</span>
                    <span><b class="text-gray-700 dark:text-gray-300">{{ trace.totals.total_tokens.toLocaleString() }}</b> токени</span>
                    <span>{{ formatDuration(trace.totals.duration_ms) }}</span>
                    <span>{{ trace.outcome }}</span>
                    <span class="font-mono">{{ trace.run_id }}</span>
                </div>

                <ol class="space-y-2.5">
                    <li v-for="step in trace.steps" :key="step.index" class="text-xs">
                        <p class="text-gray-400 dark:text-gray-500">
                            чекор {{ step.index }}
                            <span v-if="step.tokens"> · {{ step.tokens.toLocaleString() }} токени</span>
                            <span v-if="step.durationMs"> · {{ formatDuration(step.durationMs) }}</span>
                            <span v-if="step.calls.length === 0"> · конечен одговор</span>
                        </p>
                        <div v-for="(call, i) in step.calls" :key="i" class="mt-1 ml-3">
                            <p class="text-gray-800 dark:text-gray-200 break-words">
                                <span class="font-mono text-violet-600 dark:text-violet-400">{{ call.tool }}</span>
                                („{{ call.query }}“)
                            </p>
                            <p v-if="call.error" class="mt-0.5 text-red-600 dark:text-red-400 break-words">
                                грешка: {{ call.error }}
                            </p>
                            <p v-else-if="call.sources.length" class="mt-0.5 text-gray-500 dark:text-gray-400 break-words">
                                {{ call.sources.join(' · ') }}
                            </p>
                            <p v-else class="mt-0.5 italic text-gray-400 dark:text-gray-500">нема резултати</p>
                        </div>
                    </li>
                </ol>
            </template>
        </div>
    </div>
</template>
