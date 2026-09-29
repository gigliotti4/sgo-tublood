<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import ContadorRegistros from '@/Components/ContadorRegistros.vue'
import DataRow from '@/Components/DataRow.vue'
import Input from '@/Components/Input.vue'
import Pagination from '@/Components/Pagination.vue'
import Select from '@/Components/Select.vue'
import TableCard from '@/Components/TableCard.vue'
import ThOrdenable from '@/Components/ThOrdenable.vue'
import { useOrdenamiento, type OrdenVigente } from '@/composables/useOrdenamiento'
import { usePermissions } from '@/composables/usePermissions'
import { estadoVariant, numeroDe } from '@/lib/nc'
import type { NoConformidad, PaginatedData, Sector } from '@/types'

const props = defineProps<{
    noConformidades: PaginatedData<NoConformidad>
    filters: { search: string; estado: string; sector_id: number | string }
    estados: Record<string, string>
    sectores: Pick<Sector, 'id' | 'nombre'>[]
    total: number
    orden: OrdenVigente
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
const filtros = reactive({
    estado: props.filters.estado ?? '',
    sector_id: props.filters.sector_id ?? '',
})

let debounce: ReturnType<typeof setTimeout>

const filtrosVigentes = () => ({ search: search.value, ...filtros })

const { orden, ordenarPor, paramsDeOrden } = useOrdenamiento({
    ruta: 'no-conformidades.index',
    orden: () => props.orden,
    parametros: filtrosVigentes,
    ascendentesPorDefecto: ['numero', 'estado', 'sector'],
})

const recargar = () => {
    router.get(route('no-conformidades.index'), { ...filtrosVigentes(), ...paramsDeOrden.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

watch(search, () => {
    clearTimeout(debounce)
    debounce = setTimeout(recargar, 350)
})

watch(filtros, recargar)

const hayFiltros = computed(() => !!(search.value || filtros.estado || filtros.sector_id))

const limpiar = () => {
    search.value = ''
    filtros.estado = ''
    filtros.sector_id = ''
}

const formatFecha = (d: string | null) =>
    d ? new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'
</script>

<template>
    <Head title="No Conformidades" />
    <AppLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">No Conformidades</h1>
                        <ContadorRegistros :total="total" :filtrados="noConformidades.total" />
                    </div>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Incumplimientos confirmados: investigación, acciones y verificación de eficacia.
                    </p>
                </div>

                <Link v-if="hasPermission('nc.create')" :href="route('no-conformidades.create')">
                    <Button variant="brand">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Nueva No Conformidad
                    </Button>
                </Link>
            </div>

            <!-- Filtros -->
            <div class="grid grid-cols-1 gap-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <Input
                        v-model="search"
                        type="text"
                        label="Buscar"
                        placeholder="Número, descripción, cliente o proveedor"
                    >
                        <template #icon>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </template>
                    </Input>
                </div>
                <Select v-model="filtros.estado" label="Estado">
                    <option value="">Todos</option>
                    <option v-for="(label, valor) in estados" :key="valor" :value="valor">{{ label }}</option>
                </Select>
                <Select v-model="filtros.sector_id" label="Sector">
                    <option value="">Todos</option>
                    <option v-for="s in sectores" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                </Select>
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <ThOrdenable campo="numero" :orden="orden" @ordenar="ordenarPor">N°</ThOrdenable>
                                <ThOrdenable campo="estado" :orden="orden" @ordenar="ordenarPor">Estado</ThOrdenable>
                                <ThOrdenable :orden="orden">Descripción</ThOrdenable>
                                <ThOrdenable campo="sector" :orden="orden" @ordenar="ordenarPor">Sector</ThOrdenable>
                                <ThOrdenable :orden="orden">Involucra</ThOrdenable>
                                <ThOrdenable campo="deteccion" :orden="orden" @ordenar="ordenarPor">Detección</ThOrdenable>
                                <ThOrdenable :orden="orden" align="right">Obs.</ThOrdenable>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="noConformidades.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400">
                                    <template v-if="hayFiltros">No se encontraron No Conformidades con esos filtros.</template>
                                    <template v-else>Todavía no hay No Conformidades cargadas.</template>
                                </td>
                            </tr>
                            <tr
                                v-for="nc in noConformidades.data"
                                :key="nc.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="whitespace-nowrap px-4 py-3.5 font-mono text-theme-sm">
                                    <Link
                                        :href="route('no-conformidades.show', nc.id)"
                                        class="text-brand-500 hover:underline dark:text-brand-300"
                                        :class="{ 'italic text-gray-400 dark:text-gray-500': !numeroDe(nc).asignado }"
                                    >
                                        {{ numeroDe(nc).texto }}
                                    </Link>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5">
                                    <Badge :variant="estadoVariant[nc.estado] ?? 'slate'">{{ estados[nc.estado] }}</Badge>
                                </td>
                                <td class="max-w-80 px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    <span class="line-clamp-2">{{ nc.descripcion }}</span>
                                </td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    {{ nc.sector?.nombre ?? '—' }}
                                </td>
                                <td class="max-w-52 px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    <span v-if="nc.cliente" class="block truncate">{{ nc.cliente.razon_social }}</span>
                                    <span v-else-if="nc.proveedor" class="block truncate">{{ nc.proveedor.razon_social }}</span>
                                    <span v-else class="italic text-gray-400">Interna</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ formatFecha(nc.fecha_deteccion) }}
                                </td>
                                <td class="px-4 py-3.5 text-right text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ nc.observaciones_count || '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos, para mobile -->
                <div v-if="noConformidades.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="nc in noConformidades.data" :key="nc.id">
                        <template #header>
                            <Link
                                :href="route('no-conformidades.show', nc.id)"
                                class="block truncate font-mono text-theme-sm font-medium text-brand-500 dark:text-brand-300"
                            >
                                {{ numeroDe(nc).texto }}
                            </Link>
                            <p class="line-clamp-2 text-theme-xs text-gray-400">{{ nc.descripcion }}</p>
                        </template>
                        <template #body>
                            <DataRow label="Estado">
                                <Badge :variant="estadoVariant[nc.estado] ?? 'slate'">{{ estados[nc.estado] }}</Badge>
                            </DataRow>
                            <DataRow label="Sector">{{ nc.sector?.nombre ?? '—' }}</DataRow>
                            <DataRow label="Detección">{{ formatFecha(nc.fecha_deteccion) }}</DataRow>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">Sin resultados.</p>

                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span class="flex items-center gap-3">
                        {{ noConformidades.total }} No Conformidad{{ noConformidades.total !== 1 ? 'es' : '' }}
                        <button
                            v-if="hayFiltros"
                            type="button"
                            class="cursor-pointer text-theme-xs text-brand-500 hover:underline dark:text-brand-300"
                            @click="limpiar"
                        >
                            Limpiar filtros
                        </button>
                    </span>
                    <Pagination :links="noConformidades.links" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
