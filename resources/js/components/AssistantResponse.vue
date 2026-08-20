<script setup lang="ts">
import { computed } from 'vue'
import type { GraphTriple, Message, RetrievedChunk } from '@/api'

const props = defineProps<{
    msg: Message
    // True only for the reply still streaming in: retrieval results (the primary
    // content) arrive with the terminating `done` event, so until then there is
    // just the generated summary filling token-by-token.
    streaming?: boolean
}>()

const chunks = computed<RetrievedChunk[]>(() => props.msg.metadata?.top_chunks ?? [])
const triples = computed<GraphTriple[]>(() => props.msg.metadata?.top_triples ?? [])
const summary = computed<string>(() => props.msg.content ?? '')

const ttftMs = computed<number | null>(() => props.msg.metadata?.ttft_ms ?? null)
const totalMs = computed<number | null>(
    () => props.msg.metadata?.total_ms ?? props.msg.metadata?.total_latency_ms ?? null,
)

function formatTriple(triple: GraphTriple): string {
    return `${triple.head} → ${triple.relation} → ${triple.tail}`.replace(/\s+/g, ' ').trim()
}

function formatDuration(ms: number): string {
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`
}
</script>

<template>
    <div class="w-full max-w-3xl rounded-2xl rounded-bl-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
        <!-- Persistent, non-dismissable notice on every response. States plainly
             what the system is (a finder/citer of provisions), that the summary
             is machine-generated and may be wrong, and that the official text
             prevails. -->
        <div class="flex gap-2 px-4 py-2.5 bg-amber-50 dark:bg-amber-950/40 border-b border-amber-200 dark:border-amber-900/60 text-amber-900 dark:text-amber-200">
            <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            <p class="text-[11px] leading-snug">
                Оваа алатка пронаоѓа и цитира законски одредби и <strong>не претставува правен совет</strong>.
                Резимето подолу е генерирано автоматски со вештачка интелигенција и може да биде неточно или нецелосно.
                Меродавен е официјално објавениот текст на прописите во „Службен весник на Република Северна Македонија“.
            </p>
        </div>

        <div class="px-4 py-3 space-y-4">
            <!-- PRIMARY: the retrieved statutory provisions, article text shown
                 open by default — this is what the system actually produces. -->
            <section v-if="chunks.length">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300">
                    Пронајдени одредби
                </h3>
                <ol class="mt-2 space-y-3">
                    <li
                        v-for="(chunk, i) in chunks"
                        :key="chunk.id"
                        class="rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/40 p-3"
                    >
                        <div class="flex gap-1.5">
                            <span class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">{{ i + 1 }}.</span>
                            <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 break-words">
                                {{ chunk.source || 'Одредба' }}
                            </p>
                        </div>
                        <p class="mt-1.5 text-sm leading-relaxed whitespace-pre-wrap break-words text-gray-800 dark:text-gray-200">{{ chunk.text }}</p>
                    </li>
                </ol>
            </section>

            <!-- Cross-references between the retrieved articles (citation graph). -->
            <section v-if="triples.length">
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Поврзани одредби
                </h4>
                <ol class="mt-1 space-y-0.5">
                    <li
                        v-for="(triple, i) in triples"
                        :key="i"
                        class="flex gap-1.5 text-xs text-gray-600 dark:text-gray-400"
                    >
                        <span class="text-gray-400 dark:text-gray-500 tabular-nums">{{ i + 1 }}.</span>
                        <span class="break-words">{{ formatTriple(triple) }}</span>
                    </li>
                </ol>
            </section>

            <!-- SUBORDINATE: the machine-generated summary. Visually secondary
                 (muted, boxed) and explicitly labelled, so it reads as a
                 convenience over the provisions above rather than as the answer. -->
            <section
                v-if="summary || streaming"
                class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/30 p-3"
            >
                <h4 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Автоматски генерирано резиме
                </h4>
                <p class="mt-1 text-sm leading-relaxed whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ summary }}<span v-if="streaming" class="inline-block w-1.5 h-4 -mb-0.5 bg-gray-400 dark:bg-gray-500 animate-pulse" aria-hidden="true" /></p>
                <p class="mt-1.5 text-[11px] italic text-gray-400 dark:text-gray-500">
                    Генерирано од вештачка интелигенција; не е дел од официјалниот текст на законот.
                </p>
            </section>

            <p
                v-if="msg.model || ttftMs !== null || totalMs !== null"
                class="text-[11px] text-gray-400 dark:text-gray-500"
            >
                <span v-if="msg.model">{{ msg.model }}</span>
                <span v-if="ttftMs !== null"><span v-if="msg.model"> · </span>прв токен за {{ formatDuration(ttftMs) }}</span>
                <span v-if="totalMs !== null"><span v-if="msg.model || ttftMs !== null"> · </span>одговорено за {{ formatDuration(totalMs) }}</span>
            </p>
        </div>
    </div>
</template>
