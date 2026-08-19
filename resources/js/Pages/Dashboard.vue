<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { route } from 'ziggy-js'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import Badge from '@/Components/Badge.vue'
import Icon from '@/Components/Icon.vue'
import { useDarkMode } from '@/composables/useDarkMode'

const { isDark } = useDarkMode()

interface EstadoCount {
    estado: string
    label: string
    count: number
}

/** Una fila del panel que se despliega al pasar el mouse por una tarjeta. */
interface ItemTarjeta {
    id: number
    numero: string
    titulo: string
    estado: string
}

interface ListaTarjeta {
    items: ItemTarjeta[]
    total: number
}

interface UltimaObservacion {
    id: number
    numero: string
    tipo: string
    estado: string
    titulo: string
    created_at: string
}

const props = defineProps<{
    stats: {
        total: number
        abiertas: number
        cerradas: number
        asignadasAMi: number
        nc: number
        ncAbiertas: number
    }
    kpis: {
        tiempoSla: number | null
        critica: number
        sinClasificar: number
    }
    /**
     * Lo que lista el hover de "Abiertas" y "Asignadas a mí". Recortado en el
     * backend: `total` es cuántas hay de verdad, para el "y N más".
     */
    listas: {
        abiertas: ListaTarjeta
        asignadasAMi: ListaTarjeta
    }
    porEstado: EstadoCount[]
    /** Todos los estados, incluida `cancelada` (que `porEstado` excluye). */
    estadoLabels: Record<string, string>
    porSector: { sector: string; count: number }[]
    asignadas: UltimaObservacion[]
    ultimas: UltimaObservacion[]
    tipoLabels: Record<string, string>
}>()

const estadoVariant: Record<string, 'amber' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'slate' | 'red'> = {
    pendiente_clasificacion: 'amber',
    clasificada: 'blue',
    en_proceso: 'indigo',
    derivada: 'purple',
    cerrada: 'emerald',
    cancelada: 'red',
}

const estadoColor: Record<string, string> = {
    pendiente_clasificacion: '#f79009',
    clasificada: '#3b82f6',
    en_proceso: '#6373c4',
    derivada: '#8b5cf6',
    cerrada: '#12b76a',
    cancelada: '#f04438',
}

const estadoLabel = (estado: string) => props.estadoLabels[estado] ?? estado

const formatFecha = (d: string) =>
    new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })

const totalEstados = computed(() => props.porEstado.reduce((acc, e) => acc + e.count, 0))

// Barras: observaciones por sector
const sectorChartOptions = computed<ApexOptions>(() => ({
    chart: {
        type: 'bar',
        fontFamily: 'Outfit, sans-serif',
        toolbar: { show: false },
        foreColor: '#98a2b3',
    },
    colors: ['#2a3182'],
    plotOptions: {
        bar: { horizontal: false, columnWidth: '39%', borderRadius: 5, borderRadiusApplication: 'end' },
    },
    dataLabels: { enabled: false },
    stroke: { show: true, width: 4, colors: ['transparent'] },
    grid: {
        borderColor: isDark.value ? 'rgba(255,255,255,0.08)' : '#f2f4f7',
        yaxis: { lines: { show: true } },
    },
    xaxis: {
        categories: props.porSector.map(s => s.sector),
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { style: { fontSize: '12px' } },
    },
    yaxis: { labels: { style: { fontSize: '12px' } } },
    tooltip: { theme: isDark.value ? 'dark' : 'light', y: { formatter: (v: number) => `${v}` } },
    states: { hover: { filter: { type: 'darken', value: 0.9 } } },
}))

const sectorChartSeries = computed(() => [
    { name: 'Observaciones', data: props.porSector.map(s => s.count) },
])

// Donut: observaciones por estado
const estadoChartOptions = computed<ApexOptions>(() => ({
    chart: { type: 'donut', fontFamily: 'Outfit, sans-serif', foreColor: '#98a2b3' },
    labels: props.porEstado.map(e => e.label),
    colors: props.porEstado.map(e => estadoColor[e.estado] ?? '#98a2b3'),
    dataLabels: { enabled: false },
    legend: { position: 'bottom', fontSize: '13px', markers: { size: 5 }, itemMargin: { horizontal: 8, vertical: 3 } },
    stroke: { show: false },
    plotOptions: {
        pie: {
            donut: {
                size: '72%',
                labels: {
                    show: true,
                    total: {
                        show: true,
                        label: 'Total',
                        fontSize: '13px',
                        color: '#98a2b3',
                        formatter: () => `${totalEstados.value}`,
                    },
                    value: {
                        fontSize: '24px',
                        fontWeight: 700,
                        color: isDark.value ? '#ffffff' : '#101828',
                    },
                },
            },
        },
    },
    tooltip: { theme: isDark.value ? 'dark' : 'light' },
}))

