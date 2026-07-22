<script setup lang="ts">
/**
 * Bloque titulado dentro de un formulario. Por defecto acomoda los campos en
 * dos columnas; un campo puede ocupar el ancho completo con su prop `full`.
 */
withDefaults(defineProps<{
    title: string
    description?: string
    columns?: 1 | 2
}>(), {
    columns: 2,
})
</script>

<template>
    <section class="space-y-4">
        <div class="border-b border-slate-100 dark:border-slate-700 pb-2">
            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ title }}</h2>
            <p v-if="description" class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ description }}</p>
        </div>
        <div :class="columns === 2 ? 'grid sm:grid-cols-2 gap-4' : 'space-y-4'">
            <slot />
        </div>
    </section>
</template>
