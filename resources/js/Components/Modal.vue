<script lang="ts">
import { ref } from 'vue'

/**
 * Cuántos Modal hay abiertos en toda la app ahora mismo.
 *
 * Lo consulta el aviso de reclamos externos nuevos en AppLayout.vue: como ese
 * aviso puede aparecer en cualquier pantalla (llega por polling, no solo al
 * entrar), se abstiene de abrirse si ya hay otro modal — si no, se apilaría
 * encima del modal de clasificación de Observaciones/Index, que es justo
 * donde Calidad trabaja.
 */
export const modalesAbiertos = ref(0)
</script>

<script setup lang="ts">
import { onUnmounted, watch } from 'vue'

const props = withDefaults(defineProps<{
    show: boolean
    title?: string
    size?: 'sm' | 'lg' | 'xl' | '2xl'
}>(), {
    size: 'sm',
})

const emit = defineEmits<{ close: [] }>()

const sizeClasses: Record<string, string> = {
    sm: 'max-w-lg',
    lg: 'max-w-3xl',
    xl: 'max-w-5xl',
    '2xl': 'max-w-[88rem]',
}

watch(() => props.show, abierto => {
    modalesAbiertos.value += abierto ? 1 : -1
})

// Por si la página se abandona (navegación de Inertia) con el modal todavía
// abierto: sin esto el contador queda trabado y el aviso no vuelve a abrirse.
onUnmounted(() => {
    if (props.show) modalesAbiertos.value -= 1
})
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="emit('close')" />
                <div
                    class="relative w-full max-h-[90vh] overflow-y-auto rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900"
                    :class="sizeClasses[size]"
                >
                    <button
                        class="absolute right-4 top-4 flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-gray-500 transition hover:bg-gray-200 hover:text-gray-700 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/[0.08] dark:hover:text-gray-200"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <h3 v-if="title" class="mb-5 pr-10 text-lg font-semibold text-gray-800 dark:text-white/90">{{ title }}</h3>
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
