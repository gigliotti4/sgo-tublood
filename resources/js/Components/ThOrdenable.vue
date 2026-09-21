<script setup lang="ts">
import type { OrdenVigente } from '@/composables/useOrdenamiento'

/**
 * Encabezado de tabla clickeable para ordenar.
 *
 * Es puro: recibe el orden vigente y emite la clave clickeada. No sabe de
 * Inertia ni de rutas, así que lo comparten los listados que ordenan en el
 * servidor (vía `useOrdenamiento`) y los que ordenan en el cliente (Sectores,
 * que manda sus 9 filas enteras).
 */
const props = withDefaults(
    defineProps<{
        /**
         * Clave de la whitelist del backend — es lo que viaja como `?sort=`.
         * `null` = columna no ordenable: se dibuja igual que antes, sin cursor
         * ni flecha. Mismo convenio que `key: null` en las columnas de
         * `Components/Compras/TablaReposicion.vue`.
         */
        campo?: string | null
        orden: OrdenVigente
        /** Para las columnas numéricas, que van alineadas a la derecha. */
        align?: 'left' | 'right'
        /**
         * El padding horizontal es prop y no una clase pasada desde afuera
         * porque los listados usan px-4, px-5 o px-6 según la página: una clase
         * heredada convivría con la de acá en el mismo atributo y el ganador lo
         * decidiría el orden del CSS generado por Tailwind, no el del template.
         */
        pad?: string
    }>(),
    { campo: null, align: 'left', pad: 'px-4' },
)

const emit = defineEmits<{ ordenar: [string] }>()

const activa = () => props.campo !== null && props.orden.sort === props.campo

/**
 * Caracteres Unicode y no un ícono SVG: `Components/Icon.vue` no tiene flecha
 * de ordenamiento, y es el mismo recurso que ya usa la tabla de Compras —
 * meter dos vocabularios visuales para la misma acción sería peor que el
 * carácter.
 */
const flecha = () => (activa() ? (props.orden.dir === 'asc' ? '▲' : '▼') : '⇅')

const titulo = () =>
    activa()
        ? props.orden.dir === 'asc'
            ? 'Orden ascendente — clic para invertir'
            : 'Orden descendente — clic para invertir'
        : 'Ordenar por esta columna'
</script>

<template>
    <!--
        Clases del resto del panel y NO las de `Compras/TablaReposicion.vue`:
        esa tabla tiene su propio estilo denso (uppercase, 10.5px) porque
        muestra 15 columnas numéricas a la vez, y copiarlo acá desalinearía
        los listados entre sí.
    -->
    <th
        scope="col"
        class="py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400"
        :class="[
            pad,
            align === 'right' ? 'text-right' : 'text-left',
            campo ? 'cursor-pointer select-none transition-colors hover:text-brand-500 dark:hover:text-brand-300' : '',
        ]"
        :aria-sort="activa() ? (orden.dir === 'asc' ? 'ascending' : 'descending') : 'none'"
        :title="campo ? titulo() : undefined"
        @click="campo && emit('ordenar', campo)"
    >
        <slot />
        <span
            v-if="campo"
            class="ml-0.5 text-[9px]"
            :class="activa() ? 'text-brand-500 dark:text-brand-300' : 'opacity-40'"
        >
            {{ flecha() }}
        </span>
    </th>
</template>
