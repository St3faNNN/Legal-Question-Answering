import type { DefineComponent } from 'vue'
import type { AxiosInstance } from 'axios'

declare module '*.vue' {
    const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
    export default component
}

export {}

// Shim for window.axios used in bootstrap.js
declare global {
    interface Window {
        axios: AxiosInstance
    }
}