import axios from 'axios'

const client = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
})

// Request interceptor: attach Bearer token if available
client.interceptors.request.use((config) => {
    const token = localStorage.getItem('api_token')
    if (token) {
        config.headers.Authorization = `Bearer ${token}`
    }
    return config
})

// Response interceptor: handle 401 by redirecting to login (but avoid loops)
client.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('api_token')
            // Skip the redirect for requests that opt out via the
            // `skipAuthRedirect` config flag (used by the bootstrap
            // auth check so it doesn't loop while already on /login).
            const skipRedirect = error.config?._skipAuthRedirect === true
            if (!skipRedirect && !window.location.pathname.startsWith('/login')) {
                window.location.href = '/login'
            }
        }
        return Promise.reject(error)
    },
)

export async function initCsrf(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export default client