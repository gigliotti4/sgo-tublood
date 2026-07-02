<script setup lang="ts">
import { router } from '@inertiajs/vue3'

defineProps<{
    links: { url: string | null; label: string; active: boolean }[]
}>()
</script>

<template>
    <div class="flex items-center gap-1">
        <component
            :is="link.url ? 'a' : 'span'"
            v-for="link in links"
            :key="link.label"
            :href="link.url ?? undefined"
            v-html="link.label"
            class="px-2.5 py-1 rounded-md border text-xs transition-colors"
            :class="[
                link.active
                    ? 'bg-[#2a3182] text-white border-[#2a3182]'
                    : link.url
                        ? 'border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer'
                        : 'border-transparent text-slate-300 dark:text-slate-600 cursor-default'
            ]"
            @click.prevent="link.url && router.get(link.url)"
        />
    </div>
</template>
