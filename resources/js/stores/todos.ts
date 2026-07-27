import { defineStore } from 'pinia'
import { ref } from 'vue'
import { todoApi, type TodoItem, type TodoList } from '@/api'

export const useTodoStore = defineStore('todos', () => {
    const lists = ref<TodoList[]>([])
    const loading = ref(false)
    const nextCursor = ref<string | null>(null)

    async function reload() {
        nextCursor.value = null
        loading.value = true
        try {
            const res = await todoApi.list()
            lists.value = res.data
            nextCursor.value = res.meta.next_cursor
        } finally {
            loading.value = false
        }
    }

    async function create(payload: { title: string; due_at?: string | null }): Promise<TodoList> {
        const list = await todoApi.create(payload)
        lists.value.unshift(list)
        return list
    }

    async function remove(id: number) {
        await todoApi.delete(id)
        lists.value = lists.value.filter((list) => list.id !== id)
    }

    async function addItem(listId: number, payload: { title: string; description?: string | null }) {
        const item = await todoApi.addItem(listId, payload)
        findList(listId)?.items.push(item)
        return item
    }

    async function toggleItem(item: TodoItem) {
        const updated = await todoApi.updateItem(item.id, { is_done: !item.is_done })
        replaceItem(updated)
    }

    async function removeItem(item: TodoItem) {
        await todoApi.deleteItem(item.id)
        const list = findList(item.todo_list_id)
        if (list) list.items = list.items.filter((i) => i.id !== item.id)
    }

    function findList(id: number): TodoList | undefined {
        return lists.value.find((list) => list.id === id)
    }

    function replaceItem(item: TodoItem) {
        const list = findList(item.todo_list_id)
        if (!list) return
        const idx = list.items.findIndex((i) => i.id === item.id)
        if (idx !== -1) list.items[idx] = item
    }

    function reset() {
        lists.value = []
        nextCursor.value = null
    }

    return { lists, loading, nextCursor, reload, create, remove, addItem, toggleItem, removeItem, reset }
})
