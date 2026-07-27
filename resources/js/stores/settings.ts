import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingsApi, type Settings } from '@/api'

/**
 * Mirror the resolved theme to localStorage so the pre-mount script in the
 * Blade shell can apply it before Vue loads, avoiding a flash of the wrong theme.
 */
function persistTheme(settings: Settings | null) {
    if (settings?.theme) {
        localStorage.setItem('theme', settings.theme)
    }
}

export const useSettingsStore = defineStore('settings', () => {
    const settings = ref<Settings | null>(null)
    const loading = ref(false)

    async function fetch() {
        loading.value = true
        try {
            settings.value = await settingsApi.get()
            persistTheme(settings.value)
        } catch {
            // Swallow 401 when not authenticated — defaults will be used
        } finally {
            loading.value = false
        }
    }

    async function update(payload: Partial<Settings>) {
        settings.value = await settingsApi.update(payload)
        persistTheme(settings.value)
    }

    function reset() {
        settings.value = null
    }

    return { settings, loading, fetch, update, reset }
})