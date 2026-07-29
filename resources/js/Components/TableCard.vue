<template>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <slot name="header" />
            </div>
            <div v-if="$slots.actions" class="-mr-1 -mt-1 flex shrink-0 items-center gap-1">
                <slot name="actions" />
            </div>
        </div>
        <dl v-if="$slots.body" class="mt-3 divide-y divide-gray-50 dark:divide-gray-800/60">
            <slot name="body" />
        </dl>
        <div v-if="$slots.footer" class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3 dark:border-gray-800">
            <slot name="footer" />
        </div>
    </div>
</template>
