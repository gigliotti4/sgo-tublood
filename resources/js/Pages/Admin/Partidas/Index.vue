<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Input from '@/Components/Input.vue'
import Select from '@/Components/Select.vue'
import Button from '@/Components/Button.vue'
import Badge from '@/Components/Badge.vue'
import Pagination from '@/Components/Pagination.vue'
import ContadorRegistros from '@/Components/ContadorRegistros.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import type { PaginatedData, Partida } from '@/types'

const props = defineProps<{
    partidas: PaginatedData<Partida>
    filters: { search: string; proveedor: string; vencimiento: string }
    proveedores: { numero: string | null; razon_social: string }[]
    lastSync: string | null
    total: number
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
const filtros = reactive({
    proveedor: props.filters.proveedor ?? '',
    vencimiento: props.filters.vencimiento ?? '',
})

let debounce: ReturnType<typeof setTimeout>

const recargar = () => {
    router.get(route('partidas.index'), { search: search.value, ...filtros }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

// El buscador espera a que dejes de tipear; los selects recargan al toque.
watch(search, () => {
    clearTimeout(debounce)
    debounce = setTimeout(recargar, 350)
})

watch(filtros, recargar)

const hayFiltros = computed(() => !!(search.value || filtros.proveedor || filtros.vencimiento))

const limpiar = () => {
    search.value = ''
    filtros.proveedor = ''
    filtros.vencimiento = ''
}

const syncing = ref(false)

const triggerSync = () => {
    syncing.value = true
    router.post(route('partidas.sync'), {}, {
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

/**
 * Estado del vencimiento, para pintar la fecha.
 *
 * ⚠️ "Sin fecha" no es "no vence": es un dato que el kardex del ERP no trae
 * (1.141 de 12.780 partidas). Por eso va en gris y no en verde.
 */
const estadoVencimiento = (p: Partida): { label: string; variant: 'red' | 'amber' | 'emerald' | 'slate' } => {
    if (!p.fecha_vencimiento) return { label: 'Sin fecha', variant: 'slate' }

    const vence = new Date(p.fecha_vencimiento)
    const hoy = new Date()
    const dias = Math.ceil((vence.getTime() - hoy.getTime()) / 86400000)

    if (dias < 0) return { label: 'Vencida', variant: 'red' }
    if (dias <= 90) return { label: `${dias} d`, variant: 'amber' }
    return { label: 'Vigente', variant: 'emerald' }
}
</script>

<template>
    <Head title="Partidas" />
    <AppLayout>
        <div class="space-y-6">

            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Partidas</h1>
                        <ContadorRegistros :total="total" :filtrados="partidas.total" />
                    </div>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Última sincronización: {{ formatDate(lastSync) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button v-if="hasPermission('partidas.sync')" variant="brand" :disabled="syncing" @click="triggerSync">
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
            <div class="grid grid-cols-1 gap-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <Input
                        v-model="search"
                        type="text"
                        label="Buscar"
                        placeholder="Lote, código de artículo, descripción o proveedor"
                        hint="El lote no distingue mayúsculas: buscar atdec24 encuentra ATDEC24090046."
                    >
                        <template #icon>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </template>
                    </Input>
                </div>
                <Select v-model="filtros.proveedor" label="Proveedor">
                    <option value="">Todos</option>
                    <option v-for="p in proveedores" :key="p.numero ?? ''" :value="p.numero ?? ''">{{ p.razon_social }}</option>
                </Select>
                <Select v-model="filtros.vencimiento" label="Vencimiento">
                    <option value="">Todos</option>
                    <option value="vencidas">Vencidas</option>
                    <option value="por_vencer">Vencen en 90 días</option>
                    <option value="vigentes">Vigentes</option>
                    <option value="sin_vencimiento">Sin fecha cargada</option>
                </Select>
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Lote</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Artículo</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Proveedor</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Vencimiento</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ubicación</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Últ. movimiento</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="partidas.data.length === 0">
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-gray-400">
                                    <template v-if="hayFiltros">
                                        No se encontraron partidas con esos filtros.
                                    </template>
                                    <template v-else>
                                        No hay partidas. Usá el botón <strong>Sincronizar</strong> para traerlas desde RP Sistemas.
                                    </template>
                                </td>
                            </tr>
                            <tr
                                v-for="partida in partidas.data"
                                :key="partida.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="px-4 py-3.5 font-mono text-theme-sm">
                                    <Link
                                        :href="route('partidas.show', partida.id)"
                                        class="text-brand-500 hover:underline dark:text-brand-300"
                                    >
                                        {{ partida.codigo_partida }}
                                    </Link>
                                </td>
                                <td class="max-w-72 px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    <span class="block truncate">
                                        {{ partida.articulo?.descripcion ?? '—' }}
                                    </span>
                                    <span class="block font-mono text-theme-xs text-gray-400">
                                        {{ partida.codigo_articulo }}
                                        <!-- El ERP nombra códigos que el feed de artículos no trae. -->
                                        <span v-if="!partida.articulo" class="font-sans italic">· fuera del catálogo</span>
                                    </span>
                                </td>
                                <td class="max-w-52 px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    <span v-if="partida.proveedor" class="block truncate">{{ partida.proveedor.razon_social }}</span>
                                    <span v-else class="italic text-gray-400">Sin proveedor en el kardex</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    <span class="mr-1.5">{{ formatFecha(partida.fecha_vencimiento) }}</span>
                                    <Badge :variant="estadoVencimiento(partida).variant">
                                        {{ estadoVencimiento(partida).label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ partida.ubicacion ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ formatFecha(partida.ultimo_movimiento_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="partidas.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="partida in partidas.data" :key="partida.id">
                        <template #header>
                            <Link
                                :href="route('partidas.show', partida.id)"
                                class="block truncate font-mono text-theme-sm font-medium text-brand-500 dark:text-brand-300"
                            >
                                {{ partida.codigo_partida }}
                            </Link>
                            <p class="truncate text-theme-xs text-gray-400">{{ partida.articulo?.descripcion ?? partida.codigo_articulo }}</p>
                        </template>
                        <template #body>
                            <DataRow label="Código">{{ partida.codigo_articulo }}</DataRow>
                            <DataRow label="Proveedor">{{ partida.proveedor?.razon_social ?? '—' }}</DataRow>
                            <DataRow label="Vencimiento">{{ formatFecha(partida.fecha_vencimiento) }}</DataRow>
                            <DataRow label="Ubicación">{{ partida.ubicacion ?? '—' }}</DataRow>
                            <DataRow label="Últ. movimiento">{{ formatFecha(partida.ultimo_movimiento_at) }}</DataRow>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    No se encontraron partidas.
                </p>

                <!-- Footer: total + paginación -->
                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span class="flex items-center gap-3">
                        {{ partidas.total }} partida{{ partidas.total !== 1 ? 's' : '' }}
                        <button
                            v-if="hayFiltros"
                            type="button"
                            class="cursor-pointer text-theme-xs text-brand-500 hover:underline dark:text-brand-300"
                            @click="limpiar"
                        >
                            Limpiar filtros
                        </button>
                    </span>
                    <Pagination :links="partidas.links" />
                </div>
            </div>

        </div>
    </AppLayout>
</template>
