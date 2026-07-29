<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

export interface FabAction {
    key: string
    label: string
    href: string
    icon: string
}

defineProps<{ actions: FabAction[] }>()
const open = defineModel<boolean>('open', { default: false })
</script>

<template>
    <!-- Overlay transparente: cierra el dial al tocar afuera, sin oscurecer la pantalla por dos botones chicos -->
    <div v-if="open" class="fixed inset-0 z-30" @click="open = false" />

    <div class="fixed bottom-6 right-6 z-40 flex flex-col items-end gap-3">
        <TransitionGroup name="dial">
            <Link
                v-for="action in open ? actions : []"
                :key="action.key"
                :href="action.href"
                class="flex h-12 items-center gap-2 rounded-full bg-white pl-4 pr-5 text-sm font-medium text-gray-700 shadow-theme-lg transition hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                @click="open = false"
            >
                <svg class="h-5 w-5 text-brand-500 dark:text-brand-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="action.icon" />
                </svg>
                {{ action.label }}
            </Link>
        </TransitionGroup>

        <button
            type="button"
            class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-500 text-white shadow-theme-lg transition hover:scale-105 hover:bg-brand-600"
            :aria-expanded="open"
            aria-haspopup="true"
            title="Nueva observación"
            @click="open = !open"
        >
            <svg class="h-6 w-6 transition-transform duration-200" :class="open ? 'rotate-45' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span class="sr-only">{{ open ? 'Cerrar menú de acciones' : 'Nueva observación' }}</span>
        </button>
    </div>
</template>

<style scoped>
.dial-enter-active,
.dial-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.dial-enter-from,
.dial-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.9);
}
</style>
