<script setup lang="ts">
import { router } from '@inertiajs/vue3'

/**
 * Paginador de los listados del panel. Las URLs ya vienen armadas por el
 * paginador de Laravel con `withQueryString()`, así que llevan los filtros y el
 * orden vigentes.
 *
 * `preserveScroll` porque sin él cambiar de página tira al usuario al tope y
 * hay que volver a bajar hasta el paginador para dar el click siguiente.
 *
 * ⚠️ **Sin `preserveState`, a propósito.** No compra nada acá (las props viajan
 * enteras igual, no es un partial reload) y a cambio dejaría el estado local
 * apuntando a filas que ya no están en pantalla: `userToDelete` en
 * `Admin/Users/Index.vue` y `roleToDelete` en `Admin/Roles/Index.vue` guardan el
 * objeto entero, así que su modal quedaría abierto con un registro de la página
 * anterior. Hoy el remonte del componente es justamente lo que los limpia. Si
 * alguna pantalla llegara a necesitarlo, se decide ahí y no en este componente,
 * que lo comparten los once listados.
 *
 * ⚠️ Y **sin `replace`**: a diferencia de filtrar u ordenar, cambiar de página
 * tiene que dejar entrada en el historial para que el botón "atrás" del
 * navegador vuelva a la página anterior.
 */
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
            @click.prevent="link.url && router.get(link.url, {}, { preserveScroll: true })"
        />
    </div>
</template>
