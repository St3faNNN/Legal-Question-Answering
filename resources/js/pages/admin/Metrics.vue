<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppShell from '@/components/AppShell.vue'
import { adminApi, type AdminExchange, type AdminExchangeDetail, type AdminMetrics, type AgentTrace, type Distribution } from '@/api'

/**
 * Operational dashboard.
 *
 * Two rules run through this page:
 *
 *  1. No number without its denominator. Message metadata grew field by field,
 *     so a latency percentile is computed over the rows that carry the field and
 *     the coverage is printed next to it. Where a metric cannot be computed at
 *     all it says so rather than showing a zero.
 *  2. Question text is never rendered in a list. The table shows timings, path
 *     and citations; reading what someone asked is a deliberate click that
 *     fetches one exchange.
 *
 * Charts are CSS bars and inline SVG — no charting library. The three shapes
 * needed (a stacked daily bar, a histogram, a percentile row) are all widths in
 * a flex row, and a dependency for that would be harder to justify than to add.
 */
const metrics = ref<AdminMetrics | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const exchanges = ref<AdminExchange[]>([])
const exchangesLoading = ref(false)
const pathFilter = ref('')

/** The one exchange whose text has been explicitly requested, if any. */
const openExchange = ref<AdminExchangeDetail | null>(null)
const openLoading = ref(false)

const openTrace = ref<AgentTrace | null>(null)
const traceLoading = ref(false)

onMounted(async () => {
    try {
        metrics.value = await adminApi.metrics()
    } catch (e: any) {
        // A 404 here is the gate, not a missing page: this view is only reachable
        // from a nav link that the user payload gates, so the honest message is
        // that the panel is not available to this account.
        error.value = e?.response?.status === 404
            ? 'This panel is not available for your account.'
            : (e?.message ?? 'Could not load metrics.')
    } finally {
        loading.value = false
    }
    await loadExchanges()
})

async function loadExchanges() {
    exchangesLoading.value = true
    try {
        exchanges.value = (await adminApi.exchanges(pathFilter.value || undefined)).data
    } finally {
        exchangesLoading.value = false
    }
}

/** The explicit action: fetch one exchange's text. */
async function readExchange(id: number) {
    if (openExchange.value?.id === id) {
        openExchange.value = null
        return
    }
    openLoading.value = true
    openTrace.value = null
    try {
        openExchange.value = await adminApi.exchange(id)
    } finally {
        openLoading.value = false
    }
}

async function loadTrace(agentRunId: number) {
    traceLoading.value = true
    try {
        openTrace.value = await adminApi.trace(agentRunId)
    } finally {
        traceLoading.value = false
    }
}

const paths = ['pipeline', 'escalated', 'refused', 'gated', 'unclassified'] as const
const pathColour: Record<string, string> = {
    pipeline: 'bg-indigo-500',
    escalated: 'bg-violet-500',
    refused: 'bg-amber-500',
    gated: 'bg-gray-400',
    unclassified: 'bg-gray-300 dark:bg-gray-700',
}

const maxDaily = computed(() => Math.max(1, ...(metrics.value?.volume.daily ?? []).map((d) => d.total)))

function pct(n: number, of: number): number {
    return of > 0 ? (100 * n) / of : 0
}

function ms(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—'
    return value < 1000 ? `${value} ms` : `${(value / 1000).toFixed(1)} s`
}

function histogramMax(d: Distribution): number {
    return Math.max(1, ...(d.histogram ?? []).map((b) => b.count))
}

function shortDate(iso: string): string {
    return iso.slice(5)
}
</script>

