<script setup lang="ts">
/**
 * Mini-gráfico de barras para meter dentro de una celda de tabla.
 *
 * Son divs con `height` en porcentaje y no un chart de ApexCharts a propósito:
 * en el tablero de reposición se dibujan hasta 500 de estos por página, y
 * montar 500 instancias de una librería de gráficos congela el navegador. El
 * gráfico "de verdad" (el que se abre al hacer clic) sí usa ApexCharts.
 *
 * Las barras fuera del rango marcado se atenúan en vez de ocultarse: así se ve
 * el historial completo y dónde cae el período elegido.
 */
withDefaults(
    defineProps<{
        valores: number[]
        /** Índices (inclusive) que quedan dentro del período resaltado. */
        desde?: number
        hasta?: number
        clickable?: boolean
        activo?: boolean
    }>(),
    { desde: 0, hasta: Number.MAX_SAFE_INTEGER, clickable: false, activo: false },
)

/** El máximo nunca baja de 1 para no dividir por cero en un producto sin ventas. */
const alturaDe = (valor: number, valores: number[]): string => {
    const max = Math.max(1, ...valores)
    return `${Math.max(4, (Math.max(0, valor) / max) * 100)}%`
}
</script>

<template>
    <span
        class="inline-flex h-6 w-[88px] items-end gap-px"
        :class="
            clickable
                ? [
                      'cursor-pointer rounded-md border px-1.5 py-0.5 transition-colors',
                      activo
                          ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/15'
                          : 'border-transparent hover:border-brand-500 hover:bg-brand-50 dark:hover:bg-brand-500/15',
                  ]
                : ''
        "
        :title="clickable ? 'Clic para ver el gráfico mes a mes' : 'Ventas mensuales (unidades)'"
    >
        <i
            v-for="(valor, i) in valores"
            :key="i"
            class="block min-h-px flex-1 rounded-t-[1px] bg-brand-500 dark:bg-brand-400"
            :class="{ 'opacity-30': i < desde || i > hasta }"
            :style="{ height: alturaDe(valor, valores) }"
        />
    </span>
</template>
