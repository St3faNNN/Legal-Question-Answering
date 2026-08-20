<script setup lang="ts">
import { legalDocList } from '@/legal/documents'
</script>

<template>
    <footer class="text-center text-xs text-gray-400 dark:text-gray-500">
        <p class="mb-1">Оваа алатка дава правни информации, не правен совет.</p>
        <nav class="flex flex-wrap gap-x-4 gap-y-1 justify-center">
            <RouterLink
                v-for="doc in legalDocList"
                :key="doc.id"
                :to="doc.route"
                class="hover:text-indigo-600 dark:hover:text-indigo-400"
            >
                {{ doc.titleMk }}
            </RouterLink>
        </nav>
    </footer>
</template>
