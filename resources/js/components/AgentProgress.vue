<script setup lang="ts">
import type { AgentSearch } from '@/api'

/**
 * Live progress for a running escalation.
 *
 * A spinner would be the wrong control here. The agent makes several searches
 * over ~7 seconds and streams no tokens — its model calls are non-streaming by
 * construction, because the loop needs a complete tool_calls array before it can
 * act — so the only honest progress is the searches themselves. Each query
 * appears as the model asks for it, and the citations it returned appear
 * underneath when they land. Same shape as the CLI trace inspector, live.
 */
defineProps<{
    searches: AgentSearch[]
    /** Falls to false the moment the answer arrives, freezing the list in place. */
    running: boolean
}>()

function toolLabel(tool: string): string {
    return tool === 'follow_references' ? 'Следам упатување' : 'Пребарувам'
}
</script>

<template>
    <div class="w-full max-w-3xl rounded-2xl rounded-bl-sm border border-violet-200 dark:border-violet-900/60 bg-violet-50/60 dark:bg-violet-950/30 overflow-hidden">
        <div class="flex items-center gap-2 px-4 py-2.5 border-b border-violet-200 dark:border-violet-900/60">
            <span
                v-if="running"
                class="w-2 h-2 rounded-full bg-violet-500 animate-pulse shrink-0"
                aria-hidden="true"
            />
            <p class="text-xs font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-300">
                Продлабочено истражување
            </p>
        </div>

        <ol class="px-4 py-3 space-y-2.5">
            <li v-for="(search, i) in searches" :key="i" class="text-sm">
                <p class="flex gap-1.5 text-gray-800 dark:text-gray-200">
                    <span class="text-violet-500 dark:text-violet-400 tabular-nums shrink-0">{{ i + 1 }}.</span>
                    <span class="break-words">
                        <span class="text-gray-500 dark:text-gray-400">{{ toolLabel(search.tool) }}:</span>
                        <span class="font-medium"> {{ search.query }}</span>
                    </span>
                </p>

                <!-- Citations returned by this search. Identifiers only while the
                     run is in flight; the full provisions arrive with the answer. -->
                <p v-if="search.sources && search.sources.length" class="mt-1 ml-5 text-xs text-gray-500 dark:text-gray-400 break-words">
                    {{ search.sources.join(' · ') }}
                </p>
                <p v-else-if="search.sources" class="mt-1 ml-5 text-xs italic text-gray-400 dark:text-gray-500">
                    нема резултати
                </p>
                <p v-else class="mt-1 ml-5 text-xs italic text-violet-500 dark:text-violet-400">
                    пребарувам...
                </p>
            </li>

            <li v-if="searches.length === 0" class="text-sm italic text-gray-500 dark:text-gray-400">
                Се подготвува...
            </li>
        </ol>
    </div>
</template>
