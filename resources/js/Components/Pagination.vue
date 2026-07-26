<script setup lang="ts">
import { router } from '@inertiajs/vue3'

defineProps<{
    links: { url: string | null; label: string; active: boolean }[]
}>()
</script>

<template>
    <div class="flex items-center gap-1.5">
        <component
            :is="link.url ? 'a' : 'span'"
            v-for="link in links"
            :key="link.label"
            :href="link.url ?? undefined"
            v-html="link.label"
            class="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-medium transition-colors"
            :class="[
                link.active
                    ? 'bg-brand-500 text-white shadow-theme-xs'
                    : link.url
                        ? 'cursor-pointer border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.05]'
                        : 'cursor-default text-gray-300 dark:text-gray-600'
            ]"
            @click.prevent="link.url && router.get(link.url)"
        />
    </div>
</template>
