<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Badge.vue'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Pagination from '@/Components/Pagination.vue'
import Select from '@/Components/Select.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import { accionLabels, accionVariant, comoCambioSimple, esClasificacion, esNotificados, formatFechaHora, formatSize, nombreAutor } from '@/lib/bitacora'
import type { ObservationHistoryEntry, PaginatedData } from '@/types'

interface UsuarioOption { id: number; name: string; apellido: string | null }
interface SectorOption { id: number; nombre: string }

interface Filtros {
    q?: string
    accion?: string
    user_id?: string
    sector_id?: string | number
    desde?: string
    hasta?: string
}

const props = defineProps<{
    entradas: PaginatedData<ObservationHistoryEntry>
    filters: Filtros
    usuarios: UsuarioOption[]
    sectores: SectorOption[]
}>()

const nombreCompleto = (u: UsuarioOption) => [u.name, u.apellido].filter(Boolean).join(' ')

// ── Filtros ───────────────────────────────────────────────────────────────
// Mismo patrón que Admin/Observaciones/Index.vue: viven en la URL, un solo
// debounce para todos, y la guarda de fecha parcial evita disparar el
// request con un dd/mm a medio tipear.

const filtros = reactive({
    q: props.filters.q ?? '',
    accion: props.filters.accion ?? '',
    user_id: props.filters.user_id ?? '',
    sector_id: String(props.filters.sector_id ?? ''),
    desde: props.filters.desde ?? '',
    hasta: props.filters.hasta ?? '',
})

const hayFiltros = computed(() => Object.values(filtros).some(v => v !== ''))

const fechaParcial = (v: string) => v !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(v)

