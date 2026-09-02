<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Badge.vue'
import Pagination from '@/Components/Pagination.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import type { DespachoPartida, PaginatedData, Partida } from '@/types'

const props = defineProps<{
    partida: Partida
    despachos: PaginatedData<DespachoPartida>
    totalDespachos: number
    unidadesDespachadas: string | number | null
    /** Desde cuándo hay ventas locales; explica los despachos sin cliente. */
    ventasDesde: string | null
}>()

const formatFecha = (d: string | null) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

const formatCantidad = (v: string | number | null) => {
    if (v === null) return '—'
    return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 2 })
}

/**
 * El remito con su serie: el número pelado es ambiguo (29% se repite entre
 * series).
 *
 * ⚠️ Solo las series `VR*` son remitos. En el 39% de los movimientos el
 * documento precedente es de stock (`STO`/`STI`) y mostrarlo acá sería rotular
 * como remito algo que no lo es.
 */
const SERIES_DE_REMITO = ['VR8', 'VRM', 'VR6']

const remitoDe = (d: DespachoPartida) => {
    if (!d.remito_numero || !d.remito_tipo || !SERIES_DE_REMITO.includes(d.remito_tipo)) return '—'
    return `${d.remito_tipo}-${d.remito_numero}`
}

const estadoVencimiento = (): { label: string; variant: 'red' | 'amber' | 'emerald' | 'slate' } => {
    if (!props.partida.fecha_vencimiento) return { label: 'Sin fecha', variant: 'slate' }

    const dias = Math.ceil((new Date(props.partida.fecha_vencimiento).getTime() - Date.now()) / 86400000)

    if (dias < 0) return { label: 'Vencida', variant: 'red' }
    if (dias <= 90) return { label: `Vence en ${dias} d`, variant: 'amber' }
    return { label: 'Vigente', variant: 'emerald' }
}

const mesDesde = () =>
    props.ventasDesde
        ? new Date(props.ventasDesde).toLocaleDateString('es-AR', { month: '2-digit', year: 'numeric' })
        : ''
</script>

<template>
    <Head :title="`Partida ${partida.codigo_partida}`" />
    <AppLayout>
        <div class="space-y-6">

            <!-- Header -->
            <div>
                <Link
                    :href="route('partidas.index')"
                    class="text-theme-xs text-brand-500 hover:underline dark:text-brand-300"
                >
                    ← Volver a Partidas
                </Link>
                <div class="mt-1 flex flex-wrap items-center gap-2.5">
                    <h1 class="font-mono text-xl font-semibold text-gray-800 dark:text-white/90">
                        {{ partida.codigo_partida }}
                    </h1>
                    <Badge :variant="estadoVencimiento().variant">{{ estadoVencimiento().label }}</Badge>
                </div>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                    {{ partida.articulo?.descripcion ?? 'Artículo fuera del catálogo' }}
                    <span class="font-mono text-gray-400">· {{ partida.codigo_articulo }}</span>
                </p>
            </div>

            <!-- Ficha -->
            <div class="grid grid-cols-1 gap-4 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Proveedor</p>
                    <p class="mt-1 text-theme-sm text-gray-800 dark:text-white/90">
                        <span v-if="partida.proveedor">{{ partida.proveedor.razon_social }}</span>
                        <span v-else class="italic text-gray-400">Sin proveedor en el kardex</span>
                    </p>
                </div>
                <div>
                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Vencimiento</p>
                    <p class="mt-1 text-theme-sm text-gray-800 dark:text-white/90">{{ formatFecha(partida.fecha_vencimiento) }}</p>
                </div>
                <div>
                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Ubicación</p>
                    <p class="mt-1 font-mono text-theme-sm text-gray-800 dark:text-white/90">{{ partida.ubicacion ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Último movimiento</p>
                    <p class="mt-1 text-theme-sm text-gray-800 dark:text-white/90">{{ formatFecha(partida.ultimo_movimiento_at) }}</p>
                </div>
            </div>

            <!-- Despachos -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 px-4 py-3.5 dark:border-gray-800">
                    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                        Despachos ({{ totalDespachos }})
                    </p>
                    <p class="mt-0.5 text-theme-xs text-gray-400">
                        A quién se le mandó esta partida. Total despachado:
                        {{ formatCantidad(unidadesDespachadas) }} unidades.
                    </p>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Comprobante</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Remito</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cliente</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Provincia</th>
                                <th class="px-4 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cantidad</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="despachos.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-gray-400">
                                    Esta partida no registra despachos.
                                </td>
                            </tr>
                            <tr
                                v-for="d in despachos.data"
                                :key="d.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="whitespace-nowrap px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ formatFecha(d.fecha) }}</td>
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-600 dark:text-gray-300">{{ d.compro_nro }}</td>
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ remitoDe(d) }}</td>
                                <td class="max-w-60 px-4 py-3.5 text-theme-sm text-gray-800 dark:text-white/90">
                                    <template v-if="d.razon_social">
                                        <span class="block truncate">{{ d.razon_social }}</span>
                                        <span class="block font-mono text-theme-xs font-normal text-gray-400">N° {{ d.cliente }}</span>
                                    </template>
                                    <!--
                                        `ventas` solo cubre desde 2024-08 y el kardex llega a 2016.
                                        Se dice por qué falta en vez de poner un guion, que se leería
                                        como un dato que deberíamos tener.
                                    -->
                                    <span v-else class="text-theme-xs italic text-gray-400">
                                        Venta anterior a {{ mesDesde() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ d.provincia ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-right text-theme-xs text-gray-600 dark:text-gray-300">{{ formatCantidad(d.cantidad) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="despachos.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="d in despachos.data" :key="d.id">
                        <template #header>
                            <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ d.razon_social ?? `Venta anterior a ${mesDesde()}` }}
                            </p>
                            <p class="font-mono text-theme-xs text-gray-400">{{ d.compro_nro }} · {{ formatFecha(d.fecha) }}</p>
                        </template>
                        <template #body>
                            <DataRow label="Remito">{{ remitoDe(d) }}</DataRow>
                            <DataRow label="Provincia">{{ d.provincia ?? '—' }}</DataRow>
                            <DataRow label="Cantidad">{{ formatCantidad(d.cantidad) }}</DataRow>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    Esta partida no registra despachos.
                </p>

                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ despachos.total }} despacho{{ despachos.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="despachos.links" />
                </div>
            </div>

        </div>
    </AppLayout>
</template>
