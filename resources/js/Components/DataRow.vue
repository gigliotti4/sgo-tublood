<script setup lang="ts">
defineProps<{ label: string }>()
</script>

<template>
    <div class="flex items-start justify-between gap-3 py-1.5 text-theme-sm">
        <dt class="shrink-0 text-gray-500 dark:text-gray-400">{{ label }}</dt>
        <dd class="min-w-0 text-right text-gray-800 dark:text-white/90">
            <slot />
        </dd>
    </div>
</template>
