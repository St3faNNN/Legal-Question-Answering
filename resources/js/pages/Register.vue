<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })
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
            <div>
                <h1 class="text-3xl font-bold text-center text-gray-900 dark:text-gray-100">GraphRAG</h1>
                <p class="mt-2 text-center text-sm text-gray-600 dark:text-gray-400">Create a new account</p>
            </div>
            <form @submit.prevent="handleSubmit" class="mt-8 space-y-6">
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
        </div>
    </div>
</template>