const estadoChartSeries = computed(() => props.porEstado.map(e => e.count))

// Íconos de las tarjetas de métricas (Heroicons outline)
const statIcons: Record<string, string> = {
    document: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9h3.75M12 15.75h5.25M8.25 9h1.5m-1.5 3.75h1.5m-1.5 3.75h1.5M6.75 3h6.879a2.25 2.25 0 011.591.659l4.121 4.121a2.25 2.25 0 01.659 1.591V19.5a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25V5.25A2.25 2.25 0 016.75 3z',
    inbox: 'M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z',
    check: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    user: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
    flag: 'M3 3v1.5M3 21v-6m0 0l2.77-.693a9 9 0 016.208.682l.108.054a9 9 0 006.086.71l3.114-.732a48.524 48.524 0 01-.005-10.499l-3.11.732a9 9 0 01-6.085-.711l-.108-.054a9 9 0 00-6.208-.682L3 4.5M3 15V4.5',
}

interface StatCard {
    label: string
    value: number
    icon: string
    iconClass: string
    iconBg: string
    sub?: string
    /**
     * Solo las tarjetas que la traen despliegan el panel al pasar el mouse.
     * Las otras tres no: "Observaciones" y "Cerradas" listarían prácticamente
     * todo el sistema, y No Conformidades todavía no existe.
     */
    lista?: ListaTarjeta
}

