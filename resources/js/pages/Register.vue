<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LegalFooter from '@/components/LegalFooter.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'

const router = useRouter()
const auth = useAuthStore()
const form = reactive({ invite_code: '', name: '', email: '', password: '', password_confirmation: '', accept_terms: false })
const errors = ref<Record<string, string>>({})
const submitting = ref(false)

async function handleSubmit() {
    errors.value = {}
    submitting.value = true
    try {
        await auth.register({ ...form })
        router.push({ name: 'dashboard' })
    } catch (e: any) {
        if (e.response?.data?.errors) {
            errors.value = e.response.data.errors
        } else {
            errors.value = { email: e.response?.data?.message ?? 'Registration failed' }
        }
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-950 py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div class="flex justify-end">
                <ThemeToggle />
            </div>
            <div>
                <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-gray-100">GraphRAG</h1>
                <p class="mt-2 text-center text-sm text-gray-600 dark:text-gray-400">Registration is by invitation only</p>
            </div>
            <form @submit.prevent="handleSubmit" class="mt-8 space-y-6">
                <div>
                    <label for="invite_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Invitation code</label>
                    <input id="invite_code" v-model="form.invite_code" type="text" required autocomplete="off"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        :class="{ 'border-red-500': errors.invite_code }" />
                    <p v-if="errors.invite_code" class="mt-1 text-xs text-red-600">{{ errors.invite_code }}</p>
                </div>
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                    <input id="name" v-model="form.name" type="text" required autocomplete="name"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        :class="{ 'border-red-500': errors.name }" />
                    <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name }}</p>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input id="email" v-model="form.email" type="email" required autocomplete="email"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        :class="{ 'border-red-500': errors.email }" />
                    <p v-if="errors.email" class="mt-1 text-xs text-red-600">{{ errors.email }}</p>
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Password</label>
                    <input id="password" v-model="form.password" type="password" required autocomplete="new-password"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        :class="{ 'border-red-500': errors.password }" />
                    <p v-if="errors.password" class="mt-1 text-xs text-red-600">{{ errors.password }}</p>
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm Password</label>
                    <input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        :class="{ 'border-red-500': errors.password_confirmation }" />
                    <p v-if="errors.password_confirmation" class="mt-1 text-xs text-red-600">{{ errors.password_confirmation }}</p>
                </div>
                <div>
                    <label class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <input
                            v-model="form.accept_terms" type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-gray-300 dark:border-gray-700 text-indigo-600 focus:ring-indigo-500"
                            :class="{ 'ring-1 ring-red-500': errors.accept_terms }"
                        />
                        <span>
                            I have read and accept the
                            <RouterLink :to="{ name: 'terms' }" target="_blank" class="font-medium text-indigo-600 hover:text-indigo-500">Terms of Use</RouterLink>
                            and the
                            <RouterLink :to="{ name: 'privacy' }" target="_blank" class="font-medium text-indigo-600 hover:text-indigo-500">Privacy Policy</RouterLink>.
                        </span>
                    </label>
                    <p v-if="errors.accept_terms" class="mt-1 text-xs text-red-600">{{ errors.accept_terms }}</p>
                </div>
                <div>
                    <button type="submit" :disabled="submitting"
                        class="w-full flex justify-center py-2.5 px-4 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        {{ submitting ? 'Creating account...' : 'Create account' }}
                    </button>
                </div>
                <p class="text-center text-sm text-gray-600 dark:text-gray-400">
                    Already have an account?
                    <RouterLink :to="{ name: 'login' }" class="font-medium text-indigo-600 hover:text-indigo-500">Sign in</RouterLink>
                </p>
            </form>

            <LegalFooter class="pt-2" />
        </div>
    </div>
</template>