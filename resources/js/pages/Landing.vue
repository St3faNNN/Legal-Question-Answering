<script setup lang="ts">
const steps = [
    {
        title: 'You ask a question',
        body: 'Type a question in plain language. No query syntax, no schemas — just ask.',
    },
    {
        title: 'The knowledge graph retrieves',
        body: 'We link the entities in your question to a 122,000-node knowledge graph and pull the most confident supporting facts.',
    },
    {
        title: 'The model composes an answer',
        body: 'A language model reads those facts and writes a grounded reply — with the sources it used attached to every message.',
    },
]

const features = [
    {
        title: 'Grounded answers',
        body: 'Every reply is built from retrieved knowledge, not just the model’s memory — so answers stay anchored to real facts.',
    },
    {
        title: 'Source transparency',
        body: 'Each message records the entities and triples it was built from, so you can always see why the model answered the way it did.',
    },
    {
        title: 'Conversations that remember',
        body: 'Follow-up questions resolve naturally against earlier turns in the same conversation.',
    },
    {
        title: 'History you own',
        body: 'Every conversation is saved to your account, searchable and yours to revisit or delete at any time.',
    },
    {
        title: 'Light & dark',
        body: 'A calm interface that respects your system theme and your saved preference.',
    },
    {
        title: 'Built on an open pipeline',
        body: 'Retrieval and generation run as independent services you can inspect, swap, and scale.',
    },
]
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 text-gray-900 dark:text-gray-100">
        <!-- Nav -->
        <header class="mx-auto max-w-6xl px-6 py-5 flex items-center justify-between">
            <span class="text-lg font-bold tracking-tight">GraphRAG Chat</span>
            <nav class="flex items-center gap-3">
                <RouterLink
                    :to="{ name: 'login' }"
                    class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white px-3 py-2"
                >
                    Sign in
                </RouterLink>
                <RouterLink
                    :to="{ name: 'register' }"
                    class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors"
                >
                    Get started
                </RouterLink>
            </nav>
        </header>

        <!-- Hero -->
        <section class="mx-auto max-w-4xl px-6 pt-16 pb-20 text-center">
            <span class="inline-block rounded-full border border-indigo-200 dark:border-indigo-900 bg-indigo-50 dark:bg-indigo-950/50 px-3 py-1 text-xs font-medium text-indigo-700 dark:text-indigo-300">
                Knowledge-graph retrieval &plus; language generation
            </span>
            <h1 class="mt-6 text-4xl sm:text-5xl font-bold tracking-tight">
                Chat with your knowledge graph
            </h1>
            <p class="mx-auto mt-6 max-w-2xl text-lg text-gray-600 dark:text-gray-400">
                Ask questions in plain language and get answers grounded in a real knowledge graph —
                retrieved, verified, and composed for you, with every source attached.
            </p>
            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                <RouterLink
                    :to="{ name: 'register' }"
                    class="w-full sm:w-auto px-6 py-3 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors"
                >
                    Get started free
                </RouterLink>
                <RouterLink
                    :to="{ name: 'login' }"
                    class="w-full sm:w-auto px-6 py-3 rounded-lg text-sm font-semibold border border-gray-300 dark:border-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors"
                >
                    Sign in
                </RouterLink>
            </div>
        </section>

        <!-- How it works -->
        <section class="mx-auto max-w-6xl px-6 py-16">
            <h2 class="text-center text-2xl font-bold tracking-tight">How it works</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <div
                    v-for="(step, i) in steps"
                    :key="step.title"
                    class="p-6 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-sm font-semibold text-white">
                        {{ i + 1 }}
                    </div>
                    <h3 class="mt-4 font-semibold">{{ step.title }}</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ step.body }}</p>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section class="mx-auto max-w-6xl px-6 py-16">
            <h2 class="text-center text-2xl font-bold tracking-tight">Everything you need to trust the answer</h2>
            <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="p-6 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:shadow-md transition-shadow"
                >
                    <h3 class="font-semibold">{{ feature.title }}</h3>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ feature.body }}</p>
                </div>
            </div>
        </section>

        <!-- CTA band -->
        <section class="mx-auto max-w-6xl px-6 py-16">
            <div class="rounded-2xl bg-indigo-600 px-8 py-14 text-center">
                <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-white">
                    Start asking better questions
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-indigo-100">
                    Create an account and have your first grounded conversation in under a minute.
                </p>
                <RouterLink
                    :to="{ name: 'register' }"
                    class="mt-8 inline-block px-6 py-3 rounded-lg text-sm font-semibold text-indigo-700 bg-white hover:bg-indigo-50 transition-colors"
                >
                    Get started free
                </RouterLink>
            </div>
        </section>

        <!-- Footer -->
        <footer class="mx-auto max-w-6xl px-6 py-10 border-t border-gray-200 dark:border-gray-800">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-gray-500 dark:text-gray-500">
                <span>&copy; {{ new Date().getFullYear() }} GraphRAG Chat</span>
                <div class="flex items-center gap-6">
                    <RouterLink :to="{ name: 'login' }" class="hover:text-gray-800 dark:hover:text-gray-300">Sign in</RouterLink>
                    <RouterLink :to="{ name: 'register' }" class="hover:text-gray-800 dark:hover:text-gray-300">Create account</RouterLink>
                </div>
            </div>
        </footer>
    </div>
</template>