const statCards = computed<StatCard[]>(() => [
    { label: 'Observaciones', value: props.stats.total, icon: 'document', iconClass: 'text-brand-500 dark:text-brand-300', iconBg: 'bg-brand-50 dark:bg-brand-500/[0.12]' },
    { label: 'Abiertas', value: props.stats.abiertas, icon: 'inbox', iconClass: 'text-warning-600 dark:text-warning-400', iconBg: 'bg-warning-50 dark:bg-warning-500/15', lista: props.listas.abiertas },
    { label: 'Cerradas', value: props.stats.cerradas, icon: 'check', iconClass: 'text-success-600 dark:text-success-400', iconBg: 'bg-success-50 dark:bg-success-500/15' },
    { label: 'Asignadas a mí', value: props.stats.asignadasAMi, icon: 'user', iconClass: 'text-blue-600 dark:text-blue-400', iconBg: 'bg-blue-50 dark:bg-blue-500/15', lista: props.listas.asignadasAMi },
    { label: 'No Conformidades', value: props.stats.nc, icon: 'flag', iconClass: 'text-purple-600 dark:text-purple-400', iconBg: 'bg-purple-50 dark:bg-purple-500/15', sub: `${props.stats.ncAbiertas} abiertas` },
])
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Panel de control</h1>
            <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">Resumen del sistema</p>
        </div>

        <!-- Stat cards -->
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5 md:gap-6">
            <!-- `group` + `relative`: el panel de abajo se despliega al pasar el
                 mouse por la tarjeta. `focus-within` lo abre tambien con el
                 teclado, que de otra forma no llegaria nunca. -->
            <div
                v-for="card in statCards"
                :key="card.label"
                class="group relative rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]"
                :class="card.lista ? 'focus-within:z-30 hover:z-30' : ''"
            >
                <div class="flex h-12 w-12 items-center justify-center rounded-xl" :class="card.iconBg">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-6 w-6" :class="card.iconClass">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="statIcons[card.icon]" />
                    </svg>
                </div>
                <div class="mt-4">
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ card.label }}</p>
                    <div class="flex items-end justify-between">
                        <p class="mt-1 text-2xl font-bold text-gray-800 dark:text-white/90">{{ card.value }}</p>
                        <span v-if="card.sub" class="text-theme-xs text-gray-400">{{ card.sub }}</span>
                    </div>
                </div>

                <!-- Panel del hover. Sin separacion real con la tarjeta (el aire
                     lo pone el `pt-2` de adentro) para que el mouse pueda
                     entrar a la lista sin que se cierre en el camino. -->
                <div
                    v-if="card.lista"
                    class="pointer-events-none absolute left-0 top-full z-30 hidden w-full min-w-72 pt-2 group-focus-within:block group-hover:block sm:w-80"
                >
                    <div class="pointer-events-auto max-h-80 overflow-y-auto rounded-xl border border-gray-200 bg-white p-2 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
                        <p v-if="card.lista.items.length === 0" class="px-2 py-3 text-center text-theme-xs text-gray-400">
                            No hay observaciones {{ card.label.toLowerCase() }}.
                        </p>
                        <Link
                            v-for="o in card.lista.items"
                            :key="o.id"
                            :href="route('observaciones.show', o.id)"
                            class="flex items-start gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.05]"
                        >
                            <span class="mt-0.5 shrink-0 font-mono text-theme-xs text-gray-400">{{ o.numero }}</span>
                            <span class="min-w-0 flex-1 truncate text-theme-xs text-gray-700 dark:text-gray-200">{{ o.titulo }}</span>
                            <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabel(o.estado) }}</Badge>
                        </Link>
                        <p
                            v-if="card.lista.total > card.lista.items.length"
                            class="px-2 pb-1 pt-2 text-theme-xs text-gray-400"
                        >
                            y {{ card.lista.total - card.lista.items.length }} más…
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI row -->
        <div class="mb-6 grid grid-cols-1 divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-white/[0.03] sm:grid-cols-2 sm:divide-y-0 sm:divide-x xl:grid-cols-3">
            <div class="px-6 py-5">
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">KPI: Tiempo &lt;72h</p>
                <p class="mt-1 text-2xl font-bold" :class="(kpis.tiempoSla ?? 0) >= 85 ? 'text-success-600 dark:text-success-400' : 'text-error-500 dark:text-error-400'">
                    {{ kpis.tiempoSla ?? 0 }}%
                </p>
                <p class="mt-0.5 text-theme-xs text-gray-400">Meta &gt; 85%</p>
            </div>
            <div class="px-6 py-5">
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Crítica</p>
                <p class="mt-1 text-2xl font-bold text-error-500 dark:text-error-400">{{ kpis.critica }}</p>
            </div>
            <div class="px-6 py-5">
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Sin clasificar</p>
                <p class="mt-1 text-2xl font-bold text-warning-600 dark:text-warning-400">{{ kpis.sinClasificar }}</p>
            </div>
        </div>

        <!-- Charts -->
        <div class="mb-6 grid grid-cols-1 gap-4 md:gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Observaciones por sector</h2>
                <div v-if="porSector.length === 0" class="flex h-64 items-center justify-center text-sm text-gray-400">
                    Sin datos
                </div>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="280"
                    :options="sectorChartOptions"
                    :series="sectorChartSeries"
                />
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Por estado</h2>
                <div v-if="totalEstados === 0" class="flex h-64 items-center justify-center text-sm text-gray-400">
                    Sin datos
                </div>
                <VueApexCharts
                    v-else
                    type="donut"
                    height="300"
                    :options="estadoChartOptions"
                    :series="estadoChartSeries"
                />
            </div>
        </div>

        <!-- Asignadas a mí -->
        <div class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Asignadas a mí</h2>
                <Link :href="route('observaciones.index')" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200">
                    Ver todas →
                </Link>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">N°</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tipo</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Título</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                            <th class="px-6 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-if="asignadas.length === 0">
                            <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-400">
                                No tenés observaciones asignadas.
                            </td>
                        </tr>
                        <tr v-for="o in asignadas" :key="o.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <td class="px-6 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ o.numero }}</td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">{{ tipoLabels[o.tipo] ?? o.tipo }}</td>
                            <td class="px-6 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ o.titulo }}</td>
                            <td class="px-6 py-3.5">
                                <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabel(o.estado) }}</Badge>
                            </td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ formatFecha(o.created_at) }}</td>
                            <td class="px-6 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    <Link
                                        :href="route('observaciones.show', o.id)"
                                        class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                        title="Ver detalle"
                                    >
                                        <Icon name="eye" class="h-4.5 w-4.5" />
                                        <span class="sr-only">Ver detalle de {{ o.numero }}</span>
                                    </Link>
                                    <!-- El editor es el modal del listado de
                                         observaciones: se abre alla en vez de
                                         duplicarlo (ver `editar` en su Index). -->
                                    <Link
                                        :href="route('observaciones.index', { q: o.numero, editar: o.id })"
                                        class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                        title="Editar"
                                    >
                                        <Icon name="pencil" class="h-4.5 w-4.5" />
                                        <span class="sr-only">Editar {{ o.numero }}</span>
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Últimas observaciones -->
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-4">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Últimas observaciones</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">N°</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tipo</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Título</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-if="ultimas.length === 0">
                            <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-400">
                                Todavía no se cargaron observaciones.
                            </td>
                        </tr>
                        <tr v-for="o in ultimas" :key="o.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <td class="px-6 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ o.numero }}</td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">{{ tipoLabels[o.tipo] ?? o.tipo }}</td>
                            <td class="px-6 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ o.titulo }}</td>
                            <td class="px-6 py-3.5">
                                <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabel(o.estado) }}</Badge>
                            </td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ formatFecha(o.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
