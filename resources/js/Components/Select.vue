<script setup lang="ts">
defineOptions({ inheritAttrs: false })

defineProps<{
    label?: string
    error?: string
}>()

const model = defineModel<string | number | null>()
</script>

<template>
    <div>
        <label v-if="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ label }}
        </label>
        <select
            v-model="model"
            v-bind="$attrs"
            class="w-full border rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 focus:ring-2 focus:ring-indigo-500 focus:outline-none"
            :class="error ? 'border-red-400 dark:border-red-500' : 'border-gray-300 dark:border-slate-600'"
        >
            <slot />
        </select>
        <p v-if="error" class="text-red-500 dark:text-red-400 text-xs mt-1">{{ error }}</p>
    </div>
</template>
