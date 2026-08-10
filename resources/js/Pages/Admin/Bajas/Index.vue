<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Icon from '@/Components/Icon.vue'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Pagination from '@/Components/Pagination.vue'
import Select from '@/Components/Select.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import type { Observacion, PaginatedData } from '@/types'

interface Filtros {
    q?: string
    tipo?: string
    desde?: string
    hasta?: string
}

const props = defineProps<{
    observaciones: PaginatedData<Observacion>
    filters: Filtros
}>()

// ── Filtros ───────────────────────────────────────────────────────────────
// Mismo patrón que Admin/Bitacora/Index.vue: viven en la URL, un solo
// debounce para todos, y la guarda de fecha parcial evita disparar el
// request con un dd/mm a medio tipear.

const filtros = reactive({
    q: props.filters.q ?? '',
    tipo: props.filters.tipo ?? '',
    desde: props.filters.desde ?? '',
    hasta: props.filters.hasta ?? '',
})

const hayFiltros = computed(() => Object.values(filtros).some(v => v !== ''))

const fechaParcial = (v: string) => v !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(v)

const aplicarFiltros = () => {
    if (fechaParcial(filtros.desde) || fechaParcial(filtros.hasta)) return
    const params = Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== ''))
    router.get(route('bajas.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

let debounce: ReturnType<typeof setTimeout>
watch(filtros, () => {
    clearTimeout(debounce)
    debounce = setTimeout(aplicarFiltros, 300)
})

const limpiarFiltros = () => {
    Object.assign(filtros, { q: '', tipo: '', desde: '', hasta: '' })
}

// ── Presentación ─────────────────────────────────────────────────────────

const esBorrada = (o: Observacion) => !!o.deleted_at

const formatFecha = (d: string) =>
    new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })

const quienDioDeBaja = (o: Observacion) => {
    if (!o.baja) return '—'
    if (!o.baja.user) return 'Sistema'

    return [o.baja.user.name, o.baja.user.apellido].filter(Boolean).join(' ')
}

const restaurar = (o: Observacion) => {
    router.post(route('bajas.restore', o.id))
}
</script>

<template>
    <Head title="Bajas" />

    <AppLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Bajas</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                    Observaciones canceladas o borradas, con motivo y autor. Las borradas se pueden restaurar.
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="border-b border-gray-100 p-5 dark:border-gray-800">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="sm:col-span-2">
                            <Input v-model="filtros.q" label="Buscar" placeholder="N° o título…">
                                <template #icon>
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                </template>
                            </Input>
                        </div>

                        <div>
                            <Select v-model="filtros.tipo" label="Tipo de baja">
                                <option value="">Todas</option>
                                <option value="cancelada">Canceladas</option>
                                <option value="borrada">Borradas</option>
                            </Select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <InputFecha v-model="filtros.desde" label="Desde" />
                            <InputFecha v-model="filtros.hasta" label="Hasta" />
                        </div>

                        <div v-if="hayFiltros" class="flex items-end">
                            <button
                                class="cursor-pointer text-theme-sm font-medium text-gray-500 underline-offset-2 hover:text-gray-700 hover:underline dark:text-gray-400 dark:hover:text-gray-200"
                                @click="limpiarFiltros"
                            >
                                Limpiar filtros
                            </button>
                        </div>
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">N°</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Título</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Baja</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Motivo</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Quién</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                                <th class="px-5 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="observaciones.data.length === 0">
                                <td colspan="7" class="px-5 py-12 text-center text-sm text-gray-400">
                                    {{ hayFiltros ? 'Sin resultados para los filtros aplicados.' : 'No hay observaciones canceladas ni borradas.' }}
                                </td>
                            </tr>
                            <tr
                                v-for="o in observaciones.data"
                                :key="o.id"
                                class="align-top transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="px-5 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ o.numero }}</td>
                                <td class="px-5 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ o.titulo }}</td>
                                <td class="px-5 py-3.5">
                                    <Badge :variant="esBorrada(o) ? 'red' : 'amber'">{{ esBorrada(o) ? 'Borrada' : 'Cancelada' }}</Badge>
                                </td>
                                <td class="max-w-sm px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">
                                    <p class="whitespace-pre-line">{{ o.baja?.nota ?? '—' }}</p>
                                </td>
                                <td class="px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">{{ quienDioDeBaja(o) }}</td>
                                <td class="px-5 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ formatFecha(o.baja?.created_at ?? o.created_at) }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1">
                                        <Link
                                            :href="route('observaciones.show', o.id)"
                                            class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                            title="Ver detalle"
                                        >
                                            <Icon name="eye" class="h-4.5 w-4.5" />
                                            <span class="sr-only">Ver detalle de {{ o.numero }}</span>
                                        </Link>
                                        <button
                                            v-if="esBorrada(o)"
                                            type="button"
                                            class="rounded-lg px-3 py-1.5 text-theme-xs font-medium text-brand-500 transition-colors hover:bg-brand-50 dark:hover:bg-brand-500/10"
                                            @click="restaurar(o)"
                                        >
                                            Restaurar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="observaciones.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="o in observaciones.data" :key="o.id">
                        <template #header>
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge :variant="esBorrada(o) ? 'red' : 'amber'">{{ esBorrada(o) ? 'Borrada' : 'Cancelada' }}</Badge>
                                <span class="text-theme-xs text-gray-400">{{ formatFecha(o.baja?.created_at ?? o.created_at) }}</span>
                            </div>
                            <p class="mt-1 truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ o.titulo }}</p>
                            <p class="font-mono text-theme-xs text-gray-400">{{ o.numero }}</p>
                        </template>
                        <template #actions>
                            <Link
                                :href="route('observaciones.show', o.id)"
                                class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                title="Ver detalle"
                            >
                                <Icon name="eye" class="h-4.5 w-4.5" />
                                <span class="sr-only">Ver detalle de {{ o.numero }}</span>
                            </Link>
                        </template>
                        <template #body>
                            <DataRow label="Motivo">{{ o.baja?.nota ?? '—' }}</DataRow>
                            <DataRow label="Quién">{{ quienDioDeBaja(o) }}</DataRow>
                        </template>
                        <template v-if="esBorrada(o)" #footer>
                            <Button variant="outline" @click="restaurar(o)">Restaurar</Button>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    {{ hayFiltros ? 'Sin resultados para los filtros aplicados.' : 'No hay observaciones canceladas ni borradas.' }}
                </p>

                <div class="flex flex-col gap-3 border-t border-gray-100 px-5 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ observaciones.total }} observación{{ observaciones.total !== 1 ? 'es' : '' }} encontrada{{ observaciones.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="observaciones.links" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
