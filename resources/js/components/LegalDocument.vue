<script setup lang="ts">
import { ref } from 'vue'
import { legalDocList, type LegalDocMeta } from '@/legal/documents'
import ThemeToggle from '@/components/ThemeToggle.vue'

defineProps<{ meta: LegalDocMeta }>()

// Macedonian is the primary version and the default view; English is available
// via the toggle. Both are always present on the page.
const lang = ref<'mk' | 'en'>('mk')
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 py-10 px-4 sm:px-6">
        <div class="max-w-3xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <RouterLink
                    to="/"
                    class="text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-500"
                >
                    ← {{ lang === 'mk' ? 'Почетна' : 'Home' }}
                </RouterLink>

                <div class="flex items-center gap-2">
                <ThemeToggle />
                <div class="inline-flex rounded-lg border border-gray-300 dark:border-gray-700 overflow-hidden text-xs font-semibold">
                    <button
                        type="button"
                        @click="lang = 'mk'"
                        class="px-3 py-1.5 transition-colors"
                        :class="lang === 'mk' ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-300'"
                    >
                        Македонски
                    </button>
                    <button
                        type="button"
                        @click="lang = 'en'"
                        class="px-3 py-1.5 transition-colors"
                        :class="lang === 'en' ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-300'"
                    >
                        English
                    </button>
                </div>
                </div>
            </div>

            <article class="legal-content rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm p-6 sm:p-9 text-gray-800 dark:text-gray-200">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ lang === 'mk' ? meta.titleMk : meta.titleEn }}
                </h1>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    <template v-if="lang === 'mk'">Верзија {{ meta.version }} · Важи од: {{ meta.effectiveDate }}</template>
                    <template v-else>Version {{ meta.version }} · Effective: {{ meta.effectiveDate }}</template>
                </p>
                <p class="mt-3 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900/60 px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
                    <template v-if="lang === 'mk'">
                        Нацрт-документ подготвен за правен преглед. Полињата означени со „[СЕ ДОПОЛНУВА]" допрва треба да се пополнат.
                    </template>
                    <template v-else>
                        Draft prepared for legal review. Fields marked “[TO BE COMPLETED]” are not yet filled in.
                    </template>
                </p>

                <div v-show="lang === 'mk'" class="mt-5" lang="mk">
                    <slot name="mk" />
                </div>
                <div v-show="lang === 'en'" class="mt-5" lang="en">
                    <slot name="en" />
                </div>
            </article>

            <nav class="mt-6 flex flex-wrap gap-x-5 gap-y-2 justify-center text-sm">
                <RouterLink
                    v-for="doc in legalDocList"
                    :key="doc.id"
                    :to="doc.route"
                    class="text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                    :class="doc.id === meta.id ? 'font-semibold text-indigo-600 dark:text-indigo-400' : ''"
                >
                    {{ lang === 'mk' ? doc.titleMk : doc.titleEn }}
                </RouterLink>
            </nav>
        </div>
    </div>
</template>

<style scoped>
/* Typography for the slotted document body — keeps the content markup clean
   semantic HTML while giving it readable legal-document styling. */
.legal-content :slotted(h2) {
    font-size: 1.05rem;
    font-weight: 700;
    margin-top: 1.6rem;
    margin-bottom: 0.4rem;
}

.legal-content :slotted(h3) {
    font-size: 0.95rem;
    font-weight: 600;
    margin-top: 1rem;
    margin-bottom: 0.25rem;
}

.legal-content :slotted(p) {
    font-size: 0.9rem;
    line-height: 1.6;
    margin-bottom: 0.6rem;
}

.legal-content :slotted(ul) {
    list-style: disc;
    padding-left: 1.25rem;
    margin-bottom: 0.6rem;
}

.legal-content :slotted(li) {
    font-size: 0.9rem;
    line-height: 1.55;
    margin-bottom: 0.25rem;
}

.legal-content :slotted(.tbc) {
    background: rgb(254 243 199);
    color: rgb(120 53 15);
    padding: 0 0.2rem;
    border-radius: 0.2rem;
    font-weight: 600;
}
</style>
