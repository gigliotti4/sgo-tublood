<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Select from '@/Components/Select.vue'
import Button from '@/Components/Button.vue'
import Pagination from '@/Components/Pagination.vue'
import ContadorRegistros from '@/Components/ContadorRegistros.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import type { PaginatedData, Venta } from '@/types'

const props = defineProps<{
    ventas: PaginatedData<Venta>
    filters: { search: string; desde: string; hasta: string; vendedor: string; lote: string }
    vendedores: string[]
    /** El backend ya sacó los importes de las props si es false: acá solo se ocultan las columnas. */
    puedeVerMontos: boolean
    lastSync: string | null
    total: number
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
const filtros = reactive({
    desde: props.filters.desde ?? '',
    hasta: props.filters.hasta ?? '',
    vendedor: props.filters.vendedor ?? '',
    lote: props.filters.lote ?? '',
})

let debounce: ReturnType<typeof setTimeout>
let debounceLote: ReturnType<typeof setTimeout>

const recargar = () => {
    router.get(route('ventas.index'), { search: search.value, ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

// El buscador espera a que dejes de tipear; los selects y fechas recargan al
// toque, porque son un solo click.
watch(search, () => {
    clearTimeout(debounce)
    debounce = setTimeout(recargar, 350)
})

// El lote es texto libre, igual que el buscador: espera a que dejes de tipear.
// Los demás filtros son un solo click y recargan al toque.
watch(() => filtros.lote, () => {
    clearTimeout(debounceLote)
    debounceLote = setTimeout(recargar, 350)
})

watch([() => filtros.desde, () => filtros.hasta, () => filtros.vendedor], recargar)

const limpiar = () => {
    search.value = ''
    filtros.desde = ''
    filtros.hasta = ''
    filtros.vendedor = ''
    filtros.lote = ''
}

const syncing = ref(false)

const triggerSync = () => {
    syncing.value = true
    router.post(route('ventas.sync'), {}, {
        onFinish: () => { syncing.value = false },
    })
}

const formatDate = (d: string | null) => {
    if (!d) return 'Nunca'
    return new Date(d).toLocaleString('es-AR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

const formatFecha = (d: string | null) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

// Acepta `undefined` porque el importe no viaja cuando el usuario no tiene
// `ventas.montos`: la columna no se dibuja, pero el tipo lo refleja igual.
const formatMoneda = (v: string | null | undefined) => {
    if (v === null || v === undefined) return '—'
    return Number(v).toLocaleString('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 2 })
}

const formatCantidad = (v: string | null) => {
    if (v === null) return '—'
    return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 2 })
}

/** El ERP usa 0 para "sin remito", no null. */
const remito = (v: number | null) => (v ? String(v) : '—')

/**
 * El remito **con su serie** (`VR8-8500`).
 *
 * ⚠️ El número solo no identifica un remito: 7.395 de 25.320 números (29%)
 * están repetidos entre series. La serie sale del movimiento del kardex, así
 * que solo la tenemos cuando el renglón resolvió a un lote; si no, se cae al
 * número pelado que ya traía `ventas`.
 */
const remitoDe = (venta: Venta) => {
    const conSerie = venta.lotes?.find(l => l.remito)?.remito
    return conSerie ?? remito(venta.remito_nro)
}
</script>

<template>
    <Head title="Ventas" />
    <AppLayout>
        <div class="space-y-6">

            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ventas</h1>
                        <ContadorRegistros :total="total" :filtrados="ventas.total" />
                    </div>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Última sincronización: {{ formatDate(lastSync) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button v-if="hasPermission('ventas.sync')" variant="brand" :disabled="syncing" @click="triggerSync">
                        <svg
                            class="w-4 h-4"
                            :class="{ 'animate-spin': syncing }"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"
                            />
                        </svg>
                        {{ syncing ? 'Sincronizando...' : 'Sincronizar' }}
                    </Button>
                </div>
            </div>

            <!-- Filtros -->
            <div class="grid grid-cols-1 gap-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] sm:grid-cols-2 lg:grid-cols-6">
                <div class="lg:col-span-2">
                    <Input
                        v-model="search"
                        type="text"
                        label="Buscar"
                        placeholder="Comprobante, remito, cliente, artículo... o BOSO-AGUJA"
                        hint="Combiná dos términos para cruzarlos: BOSO-AGUJA trae las ventas de ese cliente con ese artículo."
                    >
                        <template #icon>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </template>
                    </Input>
                </div>
                <InputFecha v-model="filtros.desde" label="Desde" />
                <InputFecha v-model="filtros.hasta" label="Hasta" />
                <Select v-model="filtros.vendedor" label="Vendedor">
                    <option value="">Todos</option>
                    <option v-for="v in vendedores" :key="v" :value="v">{{ v }}</option>
                </Select>
                <Input
                    v-model="filtros.lote"
                    type="text"
                    label="Lote"
                    placeholder="Ej. 160224"
                    hint="Qué remitos y clientes recibieron esa partida."
                />
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Comprobante</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Remito</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cliente</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Artículo</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Lote</th>
                                <th class="px-4 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cant.</th>
                                <th v-if="puedeVerMontos" class="px-4 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Subtotal</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Vendedor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="ventas.data.length === 0">
                                <td :colspan="puedeVerMontos ? 9 : 8" class="px-4 py-12 text-center text-sm text-gray-400">
                                    <template v-if="search || filtros.desde || filtros.hasta || filtros.vendedor || filtros.lote">
                                        No se encontraron ventas con esos filtros.
                                        <span class="mt-1 block text-theme-xs">
                                            Para cruzar cliente y artículo, combinalos con un guion:
                                            <span class="font-mono">BOSO-AGUJA</span>.
                                        </span>
                                    </template>
                                    <template v-else>
                                        No hay ventas. Usá el botón <strong>Sincronizar</strong> para traerlas desde RP Sistemas.
                                    </template>
                                </td>
                            </tr>
                            <tr
                                v-for="venta in ventas.data"
                                :key="venta.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="whitespace-nowrap px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ formatFecha(venta.fecha) }}</td>
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-600 dark:text-gray-300">
                                    {{ venta.compro_nro ?? '—' }}
                                    <span v-if="venta.grupo_compro_descrip" class="block font-sans text-theme-xs text-gray-400">
                                        {{ venta.grupo_compro_descrip }}
                                    </span>
                                </td>
                                <!-- Con la serie: el número pelado es ambiguo, el 29% se repite entre series. -->
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ remitoDe(venta) }}</td>
                                <td class="max-w-52 px-4 py-3.5 text-theme-sm text-gray-800 dark:text-white/90">
                                    <span class="block truncate">{{ venta.razon_social ?? '—' }}</span>
                                    <span class="block font-mono text-theme-xs font-normal text-gray-400">N° {{ venta.cliente ?? '—' }}</span>
                                </td>
                                <td class="max-w-60 px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    <span class="block truncate">{{ venta.descrip_arti ?? '—' }}</span>
                                    <span class="block font-mono text-theme-xs text-gray-400">{{ venta.articulo ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3.5 text-theme-xs">
                                    <!--
                                        El 11% de los renglones no tiene lote: son artículos sin
                                        trazabilidad (servicios, ajustes de cambio). Es un resultado
                                        normal, no un dato faltante, por eso va como guion y no como
                                        advertencia.
                                    -->
                                    <span v-if="!venta.lotes?.length" class="text-gray-400">—</span>
                                    <template v-else>
                                        <span
                                            v-for="l in venta.lotes"
                                            :key="l.id"
                                            class="block font-mono text-gray-600 dark:text-gray-300"
                                        >
                                            {{ l.codigo_partida }}
                                            <!-- Solo cuando salió partido: si es uno solo, la cantidad ya está en su columna. -->
                                            <span v-if="(venta.lotes?.length ?? 0) > 1" class="font-sans text-gray-400">
                                                ×{{ formatCantidad(l.cantidad) }}
                                            </span>
                                        </span>
                                    </template>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-right text-theme-xs text-gray-600 dark:text-gray-300">{{ formatCantidad(venta.cantidad) }}</td>
                                <td v-if="puedeVerMontos" class="whitespace-nowrap px-4 py-3.5 text-right text-theme-xs text-gray-800 dark:text-white/90">{{ formatMoneda(venta.sub_total) }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ venta.vendedor ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="ventas.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="venta in ventas.data" :key="venta.id">
                        <template #header>
                            <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ venta.razon_social ?? '—' }}</p>
                            <p class="font-mono text-theme-xs text-gray-400">{{ venta.compro_nro ?? '—' }} · {{ formatFecha(venta.fecha) }}</p>
                        </template>
                        <template #body>
                            <DataRow label="Artículo">{{ venta.descrip_arti ?? '—' }}</DataRow>
                            <DataRow label="Código">{{ venta.articulo ?? '—' }}</DataRow>
                            <DataRow label="Remito">{{ remitoDe(venta) }}</DataRow>
                            <DataRow label="Lote">
                                <span v-if="!venta.lotes?.length">—</span>
                                <span v-for="l in venta.lotes" :key="l.id" class="block font-mono">
                                    {{ l.codigo_partida }}
                                    <span v-if="(venta.lotes?.length ?? 0) > 1">×{{ formatCantidad(l.cantidad) }}</span>
                                </span>
                            </DataRow>
                            <DataRow label="Cantidad">{{ formatCantidad(venta.cantidad) }}</DataRow>
                            <DataRow v-if="puedeVerMontos" label="Subtotal">{{ formatMoneda(venta.sub_total) }}</DataRow>
                            <DataRow label="Vendedor">{{ venta.vendedor ?? '—' }}</DataRow>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    No se encontraron ventas.
                    <span class="mt-1 block text-theme-xs">
                        Para cruzar cliente y artículo, combinalos con un guion:
                        <span class="font-mono">BOSO-AGUJA</span>.
                    </span>
                </p>

                <!-- Footer: total + paginación -->
                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span class="flex items-center gap-3">
                        {{ ventas.total }} renglón{{ ventas.total !== 1 ? 'es' : '' }}
                        <button
                            v-if="search || filtros.desde || filtros.hasta || filtros.vendedor || filtros.lote"
                            type="button"
                            class="cursor-pointer text-theme-xs text-brand-500 hover:underline dark:text-brand-300"
                            @click="limpiar"
                        >
                            Limpiar filtros
                        </button>
                    </span>
                    <Pagination :links="ventas.links" />
                </div>
            </div>

        </div>
    </AppLayout>
</template>
