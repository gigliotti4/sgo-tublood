import type { route as routeFn } from 'ziggy-js'

/**
 * `ZiggyVue` (registrado en app.ts) inyecta `route()` como global, tanto en el
 * setup como en los templates. Sin esta declaración, `vue-tsc` marca un error
 * en cada página que arma una URL.
 */
declare global {
    const route: typeof routeFn
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof routeFn
    }
}

export {}
