<script setup lang="ts">
import { computed } from 'vue'

/**
 * Contador que va al lado del título de un listado, para ver cuántos registros
 * hay sin tener que scrollear hasta el pie de la tabla.
 *
 * Con una búsqueda activa muestra "18 de 4.312": el total sin filtrar importa
 * tanto como el filtrado, si no no se sabe si el catálogo está vacío o si la
 * búsqueda no encontró nada.
 */
const props = defineProps<{
    /** Cuántos hay cargados en total, sin filtrar. */
    total: number
    /** Cuántos devolvió la búsqueda vigente. Igual a `total` cuando no hay filtro. */
    filtrados: number
}>()

const formatear = (n: number) => n.toLocaleString('es-AR')

const filtrando = computed(() => props.filtrados !== props.total)
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300"
        :title="filtrando ? `${formatear(filtrados)} de ${formatear(total)} en total` : `${formatear(total)} en total`"
    >
        <template v-if="filtrando">{{ formatear(filtrados) }} de {{ formatear(total) }}</template>
        <template v-else>{{ formatear(total) }}</template>
    </span>
</template>
