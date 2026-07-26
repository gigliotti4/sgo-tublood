<script setup lang="ts">
defineOptions({ inheritAttrs: false })

defineProps<{
    label?: string
    error?: string
    /** Texto de ayuda debajo del campo. Lo tapa el error cuando hay uno. */
    hint?: string
    required?: boolean
    /** Ocupa las dos columnas de un FormSection. */
    full?: boolean
}>()

const model = defineModel<string | number | null>()
</script>

<template>
    <div :class="full ? 'sm:col-span-2' : ''">
        <label v-if="label" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ label }}
            <span v-if="required" class="text-error-500">*</span>
        </label>
        <div class="relative">
            <select
                v-model="model"
                v-bind="$attrs"
                class="h-11 w-full appearance-none rounded-lg border bg-white py-2.5 pl-4 pr-10 text-sm text-gray-800 shadow-theme-xs transition focus:outline-none focus:ring-3 disabled:bg-gray-100 disabled:text-gray-500 dark:bg-gray-900 dark:text-white/90 dark:disabled:bg-gray-800 dark:disabled:text-gray-500"
                :class="error
                    ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10 dark:border-error-500/60'
                    : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800'"
            >
                <slot />
            </select>
            <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </span>
        </div>
        <p v-if="error" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ error }}</p>
        <p v-else-if="hint || $slots.hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
            <slot name="hint">{{ hint }}</slot>
        </p>
    </div>
</template>
