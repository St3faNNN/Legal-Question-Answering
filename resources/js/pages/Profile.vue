<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api'

const auth = useAuthStore()
const router = useRouter()
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

// --- Data-subject rights ---

const exporting = ref(false)

async function exportData() {
    exporting.value = true
    try {
        const blob = await authApi.exportData()
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `my-data-${new Date().toISOString().slice(0, 10)}.json`
        document.body.appendChild(a)
        a.click()
        a.remove()
        URL.revokeObjectURL(url)
    } finally {
        exporting.value = false
    }
}

const showDelete = ref(false)
const deletePassword = ref('')
const deleting = ref(false)
const deleteError = ref<string | null>(null)

function cancelDelete() {
    showDelete.value = false
    deletePassword.value = ''
    deleteError.value = null
}

async function deleteAccount() {
    deleting.value = true
    deleteError.value = null
    try {
        await authApi.deleteAccount(deletePassword.value)
        auth.user = null
        router.push({ name: 'landing' })
    } catch (e: any) {
        deleteError.value = e.response?.data?.errors?.password?.[0]
            ?? e.response?.data?.message
            ?? 'Deletion failed'
    } finally {
        deleting.value = false
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

            <!-- Export (right of access / portability) -->
            <section class="mt-10 border-t border-gray-200 dark:border-gray-800 pt-8">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Your data</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Download a copy of your account, conversations and messages in a portable JSON file.
                </p>
                <button @click="exportData" :disabled="exporting"
                    class="mt-3 px-5 py-2.5 rounded-lg text-sm font-semibold border border-gray-300 dark:border-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-50 transition-colors">
                    {{ exporting ? 'Preparing...' : 'Export my data' }}
                </button>
            </section>

            <!-- Delete (right to erasure) -->
            <section class="mt-10 border-t border-red-200 dark:border-red-900/50 pt-8">
                <h2 class="text-lg font-semibold text-red-700 dark:text-red-400">Delete account</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Permanently delete your account and all associated data — conversations, messages and consent
                    records. This action cannot be undone.
                </p>

                <button v-if="!showDelete" @click="showDelete = true"
                    class="mt-3 px-5 py-2.5 rounded-lg text-sm font-semibold border border-red-300 dark:border-red-800 text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors">
                    Delete my account
                </button>

                <div v-else class="mt-4 space-y-3 max-w-sm">
                    <label for="delete_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm your password</label>
                    <input id="delete_password" v-model="deletePassword" type="password" autocomplete="current-password"
                        class="block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:ring-1 focus:ring-red-500"
                        :class="{ 'border-red-500': deleteError }" />
                    <p v-if="deleteError" class="text-xs text-red-600">{{ deleteError }}</p>
                    <div class="flex gap-3">
                        <button @click="deleteAccount" :disabled="deleting || !deletePassword"
                            class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 transition-colors">
                            {{ deleting ? 'Deleting...' : 'Permanently delete' }}
                        </button>
                        <button @click="cancelDelete"
                            class="px-5 py-2.5 rounded-lg text-sm font-semibold border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AppShell>
</template>
