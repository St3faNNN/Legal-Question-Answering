import client from './client'

export interface User {
    id: number
    name: string
    email: string
    bio: string | null
    email_verified_at: string | null
    created_at: string
    updated_at: string
    last_active_at: string | null
}

export interface LoginPayload {
    email: string
    password: string
}

export interface RegisterPayload {
    name: string
    email: string
    password: string
    password_confirmation: string
}

export const authApi = {
    async login(payload: LoginPayload) {
        const { data } = await client.post<{ data: User }>('/auth/login', payload)
        return data.data
    },

    async register(payload: RegisterPayload) {
        const { data } = await client.post<{ data: User }>('/auth/register', payload)
        return data.data
    },

    async logout() {
        await client.post('/auth/logout')
    },

    async fetchUser() {
        const { data } = await client.get<{ data: User }>('/user', { _skipAuthRedirect: true } as any)
        return data.data
    },

    async updateProfile(payload: { name?: string; bio?: string | null }) {
        const { data } = await client.patch<{ data: User }>('/profile', payload)
        return data.data
    },
}

export const tokenApi = {
    async create(name: string, abilities: string[] = ['*']) {
        const { data } = await client.post<{ token: string; name: string }>('/tokens', { name, abilities })
        return data
    },

    async list() {
        const { data } = await client.get('/tokens')
        return data
    },

    async revoke(id: number) {
        await client.delete(`/tokens/${id}`)
    },
}

export interface Settings {
    theme: string
    language: string
    notifications: boolean
    default_model: string | null
}

export const settingsApi = {
    async get() {
        // Skip the interceptor's hard redirect: settings is fetched at app
        // bootstrap (App.vue) even when logged out, and a 401 there must not
        // bounce the public Landing page to /login.
        const { data } = await client.get<{ data: Settings }>('/settings', { _skipAuthRedirect: true } as any)
        return data.data
    },

    async update(payload: Partial<Settings>) {
        const { data } = await client.put<{ data: Settings }>('/settings', payload)
        return data.data
    },
}

export interface Conversation {
    id: number
    title: string | null
    display_title?: string
    created_at: string
    updated_at: string
    messages?: Message[]
}

export interface GraphTriple {
    head: string
    relation: string
    tail: string
    confidence: number | null
}

export interface MessageMetadata {
    entities?: string[]
    triple_count?: number
    top_triples?: GraphTriple[]
    context?: string
    retrieval_latency_ms?: number | null
    generation_latency_ms?: number | null
    [key: string]: unknown
}

export interface Message {
    id: number
    conversation_id: number
    role: string
    content: string
    model: string | null
    tokens: number | null
    latency_ms: number | null
    metadata: MessageMetadata | null
    created_at: string
}

export const conversationApi = {
    async list(params?: { cursor?: string }) {
        const { data } = await client.get<{ data: Conversation[]; meta: { next_cursor: string | null } }>('/conversations', { params })
        return data
    },

    async create(title?: string) {
        const { data } = await client.post<{ data: Conversation }>('/conversations', { title })
        return data.data
    },

    async show(id: number) {
        const { data } = await client.get<{ data: Conversation }>(`/conversations/${id}`)
        return data.data
    },

    async rename(id: number, title: string) {
        const { data } = await client.patch<{ data: Conversation }>(`/conversations/${id}`, { title })
        return data.data
    },

    async delete(id: number) {
        await client.delete(`/conversations/${id}`)
    },

    async messages(id: number, params?: { cursor?: string }) {
        const { data } = await client.get<{ data: Message[]; meta: { next_cursor: string | null } }>(`/conversations/${id}/messages`, { params })
        return data
    },
}

export const chatApi = {
    async send(message: string, conversationId?: number) {
        const { data } = await client.post<{
            conversation: Conversation
            message: Message
        }>('/chat', { message, conversation_id: conversationId })
        return data
    },

    // Streaming variant: dispatches a queued job and returns a stream id the
    // client subscribes to via EventSource (see the chat store).
    async stream(message: string, conversationId?: number) {
        const { data } = await client.post<{
            conversation: Conversation
            stream_id: string
        }>('/chat/stream', { message, conversation_id: conversationId })
        return data
    },
}

export interface TodoItem {
    id: number
    todo_list_id: number
    title: string
    description: string | null
    is_done: boolean
    created_at: string
    updated_at: string
}

export interface TodoList {
    id: number
    title: string
    due_at: string | null
    created_at: string
    updated_at: string
    items: TodoItem[]
}

export interface NewsArticle {
    id: number
    title: string
    url: string
    source: string
    published_at: string | null
    created_at: string
}

export const newsApi = {
    async latest(limit = 3) {
        const { data } = await client.get<{ data: NewsArticle[] }>('/news', { params: { limit } })
        return data.data
    },
}

export const todoApi = {
    async list(params?: { cursor?: string }) {
        const { data } = await client.get<{ data: TodoList[]; meta: { next_cursor: string | null } }>('/todo-lists', { params })
        return data
    },

    async create(payload: { title: string; due_at?: string | null }) {
        const { data } = await client.post<{ data: TodoList }>('/todo-lists', payload)
        return data.data
    },

    async update(id: number, payload: { title?: string; due_at?: string | null }) {
        const { data } = await client.patch<{ data: TodoList }>(`/todo-lists/${id}`, payload)
        return data.data
    },

    async delete(id: number) {
        await client.delete(`/todo-lists/${id}`)
    },

    async addItem(listId: number, payload: { title: string; description?: string | null }) {
        const { data } = await client.post<{ data: TodoItem }>(`/todo-lists/${listId}/items`, payload)
        return data.data
    },

    async updateItem(itemId: number, payload: { title?: string; description?: string | null; is_done?: boolean }) {
        const { data } = await client.patch<{ data: TodoItem }>(`/todo-items/${itemId}`, payload)
        return data.data
    },

    async deleteItem(itemId: number) {
        await client.delete(`/todo-items/${itemId}`)
    },
}