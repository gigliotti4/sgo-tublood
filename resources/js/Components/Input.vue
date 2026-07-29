<script setup lang="ts">
defineOptions({ inheritAttrs: false })

withDefaults(defineProps<{
    label?: string
    error?: string
    /** Texto de ayuda debajo del campo. Lo tapa el error cuando hay uno. */
    hint?: string
    type?: string
    required?: boolean
    /** Ocupa las dos columnas de un FormSection. */
    full?: boolean
}>(), {
    type: 'text',
})

const model = defineModel<string | number | null>()
</script>

<template>
    <div :class="full ? 'sm:col-span-2' : ''">
        <label v-if="label || $slots.label" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
            <slot name="label">{{ label }}</slot>
            <span v-if="required" class="text-error-500">*</span>
        </label>
        <div class="relative">
            <span v-if="$slots.icon" class="absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                <slot name="icon" />
            </span>
            <input
                v-model="model"
                :type="type"
                v-bind="$attrs"
                class="h-11 w-full rounded-lg border bg-white px-4 py-2.5 text-[16px] text-gray-800 shadow-theme-xs transition placeholder:text-gray-400 focus:outline-none focus:ring-3 disabled:bg-gray-100 disabled:text-gray-500 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:disabled:bg-gray-800 dark:disabled:text-gray-500 sm:text-sm"
                :class="[
                    $slots.icon ? 'pl-10' : '',
                    error
                        ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10 dark:border-error-500/60'
                        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800',
                ]"
            />
        </div>
        <p v-if="error" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ error }}</p>
        <p v-else-if="hint || $slots.hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
            <slot name="hint">{{ hint }}</slot>
        </p>
    </div>
</template>
