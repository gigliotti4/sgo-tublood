<script setup lang="ts">
defineOptions({ inheritAttrs: false })

withDefaults(defineProps<{
    label?: string
    error?: string
    /** Texto de ayuda debajo del campo. Lo tapa el error cuando hay uno. */
    hint?: string
    rows?: number
    required?: boolean
    /** Ocupa las dos columnas de un FormSection. */
    full?: boolean
}>(), {
    rows: 3,
})

const model = defineModel<string | number | null>()
</script>

<template>
    <div :class="full ? 'sm:col-span-2' : ''">
        <label v-if="label" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ label }}
            <span v-if="required" class="text-error-500">*</span>
        </label>
        <textarea
            v-model="model"
            :rows="rows"
            v-bind="$attrs"
            class="w-full rounded-lg border bg-white px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs transition placeholder:text-gray-400 focus:outline-none focus:ring-3 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"
            :class="error
                ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10 dark:border-error-500/60'
                : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800'"
        />
        <p v-if="error" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ error }}</p>
        <p v-else-if="hint || $slots.hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
            <slot name="hint">{{ hint }}</slot>
        </p>
    </div>
</template>
