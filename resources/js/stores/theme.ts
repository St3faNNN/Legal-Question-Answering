import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingsApi } from '@/api'
import { useAuthStore } from '@/stores/auth'

export type Theme = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'theme'

function readStored(): Theme {
    const t = localStorage.getItem(STORAGE_KEY)
    return t === 'light' || t === 'dark' || t === 'system' ? t : 'system'
}

/**
 * Single source of truth for the applied theme. Runtime application (toggling
 * the `dark` class on <html>) lives here; the Blade shell applies the same
 * localStorage value before first paint to avoid a flash.
 *
 * Persistence is layered: always to localStorage (so guests keep their choice
 * and the pre-paint script can read it), and additionally to the user's record
 * (UserSetting.theme) when signed in, so the preference follows them.
 */
export const useThemeStore = defineStore('theme', () => {
    const theme = ref<Theme>(readStored())
    const media = window.matchMedia('(prefers-color-scheme: dark)')

    function isDark(): boolean {
        if (theme.value === 'dark') {
            return true
        }
        if (theme.value === 'light') {
            return false
        }

        return media.matches
    }

    function apply(): void {
        document.documentElement.classList.toggle('dark', isDark())
    }

    /** Change the theme: apply now, mirror to localStorage, sync to the server when signed in. */
    function setTheme(next: Theme): void {
        theme.value = next
        localStorage.setItem(STORAGE_KEY, next)
        apply()

        if (useAuthStore().isAuthenticated) {
            settingsApi.update({ theme: next }).catch(() => {
                // Non-fatal: the local choice still applies; only cross-device
                // sync is missed if the request fails.
            })
        }
    }

    /** Adopt the server's stored theme on login/bootstrap without writing back. */
    function syncFromServer(next: Theme): void {
        theme.value = next
        localStorage.setItem(STORAGE_KEY, next)
        apply()
    }

    /** Apply once and keep 'system' in sync with the OS setting. */
    function init(): void {
        apply()
        media.addEventListener('change', () => {
            if (theme.value === 'system') {
                apply()
            }
        })
    }

    return { theme, setTheme, syncFromServer, init }
})
