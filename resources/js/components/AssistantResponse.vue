<script setup lang="ts">
import { computed } from 'vue'
import AgentTrace from '@/components/AgentTrace.vue'
import type { GraphTriple, Message, RetrievedChunk } from '@/api'

const props = defineProps<{
    msg: Message
    // True only for the reply still streaming in: retrieval results (the primary
    // content) arrive with the terminating `done` event, so until then there is
    // just the generated summary filling token-by-token.
    streaming?: boolean
    // Whether this deploy has a research agent to escalate to at all. Without
    // one, no control is offered rather than one the backend would refuse.
    escalationAvailable?: boolean
    // Suppressed while any reply is in flight, so a second run cannot be started
    // on top of the first.
    busy?: boolean
    conversationId?: number
}>()

const emit = defineEmits<{ escalate: [messageId: number] }>()

const chunks = computed<RetrievedChunk[]>(() => props.msg.metadata?.top_chunks ?? [])
const triples = computed<GraphTriple[]>(() => props.msg.metadata?.top_triples ?? [])
const summary = computed<string>(() => props.msg.content ?? '')

const ttftMs = computed<number | null>(() => props.msg.metadata?.ttft_ms ?? null)
const totalMs = computed<number | null>(
    () => props.msg.metadata?.total_ms ?? props.msg.metadata?.total_latency_ms ?? null,
)

/** This answer came from the multi-hop agent rather than the single-shot pipeline. */
const isAgent = computed<boolean>(() => props.msg.metadata?.agent === true)

/** The searches the run issued, kept on the message so they survive a reload. */
const searches = computed(() => props.msg.metadata?.searches ?? [])

/**
 * Whether to offer escalation on this answer.
 *
 * Deliberately NOT a judgement about whether the answer was good enough — no
 * signal detects that reliably (a completeness marker in the generation prompt
 * broke the refusal marker already in it; question shape fires on plain
 * single-hop questions). The user knows whether their question was answered; the
 * system does not, so the control is simply always there.
 *
 * What is excluded is only where the agent has nothing to add, each read from a
 * fact already on the message: a gated greeting, an out-of-corpus refusal, an
 * answer that is already an escalation, or a bubble not yet persisted.
 */
const canEscalate = computed<boolean>(
    () =>
        props.escalationAvailable === true &&
        !props.streaming &&
        !isAgent.value &&
        props.msg.metadata?.gated !== true &&
        props.msg.metadata?.refused !== true &&
        props.msg.id > 0 &&
        props.conversationId !== undefined,
)

function formatTriple(triple: GraphTriple): string {
    return `${triple.head} → ${triple.relation} → ${triple.tail}`.replace(/\s+/g, ' ').trim()
}

function formatDuration(ms: number): string {
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`
}
</script>

<template>
    <div
        class="w-full max-w-3xl rounded-2xl rounded-bl-sm bg-white dark:bg-gray-900 border shadow-sm overflow-hidden"
        :class="isAgent ? 'border-violet-300 dark:border-violet-900' : 'border-gray-200 dark:border-gray-800'"
    >
        <!-- Which path produced this answer. Shown only on an escalation: a
             pipeline answer is the norm and needs no badge, but an agent answer
             costs several times as much and reads differently (per-claim
             citations), so it must be attributable at a glance. -->
        <div
            v-if="isAgent"
            class="flex items-center gap-2 px-4 py-2 bg-violet-50 dark:bg-violet-950/40 border-b border-violet-200 dark:border-violet-900/60"
        >
            <svg class="w-3.5 h-3.5 shrink-0 text-violet-700 dark:text-violet-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
            </svg>
            <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-300">
                Продлабочено истражување
            </p>
            <p v-if="searches.length" class="text-[11px] text-violet-700/70 dark:text-violet-400/70">
                · {{ searches.length }} пребарувања
            </p>
        </div>

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
                 open by default — this is what the system actually produces.
                 On an escalation these are the articles the answer cites, in the
                 order it cites them; on a pipeline answer they are every passage
                 the generator read, in retrieval's rank order — the panel is
                 sized from ai.retrieval_top_n so it can never be shorter than the
                 context. -->
            <section v-if="chunks.length">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300">
                    {{ isAgent ? 'Цитирани одредби' : 'Пронајдени одредби' }}
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
                    {{ isAgent ? 'Автоматски генериран приказ со цитати' : 'Автоматски генерирано резиме' }}
                </h4>
                <p class="mt-1 text-sm leading-relaxed whitespace-pre-wrap break-words text-gray-600 dark:text-gray-300">{{ summary }}<span v-if="streaming" class="inline-block w-1.5 h-4 -mb-0.5 bg-gray-400 dark:bg-gray-500 animate-pulse" aria-hidden="true" /></p>
                <p class="mt-1.5 text-[11px] italic text-gray-400 dark:text-gray-500">
                    Генерирано од вештачка интелигенција; не е дел од официјалниот текст на законот.
                </p>
            </section>

            <!-- The searches this run issued, and the full trajectory behind them
                 — so an escalation can be audited after the fact, not only while
                 it streams. -->
            <template v-if="isAgent">
                <section v-if="searches.length">
                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Извршени пребарувања
                    </h4>
                    <ol class="mt-1 space-y-0.5">
                        <li
                            v-for="(search, i) in searches"
                            :key="i"
                            class="flex gap-1.5 text-xs text-gray-600 dark:text-gray-400"
                        >
                            <span class="text-gray-400 dark:text-gray-500 tabular-nums">{{ i + 1 }}.</span>
                            <span class="break-words">{{ search.query }}</span>
                        </li>
                    </ol>
                </section>

                <AgentTrace
                    v-if="conversationId !== undefined && msg.id > 0"
                    :conversation-id="conversationId"
                    :message-id="msg.id"
                />
            </template>

            <p
                v-if="msg.model || ttftMs !== null || totalMs !== null"
                class="text-[11px] text-gray-400 dark:text-gray-500"
            >
                <span v-if="msg.model">{{ msg.model }}</span>
                <span v-if="ttftMs !== null"><span v-if="msg.model"> · </span>прв токен за {{ formatDuration(ttftMs) }}</span>
                <span v-if="totalMs !== null"><span v-if="msg.model || ttftMs !== null"> · </span>одговорено за {{ formatDuration(totalMs) }}</span>
                <span v-if="isAgent && msg.metadata?.agent_tokens"> · {{ msg.metadata.agent_tokens.toLocaleString() }} токени</span>
            </p>

            <!-- The escalation control. Offered on every substantive pipeline
                 answer rather than when the system thinks the answer fell short:
                 it cannot tell, and the user can. Stated cost, so the choice is
                 informed. -->
            <div v-if="canEscalate" class="pt-1 border-t border-gray-100 dark:border-gray-800">
                <button
                    type="button"
                    :disabled="busy"
                    @click="emit('escalate', msg.id)"
                    class="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-violet-200 dark:border-violet-900 px-3 py-1.5 text-xs font-semibold text-violet-700 dark:text-violet-300 hover:bg-violet-50 dark:hover:bg-violet-950/40 disabled:opacity-50 transition-colors"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                    </svg>
                    Продлабочи го истражувањето
                </button>
                <p class="mt-1.5 text-[11px] text-gray-400 dark:text-gray-500">
                    Прашањето се разложува на повеќе пребарувања со цитат по тврдење. Трае повеќе од
                    обичен одговор и чини околу четири пати повеќе.
                </p>
            </div>
        </div>
    </div>
</template>
