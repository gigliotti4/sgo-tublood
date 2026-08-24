<script setup lang="ts">
import { ref } from 'vue'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
    label: string
    error?: string
    type?: string
    /** Dibuja el toggle de mostrar/ocultar, para campos de contraseña. */
    esPassword?: boolean
}>(), {
    type: 'text',
})

const model = defineModel<string>()

const mostrar = ref(false)
</script>

<template>
    <div class="space-y-1.5">
        <label class="block text-sm font-medium text-corp-700">
            {{ label }}
        </label>
        <div class="relative">
            <span v-if="$slots.icon" class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <slot name="icon" />
            </span>
            <input
                v-model="model"
                :type="esPassword ? (mostrar ? 'text' : 'password') : type"
                v-bind="$attrs"
                class="w-full py-2.5 text-sm rounded-lg border bg-white text-corp-900 placeholder-corp-400 transition duration-150 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                :class="[
                    $slots.icon ? 'pl-9' : 'px-3',
                    esPassword ? 'pr-10' : 'pr-3',
                    error
                        ? 'border-red-400 ring-1 ring-red-300'
                        : 'border-corp-200 hover:border-corp-300',
                ]"
            />
            <button
                v-if="esPassword"
                type="button"
                @click="mostrar = !mostrar"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-corp-400 hover:text-corp-600 transition"
                :aria-label="mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña'"
            >
                <!-- Ojo abierto -->
                <svg v-if="!mostrar" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                <!-- Ojo tachado -->
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                </svg>
            </button>
        </div>
        <p v-if="error" class="flex items-center gap-1.5 text-red-500 text-xs">
            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            {{ error }}
        </p>
    </div>
</template>
