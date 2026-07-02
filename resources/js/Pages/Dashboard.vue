<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'

interface EstadoCount {
    estado: string
    label: string
    count: number
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
        resueltas: number
        asignadasAMi: number
        nc: number
        ncAbiertas: number
    }
    kpis: {
        tiempoSla: number | null
        tecnovigilancia: number
        critica: number
        sinClasificar: number
    }
    porEstado: EstadoCount[]
    porSector: { sector: string; count: number }[]
    asignadas: UltimaObservacion[]
    ultimas: UltimaObservacion[]
}>()

const tipoLabels: Record<string, string> = {
    falla_producto: 'Falla de Producto',
    disconformidad_servicio: 'Disconformidad de Servicio',
}

const estadoVariant: Record<string, 'amber' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'slate' | 'red'> = {
    pendiente_clasificacion: 'amber',
    clasificada: 'blue',
    en_proceso: 'indigo',
    derivada: 'purple',
    resuelta: 'emerald',
    cerrada: 'slate',
    cancelada: 'red',
}

const estadoLabel = (estado: string) =>
    props.porEstado.find(e => e.estado === estado)?.label ?? estado

const formatFecha = (d: string) =>
    new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })

const barWidth = (count: number) =>
    props.stats.total > 0 ? Math.round((count / props.stats.total) * 100) : 0
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-bold text-slate-800 dark:text-slate-100">Panel de control</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Resumen del sistema</p>
            </div>
            <Button variant="primary" disabled title="Próximamente">+ Nueva</Button>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-4">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 border-l-4 border-l-blue-500 px-4 py-3.5">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Observaciones</p>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1">{{ stats.total }}</p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 border-l-4 border-l-orange-500 px-4 py-3.5">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Abiertas</p>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1">{{ stats.abiertas }}</p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 border-l-4 border-l-emerald-500 px-4 py-3.5">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Resueltas</p>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1">{{ stats.resueltas }}</p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 border-l-4 border-l-indigo-500 px-4 py-3.5">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Asignadas a mí</p>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1">{{ stats.asignadasAMi }}</p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 border-l-4 border-l-purple-500 px-4 py-3.5">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">NC</p>
                <p class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-1">{{ stats.nc }}</p>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ stats.ncAbiertas }} abiertas</p>
            </div>
        </div>

        <!-- KPI row -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-slate-100 dark:divide-slate-700 mb-4">
            <div class="px-5 py-4 border-l-4 border-l-rose-500">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">KPI: Tiempo &lt;72h</p>
                <p class="text-2xl font-bold text-rose-600 dark:text-rose-400 mt-1">{{ kpis.tiempoSla ?? 0 }}%</p>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Meta &gt; 85%</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Tecnovigilancia</p>
                <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">{{ kpis.tecnovigilancia }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Crítica</p>
                <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">{{ kpis.critica }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Sin clasificar</p>
                <p class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ kpis.sinClasificar }}</p>
            </div>
        </div>

        <!-- Por sector / Por estado -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200 pb-3 border-b border-slate-100 dark:border-slate-700 mb-4">Por sector</h2>
                <div v-if="porSector.length === 0" class="flex items-center justify-center h-40 text-sm text-slate-400 dark:text-slate-500">
                    Sin datos
                </div>
                <div v-else class="space-y-2">
                    <div v-for="s in porSector" :key="s.sector" class="flex items-center justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-300">{{ s.sector }}</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-100">{{ s.count }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200 pb-3 border-b border-slate-100 dark:border-slate-700 mb-4">Por estado</h2>
                <div class="space-y-3">
                    <div v-for="e in porEstado" :key="e.estado" class="flex items-center gap-3 text-sm">
                        <span class="w-32 shrink-0 text-slate-600 dark:text-slate-300">{{ e.label }}</span>
                        <div class="flex-1 h-2 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-full bg-indigo-500 rounded-full" :style="{ width: barWidth(e.count) + '%' }" />
                        </div>
                        <span class="w-6 text-right font-semibold text-slate-800 dark:text-slate-100">{{ e.count }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Asignadas a mí -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 mb-4">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Asignadas a mí</h2>
                <a :href="route('observaciones.index')" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">
                    Ver todas →
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide bg-slate-50 dark:bg-slate-700/40">
                            <th class="px-5 py-2.5">N°</th>
                            <th class="px-5 py-2.5">Tipo</th>
                            <th class="px-5 py-2.5">Sector</th>
                            <th class="px-5 py-2.5">Título</th>
                            <th class="px-5 py-2.5">Estado</th>
                            <th class="px-5 py-2.5">Fecha</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-if="asignadas.length === 0">
                            <td colspan="6" class="px-5 py-8 text-center text-sm text-slate-400 dark:text-slate-500">
                                No tenés observaciones asignadas.
                            </td>
                        </tr>
                        <tr v-for="o in asignadas" :key="o.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-5 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">{{ o.numero }}</td>
                            <td class="px-5 py-2.5 text-slate-600 dark:text-slate-300">{{ tipoLabels[o.tipo] ?? o.tipo }}</td>
                            <td class="px-5 py-2.5 text-slate-400 dark:text-slate-500">—</td>
                            <td class="px-5 py-2.5 text-slate-800 dark:text-slate-100">{{ o.titulo }}</td>
                            <td class="px-5 py-2.5">
                                <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabel(o.estado) }}</Badge>
                            </td>
                            <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ formatFecha(o.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Últimas observaciones -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
            <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Últimas observaciones</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide bg-slate-50 dark:bg-slate-700/40">
                            <th class="px-5 py-2.5">N°</th>
                            <th class="px-5 py-2.5">Tipo</th>
                            <th class="px-5 py-2.5">Título</th>
                            <th class="px-5 py-2.5">Estado</th>
                            <th class="px-5 py-2.5">Fecha</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <tr v-if="ultimas.length === 0">
                            <td colspan="5" class="px-5 py-8 text-center text-sm text-slate-400 dark:text-slate-500">
                                Todavía no se cargaron observaciones.
                            </td>
                        </tr>
                        <tr v-for="o in ultimas" :key="o.id" class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
                            <td class="px-5 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">{{ o.numero }}</td>
                            <td class="px-5 py-2.5 text-slate-600 dark:text-slate-300">{{ tipoLabels[o.tipo] ?? o.tipo }}</td>
                            <td class="px-5 py-2.5 text-slate-800 dark:text-slate-100">{{ o.titulo }}</td>
                            <td class="px-5 py-2.5">
                                <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabel(o.estado) }}</Badge>
                            </td>
                            <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ formatFecha(o.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
