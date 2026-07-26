<script setup lang="ts">
withDefaults(defineProps<{
    variant?: 'primary' | 'brand' | 'outline' | 'danger' | 'danger-text'
    type?: 'button' | 'submit'
}>(), {
    variant: 'primary',
    type: 'button',
})

const base = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50'

const variantClasses: Record<string, string> = {
    primary: `${base} bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600`,
    brand: `${base} bg-brand-500 text-white shadow-theme-xs hover:bg-brand-600`,
    outline: `${base} border border-gray-300 bg-white text-gray-700 shadow-theme-xs hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]`,
    danger: `${base} bg-error-500 text-white shadow-theme-xs hover:bg-error-600`,
    'danger-text': 'text-sm font-medium text-error-600 hover:text-error-700 hover:underline dark:text-error-400 disabled:cursor-not-allowed disabled:opacity-50',
}
</script>

<template>
    <button :type="type" class="cursor-pointer" :class="variantClasses[variant]">
        <slot />
    </button>
</template>
