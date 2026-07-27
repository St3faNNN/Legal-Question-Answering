<script setup lang="ts">
import { reactive, ref } from 'vue'
import AppShell from '@/components/AppShell.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const form = reactive({ name: '', bio: '' })
const saving = ref(false)
const saved = ref(false)
const errors = ref<Record<string, string>>({})

function initForm() {
    form.name = auth.user?.name ?? ''
    form.bio = auth.user?.bio ?? ''
}

initForm()

async function handleSubmit() {
    errors.value = {}
    saving.value = true
    saved.value = false
    try {
        await auth.updateProfile({ name: form.name, bio: form.bio || null })
        saved.value = true
        setTimeout(() => { saved.value = false }, 3000)
    } catch (e: any) {
        if (e.response?.data?.errors) {
            errors.value = e.response.data.errors
        }
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <AppShell>
        <div class="max-w-2xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">User Profile</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Update your personal information.</p>

            <div class="mt-8 flex items-center">
                <div class="w-16 h-16 rounded-full bg-indigo-100 flex items-center justify-center text-2xl font-bold text-indigo-700">
                    {{ auth.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                </div>
                <div class="ml-4">
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ auth.user?.name }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ auth.user?.email }}</p>
                </div>
            </div>

            <form @submit.prevent="handleSubmit" class="mt-8 space-y-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                    <input v-model="form.name" type="text" id="name"
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        :class="{ 'border-red-500': errors.name }" />
                    <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name }}</p>
                </div>

                <div>
                    <label for="bio" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Bio</label>
                    <textarea v-model="form.bio" id="bio" rows="4" placeholder="Tell us about yourself..."
                        class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        :class="{ 'border-red-500': errors.bio }"></textarea>
                    <p v-if="errors.bio" class="mt-1 text-xs text-red-600">{{ errors.bio }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input type="email" :value="auth.user?.email" disabled
                        class="mt-1 block w-full rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-100 dark:bg-gray-800 px-3 py-2 text-sm text-gray-500 dark:text-gray-400" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Email cannot be changed.</p>
                </div>

                <div class="flex items-center gap-4">
                    <button type="submit" :disabled="saving"
                        class="px-6 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        {{ saving ? 'Saving...' : 'Save profile' }}
                    </button>
                    <span v-if="saved" class="text-sm text-green-600">Profile updated!</span>
                </div>
            </form>
        </div>
    </AppShell>
</template>