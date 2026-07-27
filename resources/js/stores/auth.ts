import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi, type User, type LoginPayload, type RegisterPayload } from '@/api'
import { initCsrf } from '@/api/client'
import { useSettingsStore } from '@/stores/settings'

export const useAuthStore = defineStore('auth', () => {
    const user = ref<User | null>(null)
    const loading = ref(false)
    const error = ref<string | null>(null)

    const isAuthenticated = computed(() => user.value !== null)

    async function login(payload: LoginPayload) {
        loading.value = true
        error.value = null
        try {
            await initCsrf()
            user.value = await authApi.login(payload)
            // Load the just-authenticated user's settings so the theme applies
            // immediately. Non-fatal: a settings hiccup must not make a
            // successful login look like it failed.
            await loadSettingsQuietly()
        } catch (e: any) {
            error.value = e.response?.data?.message ?? 'Login failed'
            throw e
        } finally {
            loading.value = false
        }
    }

    async function register(payload: RegisterPayload) {
        loading.value = true
        error.value = null
        try {
            await initCsrf()
            user.value = await authApi.register(payload)
            await loadSettingsQuietly()
        } catch (e: any) {
            error.value = e.response?.data?.message ?? 'Registration failed'
            throw e
        } finally {
            loading.value = false
        }
    }

    // Load settings after auth without letting a failure bubble up and mask a
    // successful login/registration.
    async function loadSettingsQuietly() {
        try {
            await useSettingsStore().fetch()
        } catch {
            // Non-fatal — theme falls back to default until Settings is opened.
        }
    }

    async function logout() {
        try {
            await authApi.logout()
        } finally {
            user.value = null
            localStorage.removeItem('api_token')
        }
    }

    async function fetchUser() {
        try {
            user.value = await authApi.fetchUser()
        } catch (e: any) {
            // Swallow 401 during bootstrap so it doesn't trigger the
            // axios interceptor's full-page redirect to /login.
            user.value = null
        }
    }

    async function updateProfile(payload: { name?: string; bio?: string | null }) {
        user.value = await authApi.updateProfile(payload)
    }

    return { user, loading, error, isAuthenticated, login, register, logout, fetchUser, updateProfile }
})