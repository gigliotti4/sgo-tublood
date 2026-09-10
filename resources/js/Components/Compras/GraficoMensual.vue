<script setup lang="ts">
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { useDarkMode } from '@/composables/useDarkMode'
import { etiquetaMes, numero } from '@/lib/formato'

/**
 * Ventas mes a mes de un producto. Se abre desde el sparkline de la tabla.
 *
 * Acá sí va ApexCharts (y no divs como en `Sparkline`) porque hay UNO abierto a
 * la vez: se puede pagar el costo de la librería a cambio de tooltips y ejes.
 *
 * Los meses fuera del período elegido se dibujan más claros en vez de
 * ocultarse: el punto es ver el historial completo y dónde cae el recorte.
 */
const props = defineProps<{
    titulo: string
    meses: string[]
    valores: number[]
    desde: number
    hasta: number
}>()

const { isDark } = useDarkMode()

const series = computed(() => [{ name: 'Unidades', data: props.valores }])

const opciones = computed<ApexOptions>(() => ({
    chart: {
        type: 'bar',
        fontFamily: 'Poppins, sans-serif',
        toolbar: { show: false },
        foreColor: '#98a2b3',
        animations: { enabled: false },
    },
    colors: ['#001489'],
    plotOptions: {
        bar: { columnWidth: '55%', borderRadius: 3, borderRadiusApplication: 'end' },
    },
    // Un color por barra: las de fuera del período van atenuadas.
    fill: {
        opacity: props.valores.map((_, i) => (i >= props.desde && i <= props.hasta ? 1 : 0.3)),
    },
    states: { hover: { filter: { type: 'darken', value: 0.9 } } },
    dataLabels: { enabled: false },
    grid: {
        borderColor: isDark.value ? 'rgba(255,255,255,0.08)' : '#f2f4f7',
        yaxis: { lines: { show: true } },
    },
    xaxis: {
        categories: props.meses.map(etiquetaMes),
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { style: { fontSize: '10px' }, rotate: -45, rotateAlways: true },
    },
    yaxis: {
        labels: { style: { fontSize: '10px' }, formatter: (v: number) => numero(v) },
    },
    tooltip: {
        theme: isDark.value ? 'dark' : 'light',
        y: { formatter: (v: number) => `${numero(v)} u.` },
    },
}))
</script>

<template>
    <div>
        <p class="mb-2 text-xs font-bold text-gray-800 dark:text-white/90">
            Ventas mes a mes (unidades) — {{ titulo }}
        </p>
        <div class="max-w-[1100px]">
            <VueApexCharts type="bar" height="180" :options="opciones" :series="series" />
        </div>
    </div>
</template>
