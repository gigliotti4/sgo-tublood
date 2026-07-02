<script setup lang="ts">
withDefaults(defineProps<{
    variant?: 'primary' | 'brand' | 'danger' | 'danger-text'
    type?: 'button' | 'submit'
}>(), {
    variant: 'primary',
    type: 'button',
})

const variantClasses: Record<string, string> = {
    primary: 'bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition',
    brand: 'inline-flex items-center gap-2 bg-[#2a3182] text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-[#1e2460] disabled:opacity-60 disabled:cursor-not-allowed transition-colors',
    danger: 'bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition',
    'danger-text': 'text-red-600 dark:text-red-400 hover:underline text-sm',
}
</script>

<template>
    <button :type="type" class="cursor-pointer" :class="variantClasses[variant]">
        <slot />
    </button>
</template>
