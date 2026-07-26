<script setup lang="ts">
withDefaults(defineProps<{
    variant?: 'slate' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'amber' | 'red'
    pill?: boolean
}>(), {
    variant: 'slate',
    pill: true,
})

const variantClasses: Record<string, string> = {
    slate: 'bg-gray-100 text-gray-700 dark:bg-white/[0.06] dark:text-gray-400',
    blue: 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
    indigo: 'bg-brand-50 text-brand-500 dark:bg-brand-500/25 dark:text-brand-300',
    purple: 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-400',
    emerald: 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
    amber: 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400',
    red: 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400',
}
</script>

<template>
    <span
        class="mb-1 mr-1 inline-flex items-center px-2.5 py-0.5 text-theme-xs font-medium"
        :class="[variantClasses[variant], pill ? 'rounded-full' : 'rounded-md']"
    >
        <slot />
    </span>
</template>
