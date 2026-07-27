<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useTodoStore } from '@/stores/todos'
import type { TodoList } from '@/api'

const todos = useTodoStore()

const listForm = reactive({ title: '', due_at: '' })
const creating = ref(false)
const listError = ref<string | null>(null)

// Per-list "add item" drafts, keyed by list id.
const itemDrafts = reactive<Record<number, { title: string; description: string }>>({})

onMounted(async () => {
    await todos.reload()
})

function draftFor(listId: number) {
    if (!itemDrafts[listId]) {
        itemDrafts[listId] = { title: '', description: '' }
    }
    return itemDrafts[listId]
}

async function createList() {
    if (listForm.title.trim() === '') return
    creating.value = true
    listError.value = null
    try {
        await todos.create({
            title: listForm.title.trim(),
            due_at: listForm.due_at !== '' ? listForm.due_at : null,
        })
        listForm.title = ''
        listForm.due_at = ''
    } catch (e: any) {
        listError.value = e.response?.data?.message ?? 'Could not create the list.'
    } finally {
        creating.value = false
    }
}

async function addItem(list: TodoList) {
    const draft = draftFor(list.id)
    if (draft.title.trim() === '') return
    await todos.addItem(list.id, {
        title: draft.title.trim(),
        description: draft.description.trim() !== '' ? draft.description.trim() : null,
    })
    draft.title = ''
    draft.description = ''
}

function formatDate(value: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

function formatDueDate(value: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
    <section>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">To-Do</h2>

        <!-- Create a new list -->
        <form
            class="mt-3 p-4 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 flex flex-col gap-3 sm:flex-row sm:items-end"
            @submit.prevent="createList"
        >
            <div class="flex-1">
                <label for="todo-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">List title</label>
                <input
                    id="todo-title" v-model="listForm.title" type="text" placeholder="e.g. Launch checklist"
                    class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                />
            </div>
            <div>
                <label for="todo-due" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Due to</label>
                <input
                    id="todo-due" v-model="listForm.due_at" type="date"
                    class="mt-1 block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                />
            </div>
            <button
                type="submit" :disabled="creating || listForm.title.trim() === ''"
                class="px-6 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 disabled:opacity-50 transition-colors"
            >
                {{ creating ? 'Adding...' : 'Add list' }}
            </button>
        </form>
        <p v-if="listError" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ listError }}</p>

        <!-- Empty state -->
        <div
            v-if="!todos.loading && todos.lists.length === 0"
            class="mt-4 p-8 text-center border border-dashed border-gray-300 dark:border-gray-700 rounded-xl"
        >
            <p class="text-sm text-gray-500 dark:text-gray-400">No to-do lists yet. Create one above.</p>
        </div>

        <!-- List cards -->
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div
                v-for="list in todos.lists" :key="list.id"
                class="p-6 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ list.title }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-500 mt-0.5">Created {{ formatDate(list.created_at) }}</p>
                        <p v-if="list.due_at" class="text-xs font-medium text-amber-600 dark:text-amber-400 mt-0.5">
                            Due to {{ formatDueDate(list.due_at) }}
                        </p>
                    </div>
                    <button
                        type="button" title="Delete list"
                        class="text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                        @click="todos.remove(list.id)"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>

                <!-- Items (checkboxes) -->
                <ul class="mt-4 space-y-2">
                    <li v-for="item in list.items" :key="item.id" class="flex items-start gap-3 group">
                        <input
                            :id="`item-${item.id}`" type="checkbox" :checked="item.is_done"
                            class="mt-0.5 w-4 h-4 rounded border-gray-300 dark:border-gray-700 text-indigo-600 focus:ring-indigo-500"
                            @change="todos.toggleItem(item)"
                        />
                        <label :for="`item-${item.id}`" class="flex-1 cursor-pointer">
                            <span
                                class="block text-sm font-medium"
                                :class="item.is_done ? 'line-through text-gray-400 dark:text-gray-600' : 'text-gray-900 dark:text-gray-100'"
                            >{{ item.title }}</span>
                            <span v-if="item.description" class="block text-xs text-gray-500 dark:text-gray-400">{{ item.description }}</span>
                        </label>
                        <button
                            type="button" title="Remove item"
                            class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition"
                            @click="todos.removeItem(item)"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </li>
                    <li v-if="list.items.length === 0" class="text-xs text-gray-400 dark:text-gray-600">No items yet.</li>
                </ul>

                <!-- Add item -->
                <form class="mt-4 space-y-2 border-t border-gray-100 dark:border-gray-800 pt-3" @submit.prevent="addItem(list)">
                    <input
                        v-model="draftFor(list.id).title" type="text" placeholder="Item title"
                        class="block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    />
                    <div class="flex gap-2">
                        <input
                            v-model="draftFor(list.id).description" type="text" placeholder="Short explanation (optional)"
                            class="flex-1 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                        />
                        <button
                            type="submit" :disabled="draftFor(list.id).title.trim() === ''"
                            class="px-4 py-1.5 rounded-lg text-sm font-semibold text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-50 dark:hover:bg-indigo-950 disabled:opacity-50 transition-colors"
                        >
                            Add
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
