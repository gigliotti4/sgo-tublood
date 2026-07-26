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
    <section class="space-y-5">
        <div class="border-b border-gray-100 pb-3 dark:border-gray-800">
            <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ description }}</p>
        </div>
        <div :class="columns === 2 ? 'grid gap-5 sm:grid-cols-2' : 'space-y-5'">
            <slot />
        </div>
    </section>
</template>
