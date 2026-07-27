import { watchEffect } from 'vue'
import { useSettingsStore } from '@/stores/settings'

/**
 * Applies the user's theme preference by toggling the `dark` class
 * on <html>. Supports 'light', 'dark', and 'system' (which follows
 * the OS prefers-color-scheme media query).
 */
export function useTheme() {
    const settings = useSettingsStore()

    const media = window.matchMedia('(prefers-color-scheme: dark)')

    function apply() {
        // Prefer live settings; fall back to the last value persisted in
        // localStorage (set at login/update) so the theme is correct before
        // settings finish loading; default to 'system' for brand-new sessions.
        const stored = localStorage.getItem('theme')
        const theme =
            settings.settings?.theme ??
            (stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system')
        let isDark: boolean

        if (theme === 'dark') {
            isDark = true
        } else if (theme === 'light') {
            isDark = false
        } else {
            isDark = media.matches
        }

        document.documentElement.classList.toggle('dark', isDark)
    }

    // Re-apply whenever settings change
    watchEffect(apply)

    // Re-apply when OS theme changes (only relevant for 'system')
    media.addEventListener('change', apply)
}