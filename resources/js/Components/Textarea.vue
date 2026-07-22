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
        <label v-if="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ label }}
            <span v-if="required" class="text-red-500">*</span>
        </label>
        <textarea
            v-model="model"
            :rows="rows"
            v-bind="$attrs"
            class="w-full border rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            :class="error ? 'border-red-400 dark:border-red-500' : 'border-gray-300 dark:border-slate-600'"
        />
        <p v-if="error" class="text-red-500 dark:text-red-400 text-xs mt-1">{{ error }}</p>
        <p v-else-if="hint || $slots.hint" class="text-xs text-gray-500 dark:text-slate-400 mt-1">
            <slot name="hint">{{ hint }}</slot>
        </p>
    </div>
</template>
