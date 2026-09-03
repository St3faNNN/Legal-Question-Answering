import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('@/pages/Login.vue'), meta: { guest: true } },
    { path: '/register', name: 'register', component: () => import('@/pages/Register.vue'), meta: { guest: true } },
    { path: '/dashboard', name: 'dashboard', component: () => import('@/pages/Dashboard.vue'), meta: { auth: true } },
    { path: '/chat', name: 'chat', component: () => import('@/pages/Chat.vue'), meta: { auth: true } },
    { path: '/chat/:id', name: 'chat-conversation', component: () => import('@/pages/Chat.vue'), meta: { auth: true } },
    { path: '/history', name: 'history', component: () => import('@/pages/History.vue'), meta: { auth: true } },
    { path: '/settings', name: 'settings', component: () => import('@/pages/Settings.vue'), meta: { auth: true } },
    { path: '/profile', name: 'profile', component: () => import('@/pages/Profile.vue'), meta: { auth: true } },
    // Operational dashboard. `auth` only: the real gate is server-side on the
    // data endpoints, which 404 for a non-allowlisted user, so this route renders
    // an empty shell rather than leaking anything if someone types the URL.
    { path: '/admin', name: 'admin', component: () => import('@/pages/admin/Metrics.vue'), meta: { auth: true } },
    { path: '/', name: 'landing', component: () => import('@/pages/Landing.vue'), meta: { guest: true } },
    // Legal documents — public, reachable whether signed in or not (no auth/guest meta).
    { path: '/privacy', name: 'privacy', component: () => import('@/pages/legal/PrivacyPolicy.vue') },
    { path: '/terms', name: 'terms', component: () => import('@/pages/legal/TermsOfUse.vue') },
    { path: '/disclaimer', name: 'disclaimer', component: () => import('@/pages/legal/Disclaimer.vue') },
    { path: '/:pathMatch(.*)', redirect: '/dashboard' },
]

export const router = createRouter({
    history: createWebHistory(),
    routes,
})

// Track whether the initial auth check has resolved so the first
// navigation doesn't redirect before we know the user's state.
let authBootstrapped = false
let bootstrapPromise: Promise<void> | null = null

export async function bootstrapAuth(): Promise<void> {
    if (bootstrapPromise) return bootstrapPromise
    bootstrapPromise = (async () => {
        const auth = useAuthStore()
        if (auth.user === null) {
            await auth.fetchUser()
        }
        authBootstrapped = true
    })()
    return bootstrapPromise
}

router.beforeEach(async (to) => {
    // Ensure the auth state is known before guarding the very first navigation
    if (!authBootstrapped) {
        await bootstrapAuth()
    }

    const auth = useAuthStore()

    if (to.meta.auth && !auth.isAuthenticated) {
        return { name: 'login' }
    }

    if (to.meta.guest && auth.isAuthenticated) {
        return { name: 'dashboard' }
    }
})