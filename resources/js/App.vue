<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'
import { useTheme } from '@/composables/useTheme'

const settingsStore = useSettingsStore()

// Load settings once at app bootstrap so the theme is applied early.
// Swallow errors here — if the user isn't authenticated yet (e.g. on
// the login page), the settings API returns 401 and we just skip the
// theme application until settings are loaded from the Settings page.
onMounted(async () => {
    try {
        await settingsStore.fetch()
    } catch {
        // Not authenticated yet — theme will default to 'system'
    }
})

// Watch settings.theme and toggle the `dark` class on <html>
useTheme()
</script>

<template>
    <RouterView />
</template>