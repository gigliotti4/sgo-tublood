<script setup lang="ts">
withDefaults(defineProps<{
    variant?: 'slate' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'amber' | 'red'
    pill?: boolean
}>(), {
    variant: 'slate',
    pill: true,
})

const variantClasses: Record<string, string> = {
    slate: 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-600',
    blue: 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
    indigo: 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800',
    purple: 'bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800',
    emerald: 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
    amber: 'bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800',
    red: 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
}
</script>

<template>
    <span
        class="inline-flex items-center px-2 py-0.5 text-xs font-medium mr-1 mb-1"
        :class="[variantClasses[variant], pill ? 'rounded-full border' : 'rounded']"
    >
        <slot />
    </span>
</template>
