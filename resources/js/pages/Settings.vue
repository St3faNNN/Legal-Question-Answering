<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import AppShell from '@/components/AppShell.vue'
import { useSettingsStore } from '@/stores/settings'

const store = useSettingsStore()
const form = reactive({
    theme: 'system',
    language: 'en',
    notifications: true,
    default_model: '' as string,
})
const saving = ref(false)
const saved = ref(false)

const languages = [
    { value: 'en', label: 'English' },
    { value: 'es', label: 'Español' },
    { value: 'fr', label: 'Français' },
    { value: 'de', label: 'Deutsch' },
    { value: 'it', label: 'Italiano' },
    { value: 'pt', label: 'Português' },
    { value: 'nl', label: 'Nederlands' },
    { value: 'ru', label: 'Русский' },
    { value: 'ja', label: '日本語' },
    { value: 'zh', label: '中文' },
    { value: 'ko', label: '한국어' },
    { value: 'ar', label: 'العربية' },
    { value: 'tr', label: 'Türkçe' },
    { value: 'pl', label: 'Polski' },
]

onMounted(async () => {
    await store.fetch()
    if (store.settings) {
        form.theme = store.settings.theme
        form.language = store.settings.language
        form.notifications = store.settings.notifications
        form.default_model = store.settings.default_model ?? ''
    }
})

// Live-preview the theme immediately when the user changes the dropdown,
// without waiting for the save button. The persisted value may differ
// if they revert, but this gives instant feedback on the settings page.
watch(() => form.theme, (newTheme) => {
    if (store.settings) {
        store.settings.theme = newTheme
    }
})

async function handleSubmit() {
    saving.value = true
    saved.value = false
    try {
        await store.update({
            ...form,
            default_model: form.default_model || null,
        } as any)
        saved.value = true
        setTimeout(() => { saved.value = false }, 3000)
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <AppShell>
        <div class="max-w-2xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Settings</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Manage your application preferences.</p>

            <form @submit.prevent="handleSubmit" class="mt-8 space-y-6">
                <div>
                    <label for="theme" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Theme</label>
                    <select v-model="form.theme"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        <option value="system">System</option>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                </div>

                <div>
                    <label for="language" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Language</label>
                    <select v-model="form.language"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        <option v-for="lang in languages" :key="lang.value" :value="lang.value">{{ lang.label }}</option>
                    </select>
                </div>

                <div class="flex items-center">
                    <input v-model="form.notifications" type="checkbox" id="notifications"
                        class="w-4 h-4 rounded border-gray-300 dark:border-gray-700 text-indigo-600 focus:ring-indigo-500" />
                    <label for="notifications" class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300">Enable notifications</label>
                </div>

                <div>
                    <label for="default_model" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Default AI Model</label>
                    <input v-model="form.default_model" type="text" placeholder="e.g. bart-finetuned"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Leave empty to use the server default. This will be used when AI services are connected in Phase 2.</p>
                </div>

                <div class="flex items-center gap-4">
                    <button type="submit" :disabled="saving"
                        class="px-6 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 disabled:opacity-50 transition-colors">
                        {{ saving ? 'Saving...' : 'Save changes' }}
                    </button>
                    <span v-if="saved" class="text-sm text-green-600 dark:text-green-400">Settings saved!</span>
                </div>
            </form>
        </div>
    </AppShell>
</template>