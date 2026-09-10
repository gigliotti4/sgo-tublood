<script setup lang="ts">
import { computed } from 'vue'
import type { KpisCompras } from '@/lib/compras'
import { decimal, moneda, numero } from '@/lib/formato'

const props = defineProps<{
    kpis: KpisCompras
    mesesObjetivo: number
}>()

const objetivo = computed(() => decimal(props.mesesObjetivo).replace(',0', ''))

interface Tarjeta {
    label: string
    valor: string
    hint: string
    tono?: 'crit' | 'good' | 'muted'
}

const tarjetas = computed<Tarjeta[]>(() => [
    {
        label: 'Multimarca GTIN',
        valor: numero(props.kpis.total),
        hint: `${props.kpis.categorias} categorías`,
    },
    {
        label: 'A comprar ya',
        valor: numero(props.kpis.aComprar),
        hint: `no cubren ${objetivo.value} mes(es)`,
        tono: 'crit',
    },
    {
        label: 'Stock OK',
        valor: numero(props.kpis.stockOk),
        hint: 'cubren el objetivo',
        tono: 'good',
    },
    {
        label: 'Sin venta',
        valor: numero(props.kpis.sinVenta),
        hint: 'sin ventas en el período',
        tono: 'muted',
    },
    {
        label: 'Cantidad de meses en stock',
        valor: decimal(props.kpis.promedioMeses),
        // La mediana va al lado del promedio porque el promedio se dispara con
        // productos de stock alto y venta mínima: coberturas de cientos de meses
        // arrastran el número y lo vuelven ilegible.
        hint: `promedio · mediana ${decimal(props.kpis.medianaMeses)} · ${numero(props.kpis.conCobertura)} prod.`,
    },
    {
        label: 'Facturación del período',
        valor: moneda(props.kpis.facturacion),
        hint: `${numero(props.kpis.claseA)} prod. hacen el 80% · ${numero(props.kpis.claseB)} el 20%`,
    },
    {
        label: 'Objetivo de cobertura',
        valor: objetivo.value,
        hint: 'meses de stock',
    },
])

const tonos: Record<string, string> = {
    crit: 'text-error-500 dark:text-error-400',
    good: 'text-success-600 dark:text-success-400',
    muted: 'text-gray-400',
}
</script>

<template>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-7">
        <div
            v-for="t in tarjetas"
            :key="t.label"
            class="rounded-2xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-white/[0.03]"
        >
            <p class="text-theme-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ t.label }}
            </p>
            <p
                class="mt-1 text-2xl font-bold tabular-nums text-gray-800 dark:text-white/90"
                :class="t.tono ? tonos[t.tono] : ''"
            >
                {{ t.valor }}
            </p>
            <p class="mt-0.5 text-theme-xs text-gray-400">{{ t.hint }}</p>
        </div>
    </div>
</template>
