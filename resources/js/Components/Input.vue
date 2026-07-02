<script setup lang="ts">
defineOptions({ inheritAttrs: false })

withDefaults(defineProps<{
    label?: string
    error?: string
    type?: string
}>(), {
    type: 'text',
})

const model = defineModel<string | number | null>()
</script>

<template>
    <div>
        <label v-if="label || $slots.label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            <slot name="label">{{ label }}</slot>
        </label>
        <div class="relative">
            <span v-if="$slots.icon" class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
                <slot name="icon" />
            </span>
            <input
                v-model="model"
                :type="type"
                v-bind="$attrs"
                class="w-full border rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                :class="[
                    $slots.icon ? 'pl-9' : '',
                    error ? 'border-red-400 dark:border-red-500' : 'border-gray-300 dark:border-slate-600',
                ]"
            />
        </div>
        <p v-if="error" class="text-red-500 dark:text-red-400 text-xs mt-1">{{ error }}</p>
    </div>
</template>
