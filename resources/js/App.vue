<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'
import { useThemeStore } from '@/stores/theme'

const settingsStore = useSettingsStore()
const themeStore = useThemeStore()

// Apply the persisted/system theme immediately and keep 'system' in sync with
// the OS. (The Blade shell already applied it pre-paint; this is the runtime
// owner from here on.)
themeStore.init()

onMounted(async () => {
    // Once the user's settings load, adopt the server-stored theme so the
    // preference follows them across devices. Guests (401) keep the local one.
    try {
        await settingsStore.fetch()
        if (settingsStore.settings?.theme) {
            themeStore.syncFromServer(settingsStore.settings.theme as never)
        }
    } catch {
        // Not authenticated — keep the localStorage/system theme.
    }
})
</script>

<template>
    <RouterView />
</template>
