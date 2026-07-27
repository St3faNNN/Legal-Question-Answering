<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { newsApi, type NewsArticle } from '@/api'

const articles = ref<NewsArticle[]>([])
const loading = ref(true)

onMounted(async () => {
    try {
        articles.value = await newsApi.latest(3)
    } finally {
        loading.value = false
    }
})

function formatDate(value: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
    <section>
        <div class="flex items-baseline justify-between">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Latest news</h2>
            <a
                href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon"
                target="_blank" rel="noopener"
                class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
            >МОН · Конкурси &rarr;</a>
        </div>

        <div v-if="loading" class="mt-3 text-sm text-gray-500">Loading news...</div>

        <div
            v-else-if="articles.length === 0"
            class="mt-3 p-6 text-center border border-dashed border-gray-300 dark:border-gray-700 rounded-xl"
        >
            <p class="text-sm text-gray-500 dark:text-gray-400">No news yet.</p>
        </div>

        <ol v-else class="mt-3 space-y-2">
            <li v-for="(article, i) in articles" :key="article.id">
                <a
                    :href="article.url" target="_blank" rel="noopener"
                    class="flex items-start gap-3 p-4 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 hover:shadow-md transition"
                >
                    <span class="mt-0.5 w-6 h-6 shrink-0 rounded-md bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 text-xs font-semibold flex items-center justify-center tabular-nums">
                        {{ i + 1 }}
                    </span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ article.title }}</span>
                        <span class="block text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                            mon.gov.mk<template v-if="article.published_at"> · {{ formatDate(article.published_at) }}</template>
                        </span>
                    </span>
                    <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </a>
            </li>
        </ol>
    </section>
</template>