const aplicarFiltros = () => {
    if (fechaParcial(filtros.desde) || fechaParcial(filtros.hasta)) return
    const params = Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== ''))
    router.get(route('bitacora.index'), params, {
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
    Object.assign(filtros, { q: '', accion: '', user_id: '', sector_id: '', desde: '', hasta: '' })
}
</script>

<template>
    <Head title="Bitácora" />

    <AppLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Bitácora</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                    Historial completo de todas las observaciones: quién hizo qué, y cuándo.
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <!-- Buscador -->
                <div class="border-b border-gray-100 p-5 dark:border-gray-800">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="sm:col-span-2">
                            <Input v-model="filtros.q" label="Buscar" placeholder="N° o título de la observación, texto del comentario…">
                                <template #icon>
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                </template>
                            </Input>
                        </div>

                        <Select v-model="filtros.accion" label="Acción">
                            <option value="">Todas</option>
                            <option v-for="(label, key) in accionLabels" :key="key" :value="key">{{ label }}</option>
                        </Select>

                        <Select v-model="filtros.user_id" label="Usuario">
                            <option value="">Todos</option>
                            <option value="sistema">Sistema</option>
                            <option v-for="u in usuarios" :key="u.id" :value="String(u.id)">{{ nombreCompleto(u) }}</option>
                        </Select>

                        <Select v-model="filtros.sector_id" label="Sector">
                            <option value="">Todos</option>
                            <option v-for="s in sectores" :key="s.id" :value="String(s.id)">{{ s.nombre }}</option>
                        </Select>

                        <InputFecha v-model="filtros.desde" label="Desde" />
                        <InputFecha v-model="filtros.hasta" label="Hasta" />

                        <div v-if="hayFiltros" class="flex items-end sm:col-span-2">
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
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acción</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Usuario</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Observación</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Detalle</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="entradas.data.length === 0">
                                <td colspan="5" class="px-5 py-12 text-center text-sm text-gray-400">
                                    {{ hayFiltros ? 'Sin resultados para los filtros aplicados.' : 'Todavía no hay actividad registrada.' }}
                                </td>
                            </tr>
                            <tr
                                v-for="entrada in entradas.data"
                                :key="entrada.id"
                                class="align-top transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="whitespace-nowrap px-5 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ formatFechaHora(entrada.created_at) }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <Badge :variant="accionVariant[entrada.accion] ?? 'slate'">
                                        {{ accionLabels[entrada.accion] ?? entrada.accion }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">
                                    {{ nombreAutor(entrada) }}
                                </td>
                                <td class="px-5 py-3.5 text-theme-sm">
                                    <Link
                                        v-if="entrada.observacion"
                                        :href="route('observaciones.show', entrada.observacion.id)"
                                        class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                                    >
                                        {{ entrada.observacion.numero }}
                                    </Link>
                                    <p v-if="entrada.observacion" class="mt-0.5 truncate text-theme-xs text-gray-400">
                                        {{ entrada.observacion.titulo }}
                                        <template v-if="entrada.observacion.sector"> · {{ entrada.observacion.sector.nombre }}</template>
                                    </p>
                                </td>
                                <td class="max-w-md px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">
                                    <p v-if="comoCambioSimple(entrada.cambios)">
                                        De <span class="font-medium text-gray-800 dark:text-white/90">{{ comoCambioSimple(entrada.cambios)!.de }}</span>
                                        a <span class="font-medium text-gray-800 dark:text-white/90">{{ comoCambioSimple(entrada.cambios)!.a }}</span>
                                    </p>
                                    <div v-else-if="esClasificacion(entrada)" class="space-y-0.5">
                                        <p>Prioridad: {{ entrada.cambios.prioridad.de }} → {{ entrada.cambios.prioridad.a }}</p>
                                        <p>Tipo de caso: {{ entrada.cambios.tipo_caso.de }} → {{ entrada.cambios.tipo_caso.a }}</p>
                                    </div>
                                    <div v-else-if="esNotificados(entrada)" class="space-y-0.5">
                                        <p v-if="entrada.cambios.sumados.length">Se sumó a {{ entrada.cambios.sumados.join(', ') }}</p>
                                        <p v-if="entrada.cambios.sacados.length">Se sacó a {{ entrada.cambios.sacados.join(', ') }}</p>
                                    </div>
                                    <p v-if="entrada.nota" class="whitespace-pre-line">{{ entrada.nota }}</p>
                                    <ul v-if="entrada.adjuntos.length" class="mt-1 space-y-0.5">
                                        <li v-for="a in entrada.adjuntos" :key="a.id" class="text-theme-xs text-gray-400">
                                            📎 {{ a.original_name }} ({{ formatSize(a.size) }})
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="entradas.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="entrada in entradas.data" :key="entrada.id">
                        <template #header>
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge :variant="accionVariant[entrada.accion] ?? 'slate'">{{ accionLabels[entrada.accion] ?? entrada.accion }}</Badge>
                                <span class="text-theme-xs text-gray-400">{{ formatFechaHora(entrada.created_at) }}</span>
                            </div>
                        </template>
                        <template #body>
                            <DataRow label="Usuario">{{ nombreAutor(entrada) }}</DataRow>
                            <DataRow label="Observación">
                                <Link
                                    v-if="entrada.observacion"
                                    :href="route('observaciones.show', entrada.observacion.id)"
                                    class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                                >
                                    {{ entrada.observacion.numero }}
                                </Link>
                                <span v-else>—</span>
                            </DataRow>
                        </template>
                        <template #footer>
                            <div class="w-full text-theme-sm text-gray-600 dark:text-gray-300">
                                <p v-if="comoCambioSimple(entrada.cambios)">
                                    De <span class="font-medium text-gray-800 dark:text-white/90">{{ comoCambioSimple(entrada.cambios)!.de }}</span>
                                    a <span class="font-medium text-gray-800 dark:text-white/90">{{ comoCambioSimple(entrada.cambios)!.a }}</span>
                                </p>
                                <div v-else-if="esClasificacion(entrada)" class="space-y-0.5">
                                    <p>Prioridad: {{ entrada.cambios.prioridad.de }} → {{ entrada.cambios.prioridad.a }}</p>
                                    <p>Tipo de caso: {{ entrada.cambios.tipo_caso.de }} → {{ entrada.cambios.tipo_caso.a }}</p>
                                </div>
                                <div v-else-if="esNotificados(entrada)" class="space-y-0.5">
                                    <p v-if="entrada.cambios.sumados.length">Se sumó a {{ entrada.cambios.sumados.join(', ') }}</p>
                                    <p v-if="entrada.cambios.sacados.length">Se sacó a {{ entrada.cambios.sacados.join(', ') }}</p>
                                </div>
                                <p v-if="entrada.nota" class="whitespace-pre-line">{{ entrada.nota }}</p>
                                <ul v-if="entrada.adjuntos.length" class="mt-1 space-y-0.5">
                                    <li v-for="a in entrada.adjuntos" :key="a.id" class="text-theme-xs text-gray-400">
                                        📎 {{ a.original_name }} ({{ formatSize(a.size) }})
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    {{ hayFiltros ? 'Sin resultados para los filtros aplicados.' : 'Todavía no hay actividad registrada.' }}
                </p>

                <div v-if="entradas.data.length > 0" class="flex items-center justify-between border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ entradas.total }} entradas encontradas</p>
                    <Pagination :links="entradas.links" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
