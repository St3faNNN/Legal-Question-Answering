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
    /** Deploy capabilities; escalation is absent unless an agent service is configured. */
    features?: { escalation: boolean; admin: boolean }
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
    invite_code: string
    accept_terms: boolean
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

    // Download all data the system holds about the user, as a JSON blob.
    async exportData(): Promise<Blob> {
        const { data } = await client.get('/account/export', { responseType: 'blob' })
        return data as Blob
    },

    // Permanently delete the account, confirmed with the current password.
    async deleteAccount(password: string) {
        await client.delete('/account', { data: { password } })
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

export interface RetrievedChunk {
    id: string
    text: string
    source: string
    score: number | null
}

/** One tool call the agent issued, as it appears live and in a stored trace. */
export interface AgentSearch {
    tool: string
    query: string
    /** Citations returned; null while the search is still running. */
    sources: string[] | null
}

/** One step of a stored agent trajectory. */
export interface AgentTraceStep {
    index: number
    durationMs: number | null
    tokens: number | null
    reasoning: string | null
    calls: Array<{ tool: string; query: string; sources: string[]; error: string | null }>
}

export interface AgentTrace {
    run_id: string
    question: string
    outcome: string
    model: string | null
    totals: { steps: number; api_calls: number; total_tokens: number; duration_ms: number }
    steps: AgentTraceStep[]
    passages: RetrievedChunk[]
    created_at: string
}

export interface MessageMetadata {
    entities?: string[]
    triple_count?: number
    top_triples?: GraphTriple[]
    chunk_count?: number
    top_chunks?: RetrievedChunk[]
    context_items?: number | null
    context?: string
    retrieval_latency_ms?: number | null
    generation_latency_ms?: number | null
    /** End-to-end: received query -> final generated word (ms). */
    total_latency_ms?: number | null
    /** Request received -> first token (ms). Governs perceived speed. */
    ttft_ms?: number | null
    /** Retrieval call start -> context assembled (ms). */
    retrieval_ms?: number | null
    /** Request received -> final token (ms); alias of total_latency_ms. */
    total_ms?: number | null
    /** Non-legal input answered from a canned message: no retrieval, no model call. */
    gated?: boolean
    /** The answer is out-of-corpus; the provisions panel is suppressed server-side. */
    refused?: boolean
    /** True when the agent produced this answer rather than the pipeline. */
    agent?: boolean
    run_id?: string | null
    /** The queries the run issued, kept so they survive a reload. */
    searches?: Array<{ tool: string; query: string }>
    agent_steps?: number
    agent_api_calls?: number
    agent_tokens?: number
    /** Articles the answer cites that no retrieved passage backs. */
    uncited_articles?: string[]
    escalated_from_message_id?: number | null
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
    // Dispatches a queued job and returns a stream id the client subscribes to
    // via EventSource (see the chat store). There is no unary alternative: the
    // non-streaming POST /chat was removed along with its server-side path.
    async stream(message: string, conversationId?: number) {
        const { data } = await client.post<{
            conversation: Conversation
            stream_id: string
        }>('/chat/stream', { message, conversation_id: conversationId })
        return data
    },

    // Re-answer the question behind an existing reply with the research agent.
    // Same stream contract as above — the events differ, not the transport.
    //
    // `questionMessageId` is required. The server will not infer which question
    // an answer belongs to: it used to, by walking back to the nearest preceding
    // user message, and that walk returned the conversation's FIRST question
    // instead of the escalated one. The thread is rendered here, so the pairing
    // is known here; the server validates it rather than guessing.
    async escalate(conversationId: number, messageId: number, questionMessageId: number) {
        const { data } = await client.post<{
            conversation: Conversation
            stream_id: string
            escalated_from: number
            cost_units: number
            remaining_units: number
        }>('/chat/escalate', {
            conversation_id: conversationId,
            message_id: messageId,
            question_message_id: questionMessageId,
        })
        return data
    },

    // The stored trajectory behind an escalated answer.
    async trace(conversationId: number, messageId: number) {
        const { data } = await client.get<{ data: AgentTrace }>(
            `/conversations/${conversationId}/messages/${messageId}/trace`,
        )
        return data.data
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

/** One latency series, always carrying the sample it was computed from. */
export interface Distribution {
    available: boolean
    n: number
    out_of: number
    coverage?: number
    min?: number
    p50?: number
    p90?: number
    p95?: number
    p99?: number
    max?: number
    histogram?: Array<{ label: string; count: number }>
}

export interface AdminMetrics {
    generated_at: string
    window: { days: number; from: string; to: string }
    volume: {
        questions: number
        answers: number
        conversations: number
        users_active: number
        by_path: Record<string, number>
        daily: Array<Record<string, number> & { date: string; total: number }>
    }
    latency: Record<'retrieval_ms' | 'ttft_ms' | 'total_ms' | 'generation_latency_ms', Distribution> & {
        total_messages: number
        by_model: Array<{ model: string; answers: number; p50_ms: number | null; p95_ms: number | null; last_seen: string | null }>
        /** Audit line for the by-model table: answers that arrived without a model name. */
        null_model: { count: number; out_of: number; gated_excluded: number; note: string }
    }
    tokens: {
        escalation: { available: boolean; runs: number; total: number; mean: number | null; min: number | null; max: number | null }
        // Deliberately not a number: the hosted driver streams and returns no
        // usage block, so there is nothing to report and nothing to estimate.
        pipeline: { available: false; reason: string; legacy_rows_with_tokens: number }
        budget: {
            daily_units: number
            pipeline_cost: number
            escalation_cost: number
            today: Array<{ user_id: number; name: string; used: number; remaining: number }>
        }
    }
    agent: {
        runs: number
        by_outcome: Record<string, number>
        steps: { mean: number | null; min: number | null; max: number | null }
        searches_per_run: { mean: number | null; min: number | null; max: number | null }
        recent: Array<{
            id: number; run_id: string; outcome: string; model: string | null
            steps: number; api_calls: number; searches: number
            total_tokens: number; duration_ms: number
            message_id: number; conversation_id: number | null; created_at: string | null
        }>
    }
    failures: {
        refusals: { count: number; out_of: number; note: string }
        gated: { count: number; out_of: number }
        failed_jobs: { total: number; recent: Array<{ id: number; job: string; failed_at: string; reason: string }> }
    }
}

/** A row in the exchange list. Carries NO question or answer text by design. */
export interface AdminExchange {
    id: number
    conversation_id: number
    created_at: string | null
    path: string
    model: string | null
    retrieval_ms: number | null
    ttft_ms: number | null
    total_ms: number | null
    latency_ms: number | null
    chunk_count: number | null
    context_items: number | null
    tokens: number | null
    agent_tokens: number | null
    run_id: string | null
    agent_run_id: number | null
    citations: string[]
    /** Count only — the query strings are question-adjacent and live in the detail. */
    search_count: number
    uncited_articles: string[]
    question_chars: number | null
    answer_chars: number
}

/** One exchange WITH its text — only ever fetched by an explicit action. */
export interface AdminExchangeDetail extends AdminExchange {
    searches: string[]
    question: string | null
    question_id: number | null
    answer: string
    metadata: MessageMetadata | null
}

export const adminApi = {
    async metrics() {
        const { data } = await client.get<{ data: AdminMetrics }>('/admin/metrics')
        return data.data
    },

    // Metadata only. The text lives behind exchange() below.
    async exchanges(path?: string, cursor?: string) {
        const { data } = await client.get<{ data: AdminExchange[]; meta: { next_cursor: string | null } }>(
            '/admin/exchanges',
            { params: { path, cursor } },
        )
        return data
    },

    // The explicit action: read one exchange's question and answer.
    async exchange(id: number) {
        const { data } = await client.get<{ data: AdminExchangeDetail }>(`/admin/exchanges/${id}`)
        return data.data
    },

    async trace(agentRunId: number) {
        const { data } = await client.get<{ data: AgentTrace }>(`/admin/agent-runs/${agentRunId}/trace`)
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