<template>
    <AppShell>
        <div class="max-w-6xl mx-auto space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Operations</h1>
                <p v-if="metrics" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ metrics.window.days }}-day window · {{ metrics.window.from }} → {{ metrics.window.to }} ·
                    aggregated from the database, not the log files (those do not survive a container recreate)
                </p>
            </div>

            <p v-if="loading" class="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
            <p v-else-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>

            <template v-else-if="metrics">
                <!-- VOLUME -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Volume</h2>

                    <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div v-for="stat in [
                            { label: 'Questions asked', value: metrics.volume.questions },
                            { label: 'Answers produced', value: metrics.volume.answers },
                            { label: 'Conversations', value: metrics.volume.conversations },
                            { label: 'Active users', value: metrics.volume.users_active },
                        ]" :key="stat.label" class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-3">
                            <p class="text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ stat.value }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ stat.label }}</p>
                        </div>
                    </div>
                    <p class="mt-2 text-[11px] text-gray-400 dark:text-gray-500">
                        Answers exceed or trail questions on purpose: an escalation adds an answer without a new question.
                    </p>

                    <!-- Path mix -->
                    <div class="mt-4">
                        <div class="flex h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                v-for="p in paths"
                                :key="p"
                                :class="pathColour[p]"
                                :style="{ width: pct(metrics.volume.by_path[p], metrics.volume.answers) + '%' }"
                                :title="`${p}: ${metrics.volume.by_path[p]}`"
                            />
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                            <span v-for="p in paths" :key="p" class="flex items-center gap-1.5 text-[11px] text-gray-600 dark:text-gray-400">
                                <span class="w-2 h-2 rounded-sm" :class="pathColour[p]" />
                                {{ p }} <b class="tabular-nums text-gray-900 dark:text-gray-200">{{ metrics.volume.by_path[p] }}</b>
                            </span>
                        </div>
                        <p v-if="metrics.volume.by_path.unclassified > 0" class="mt-1.5 text-[11px] text-gray-400 dark:text-gray-500">
                            “unclassified” are answers written before the path flags existed. They are counted apart rather
                            than folded into “pipeline”, because an absent flag is not a false one.
                        </p>
                    </div>

                    <!-- Daily, stacked. CSS widths; no chart library. -->
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-[11px]">
                            <tbody>
                                <tr v-for="day in metrics.volume.daily" :key="day.date" class="align-middle">
                                    <td class="py-0.5 pr-2 whitespace-nowrap text-gray-500 dark:text-gray-400 tabular-nums">{{ shortDate(day.date) }}</td>
                                    <td class="py-0.5 pr-2 text-right tabular-nums text-gray-900 dark:text-gray-200 w-8">{{ day.total }}</td>
                                    <td class="py-0.5 w-full">
                                        <div class="flex h-3 rounded-sm overflow-hidden" :style="{ width: pct(day.total, maxDaily) + '%' }">
                                            <div v-for="p in paths" :key="p" :class="pathColour[p]" :style="{ width: pct(day[p], day.total) + '%' }" :title="`${p}: ${day[p]}`" />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- LATENCY -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Latency</h2>
                    <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                        Percentiles, not means — the tail is what hurts, and this data spans several generations of the
                        system. Each row states the sample it was computed from.
                    </p>

                    <div class="mt-3 space-y-4">
                        <div v-for="key in (['retrieval_ms', 'ttft_ms', 'total_ms', 'generation_latency_ms'] as const)" :key="key">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ key }}</h3>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    <template v-if="metrics.latency[key].available">
                                        n = {{ metrics.latency[key].n }} of {{ metrics.latency[key].out_of }}
                                        ({{ Math.round((metrics.latency[key].coverage ?? 0) * 100) }}% coverage)
                                    </template>
                                    <template v-else>not recorded on any answer in this window</template>
                                </p>
                            </div>

                            <template v-if="metrics.latency[key].available">
                                <div class="mt-1 grid grid-cols-3 sm:grid-cols-6 gap-2 text-[11px]">
                                    <div v-for="p in (['min', 'p50', 'p90', 'p95', 'p99', 'max'] as const)" :key="p" class="rounded bg-gray-50 dark:bg-gray-800/50 px-2 py-1">
                                        <span class="text-gray-500 dark:text-gray-400">{{ p }}</span>
                                        <b class="block tabular-nums text-gray-900 dark:text-gray-100">{{ ms(metrics.latency[key][p]) }}</b>
                                    </div>
                                </div>
                                <div class="mt-1.5 flex items-end gap-0.5 h-10">
                                    <div
                                        v-for="bucket in metrics.latency[key].histogram"
                                        :key="bucket.label"
                                        class="flex-1 bg-indigo-400/70 dark:bg-indigo-500/60 rounded-t-sm min-h-[1px]"
                                        :style="{ height: pct(bucket.count, histogramMax(metrics.latency[key])) + '%' }"
                                        :title="`${bucket.label} ms: ${bucket.count}`"
                                    />
                                </div>
                            </template>
                            <p v-else class="mt-1 text-[11px] italic text-gray-400 dark:text-gray-500">
                                Omitted rather than shown as zero.
                            </p>
                        </div>
                    </div>

                    <h3 class="mt-5 text-xs font-semibold text-gray-800 dark:text-gray-200">By model</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">
                        Pooling these would describe no configuration that ever ran.
                    </p>
                    <p
                        class="mt-1 text-[11px]"
                        :class="metrics.latency.null_model.count > 0
                            ? 'text-amber-700 dark:text-amber-400'
                            : 'text-gray-400 dark:text-gray-500'"
                    >
                        <b class="tabular-nums">{{ metrics.latency.null_model.count }}</b>
                        of {{ metrics.latency.null_model.out_of }} answers carry no model name
                        <span v-if="metrics.latency.null_model.gated_excluded">
                            ({{ metrics.latency.null_model.gated_excluded }} gated replies excluded — they make no model call)
                        </span>. {{ metrics.latency.null_model.note }}
                    </p>
                    <div class="mt-1.5 overflow-x-auto">
                        <table class="w-full text-[11px]">
                            <thead class="text-gray-500 dark:text-gray-400">
                                <tr class="text-left"><th class="py-1 pr-3 font-medium">model</th><th class="py-1 pr-3 font-medium text-right">answers</th><th class="py-1 pr-3 font-medium text-right">p50</th><th class="py-1 pr-3 font-medium text-right">p95</th><th class="py-1 font-medium">last seen</th></tr>
                            </thead>
                            <tbody class="text-gray-800 dark:text-gray-200">
                                <tr v-for="row in metrics.latency.by_model" :key="row.model" class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-1 pr-3 font-mono">{{ row.model }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums">{{ row.answers }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums">{{ ms(row.p50_ms) }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums">{{ ms(row.p95_ms) }}</td>
                                    <td class="py-1 text-gray-500 dark:text-gray-400">{{ row.last_seen }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- TOKENS -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Tokens and budget</h2>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-violet-200 dark:border-violet-900/60 bg-violet-50/50 dark:bg-violet-950/20 p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-300">Escalation — measured</p>
                            <template v-if="metrics.tokens.escalation.available">
                                <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ metrics.tokens.escalation.total.toLocaleString() }}
                                </p>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400">
                                    over {{ metrics.tokens.escalation.runs }} run(s) · mean
                                    {{ metrics.tokens.escalation.mean?.toLocaleString() }} · range
                                    {{ metrics.tokens.escalation.min?.toLocaleString() }}–{{ metrics.tokens.escalation.max?.toLocaleString() }}
                                </p>
                            </template>
                            <p v-else class="mt-1 text-sm italic text-gray-500 dark:text-gray-400">No runs yet.</p>
                        </div>

                        <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pipeline — not available</p>
                            <p class="mt-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                                {{ metrics.tokens.pipeline.reason }}
                            </p>
                            <p class="mt-1.5 text-[11px] text-gray-400 dark:text-gray-500">
                                {{ metrics.tokens.pipeline.legacy_rows_with_tokens }} answers do carry a token count, all
                                from the retired local-model era.
                            </p>
                        </div>
                    </div>

                    <div class="mt-3 rounded-lg bg-gray-50 dark:bg-gray-800/50 p-3">
                        <p class="text-[11px] text-gray-600 dark:text-gray-400">
                            Daily meter: <b class="text-gray-900 dark:text-gray-200">{{ metrics.tokens.budget.daily_units }}</b> units/user/day ·
                            pipeline {{ metrics.tokens.budget.pipeline_cost }} · escalation {{ metrics.tokens.budget.escalation_cost }}
                        </p>
                        <p v-if="metrics.tokens.budget.today.length === 0" class="mt-1 text-[11px] italic text-gray-400 dark:text-gray-500">
                            Nothing spent today. This is a live gauge, not a history — the meter is a cache key that expires
                            at midnight, so there is no yesterday to show.
                        </p>
                        <ul v-else class="mt-1 space-y-0.5">
                            <li v-for="row in metrics.tokens.budget.today" :key="row.user_id" class="text-[11px] text-gray-700 dark:text-gray-300 tabular-nums">
                                {{ row.name }} — {{ row.used }} used, {{ row.remaining }} left
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- AGENT -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Agent runs</h2>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-600 dark:text-gray-400">
                        <span>runs <b class="text-gray-900 dark:text-gray-200 tabular-nums">{{ metrics.agent.runs }}</b></span>
                        <span v-for="(n, outcome) in metrics.agent.by_outcome" :key="outcome">{{ outcome }} <b class="text-gray-900 dark:text-gray-200 tabular-nums">{{ n }}</b></span>
                        <span>steps/run <b class="text-gray-900 dark:text-gray-200 tabular-nums">{{ metrics.agent.steps.mean ?? '—' }}</b> (max {{ metrics.agent.steps.max ?? '—' }})</span>
                        <span>searches/run <b class="text-gray-900 dark:text-gray-200 tabular-nums">{{ metrics.agent.searches_per_run.mean ?? '—' }}</b> (max {{ metrics.agent.searches_per_run.max ?? '—' }})</span>
                    </div>

                    <div v-if="metrics.agent.recent.length" class="mt-3 overflow-x-auto">
                        <table class="w-full text-[11px]">
                            <thead class="text-gray-500 dark:text-gray-400">
                                <tr class="text-left"><th class="py-1 pr-3 font-medium">run</th><th class="py-1 pr-3 font-medium">outcome</th><th class="py-1 pr-3 font-medium text-right">steps</th><th class="py-1 pr-3 font-medium text-right">searches</th><th class="py-1 pr-3 font-medium text-right">tokens</th><th class="py-1 pr-3 font-medium text-right">time</th><th class="py-1 font-medium">trace</th></tr>
                            </thead>
                            <tbody class="text-gray-800 dark:text-gray-200">
                                <tr v-for="run in metrics.agent.recent" :key="run.id" class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-1 pr-3 font-mono">{{ run.run_id }}</td>
                                    <td class="py-1 pr-3">{{ run.outcome }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums">{{ run.steps }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums">{{ run.searches }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums">{{ run.total_tokens.toLocaleString() }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums">{{ ms(run.duration_ms) }}</td>
                                    <td class="py-1">
                                        <button type="button" class="text-indigo-600 dark:text-indigo-400 hover:underline" @click="loadTrace(run.id)">open</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-2 text-[11px] italic text-gray-400 dark:text-gray-500">No escalations yet.</p>

                    <!-- The trajectory, same shape the chat's trace view renders. -->
                    <div v-if="traceLoading" class="mt-3 text-[11px] text-gray-500">Loading trace…</div>
                    <div v-else-if="openTrace" class="mt-3 rounded-lg border border-gray-200 dark:border-gray-800 p-3">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[11px] text-gray-500 dark:text-gray-400">
                            <span class="font-mono text-gray-800 dark:text-gray-200">{{ openTrace.run_id }}</span>
                            <span>{{ openTrace.totals.steps }} steps</span>
                            <span>{{ openTrace.totals.api_calls }} model calls</span>
                            <span>{{ openTrace.totals.total_tokens.toLocaleString() }} tokens</span>
                            <span>{{ ms(openTrace.totals.duration_ms) }}</span>
                            <button type="button" class="ml-auto text-indigo-600 dark:text-indigo-400 hover:underline" @click="openTrace = null">close</button>
                        </div>
                        <ol class="mt-2 space-y-1.5">
                            <li v-for="step in openTrace.steps" :key="step.index" class="text-[11px]">
                                <p class="text-gray-400 dark:text-gray-500">step {{ step.index }}<span v-if="step.tokens"> · {{ step.tokens.toLocaleString() }} tok</span></p>
                                <div v-for="(call, i) in step.calls" :key="i" class="ml-3">
                                    <p class="text-gray-800 dark:text-gray-200 break-words">
                                        <span class="font-mono text-violet-600 dark:text-violet-400">{{ call.tool }}</span>(„{{ call.query }}“)
                                    </p>
                                    <p class="text-gray-500 dark:text-gray-400 break-words">{{ call.sources.join(' · ') || 'no results' }}</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                </section>

                <!-- FAILURES -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Failures</h2>
                    <div class="mt-2 grid gap-3 sm:grid-cols-3 text-[11px]">
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-3">
                            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                {{ metrics.failures.refusals.count }}<span class="text-sm text-gray-400"> / {{ metrics.failures.refusals.out_of }}</span>
                            </p>
                            <p class="text-gray-500 dark:text-gray-400">refusals</p>
                            <p class="mt-1 text-gray-400 dark:text-gray-500">{{ metrics.failures.refusals.note }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-3">
                            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                {{ metrics.failures.gated.count }}<span class="text-sm text-gray-400"> / {{ metrics.failures.gated.out_of }}</span>
                            </p>
                            <p class="text-gray-500 dark:text-gray-400">gated (non-legal input, answered with no model call)</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 p-3">
                            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ metrics.failures.failed_jobs.total }}</p>
                            <p class="text-gray-500 dark:text-gray-400">failed jobs (all time)</p>
                        </div>
                    </div>
                    <ul v-if="metrics.failures.failed_jobs.recent.length" class="mt-3 space-y-1">
                        <li v-for="job in metrics.failures.failed_jobs.recent" :key="job.id" class="text-[11px] text-gray-700 dark:text-gray-300 break-words">
                            <span class="text-gray-400 dark:text-gray-500 tabular-nums">{{ job.failed_at }}</span>
                            <span class="font-mono"> {{ job.job }}</span> — {{ job.reason }}
                        </li>
                    </ul>
                </section>

                <!-- EXCHANGES: metadata by default, text on demand -->
                <section class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Recent exchanges</h2>
                        <select
                            v-model="pathFilter"
                            @change="loadExchanges"
                            class="rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-2 py-1 text-xs text-gray-900 dark:text-gray-100"
                        >
                            <option value="">all paths</option>
                            <option v-for="p in paths" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                    <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                        Metadata only. Question and answer text are not loaded with this table — reading one is a
                        deliberate click, so opening the dashboard does not mean reading everyone's questions.
                    </p>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-[11px]">
                            <thead class="text-gray-500 dark:text-gray-400">
                                <tr class="text-left">
                                    <th class="py-1 pr-3 font-medium">when</th><th class="py-1 pr-3 font-medium">path</th>
                                    <th class="py-1 pr-3 font-medium text-right">ttft</th><th class="py-1 pr-3 font-medium text-right">total</th>
                                    <th class="py-1 pr-3 font-medium text-right">chars in</th><th class="py-1 pr-3 font-medium">citations</th>
                                    <th class="py-1 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-800 dark:text-gray-200">
                                <template v-for="row in exchanges" :key="row.id">
                                    <tr class="border-t border-gray-100 dark:border-gray-800">
                                        <td class="py-1 pr-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ row.created_at?.slice(0, 16).replace('T', ' ') }}</td>
                                        <td class="py-1 pr-3"><span class="inline-block w-2 h-2 rounded-sm mr-1" :class="pathColour[row.path]" />{{ row.path }}</td>
                                        <td class="py-1 pr-3 text-right tabular-nums">{{ ms(row.ttft_ms) }}</td>
                                        <td class="py-1 pr-3 text-right tabular-nums">{{ ms(row.total_ms ?? row.latency_ms) }}</td>
                                        <td class="py-1 pr-3 text-right tabular-nums">{{ row.question_chars ?? '—' }}</td>
                                        <td class="py-1 pr-3 text-gray-500 dark:text-gray-400 truncate max-w-xs">{{ row.citations.slice(0, 2).join(' · ') || '—' }}</td>
                                        <td class="py-1 whitespace-nowrap">
                                            <button type="button" class="text-indigo-600 dark:text-indigo-400 hover:underline" @click="readExchange(row.id)">
                                                {{ openExchange?.id === row.id ? 'hide text' : 'read text' }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="openExchange?.id === row.id" class="bg-gray-50 dark:bg-gray-800/40">
                                        <td colspan="7" class="p-3">
                                            <p v-if="openLoading" class="text-gray-500">Loading…</p>
                                            <template v-else>
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Question</p>
                                                <p class="mt-0.5 text-xs whitespace-pre-wrap break-words text-gray-900 dark:text-gray-100">{{ openExchange.question ?? '(not found)' }}</p>
                                                <p class="mt-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Answer</p>
                                                <p class="mt-0.5 text-xs whitespace-pre-wrap break-words text-gray-700 dark:text-gray-300">{{ openExchange.answer }}</p>
                                                <button
                                                    v-if="row.agent_run_id"
                                                    type="button"
                                                    class="mt-2 text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline"
                                                    @click="loadTrace(row.agent_run_id)"
                                                >
                                                    open agent trace
                                                </button>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <p v-if="exchangesLoading" class="mt-2 text-[11px] text-gray-500">Loading…</p>
                        <p v-else-if="exchanges.length === 0" class="mt-2 text-[11px] italic text-gray-400">No exchanges on this page match that path.</p>
                    </div>
                </section>
            </template>
        </div>
    </AppShell>
</template>
