<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Input from '@/Components/Input.vue'
import Select from '@/Components/Select.vue'
import Badge from '@/Components/Badge.vue'
import Icon from '@/Components/Icon.vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import Pagination from '@/Components/Pagination.vue'
import ContadorRegistros from '@/Components/ContadorRegistros.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import type { Articulo, PaginatedData } from '@/types'

const props = defineProps<{
    articulos: PaginatedData<Articulo>
    filters: { search: string; estado: string; proveedor: string }
    lastSync: string | null
    total: number
    totalInactivos: number
    /** Cuántos artículos siguen sin proveedor, para seguir el avance de la carga en RP. */
    totalSinProveedor: number
    /** Valores de `codigo_proveedor` que el ERP tiene cargados pero no sirven. */
    codigosInvalidos: { codigo_proveedor: string; articulos: number; motivo: string }[]
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
const estado = ref(props.filters.estado ?? '')
const proveedor = ref(props.filters.proveedor ?? '')

const recargar = () => {
    router.get(route('articulos.index'), { search: search.value, estado: estado.value, proveedor: proveedor.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

let debounce: ReturnType<typeof setTimeout>

// Solo el buscador necesita debounce: el select cambia de a un valor.
watch(search, () => {
    clearTimeout(debounce)
    debounce = setTimeout(recargar, 350)
})

watch([estado, proveedor], recargar)

const urlExportar = computed(() => route('articulos.export', {
    search: search.value || undefined,
    estado: estado.value || undefined,
    proveedor: proveedor.value || undefined,
}))

// El reporte de códigos mal cargados se abre a pedido: es para mandarle a RP,
// no algo que haga falta ver todos los días.
const verCodigosInvalidos = ref(false)

const articulosAfectados = computed(() =>
    props.codigosInvalidos.reduce((total, c) => total + c.articulos, 0),
)

const syncing = ref(false)

const triggerSync = () => {
    syncing.value = true
    router.post(route('articulos.sync'), {}, {
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
    return new Date(d).toLocaleDateString('es-AR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
    })
}

const showImportModal = ref(false)
const importForm = useForm({
    archivo: null as File | null,
    crear_faltantes: false,
})

const onArchivoChange = (e: Event) => {
    importForm.archivo = (e.target as HTMLInputElement).files?.[0] ?? null
}

const submitImport = () => {
    importForm.post(route('articulos.import'), {
        forceFormData: true,
        onSuccess: () => {
            showImportModal.value = false
            importForm.reset()
        },
    })
}
</script>

<template>
    <Head title="Artículos" />
    <AppLayout>
        <div class="space-y-6">

            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Artículos</h1>
                        <ContadorRegistros :total="total" :filtrados="articulos.total" />
                    </div>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Última sincronización: {{ formatDate(lastSync) }}
                        <span v-if="totalInactivos > 0">· {{ totalInactivos }} discontinuado{{ totalInactivos !== 1 ? 's' : '' }}</span>
                        <span v-if="totalSinProveedor > 0">· {{ totalSinProveedor }} sin proveedor</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <!-- Descarga directa, no navegación de Inertia: por eso <a> y no <Link>. -->
                    <a
                        :href="urlExportar"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                    >
                        Exportar a Excel
                    </a>
                    <Button v-if="hasPermission('articulos.import')" variant="outline" @click="showImportModal = true">
                        Importar Excel
                    </Button>
                    <Button v-if="hasPermission('articulos.sync')" variant="brand" :disabled="syncing" @click="triggerSync">
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

            <!-- Buscador y filtro -->
            <div class="flex flex-wrap items-end gap-3">
                <div class="max-w-sm flex-1">
                    <Input v-model="search" type="text" placeholder="Buscar por código, descripción, PM, legajo...">
                        <template #icon>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </template>
                    </Input>
                </div>
                <div class="w-48">
                    <Select v-model="estado">
                        <option value="">Todos los estados</option>
                        <option value="activos">Activos</option>
                        <option value="inactivos">Discontinuados</option>
                    </Select>
                </div>
                <div class="w-52">
                    <Select v-model="proveedor">
                        <option value="">Con y sin proveedor</option>
                        <option value="sin">Sin proveedor</option>
                        <option value="con">Con proveedor</option>
                    </Select>
                </div>
            </div>

            <!--
                Aviso de códigos mal cargados en RP. No es un error del sistema:
                es un dato sucio del ERP (costos tipeados en el campo de
                proveedor) que hay que pedirle a RP que limpie, y por eso el
                bloque existe — para poder mandarles la lista concreta.
            -->
            <div
                v-if="codigosInvalidos.length"
                class="flex flex-col gap-3 rounded-2xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/10 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-theme-sm text-warning-700 dark:text-warning-400">
                    <strong>{{ codigosInvalidos.length }}</strong>
                    valor{{ codigosInvalidos.length !== 1 ? 'es' : '' }} de código de proveedor
                    en RP no sirve{{ codigosInvalidos.length !== 1 ? 'n' : '' }} para vincular,
                    y afecta{{ articulosAfectados !== 1 ? 'n' : '' }} a {{ articulosAfectados }}
                    artículo{{ articulosAfectados !== 1 ? 's' : '' }}.
                </p>
                <button
                    type="button"
                    class="shrink-0 cursor-pointer text-theme-xs font-medium text-warning-700 underline dark:text-warning-400"
                    @click="verCodigosInvalidos = true"
                >
                    Ver la lista
                </button>
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Código</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Descripción</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Proveedor</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">PM</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Legajo</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Vencimiento</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Observaciones</th>
                                <th v-if="hasPermission('articulos.edit')" class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="articulos.data.length === 0">
                                <td colspan="9" class="px-4 py-12 text-center text-sm text-gray-400">
                                    <template v-if="search">
                                        No se encontraron artículos para "<span class="font-medium">{{ search }}</span>".
                                    </template>
                                    <template v-else>
                                        No hay artículos. Usá el botón <strong>Sincronizar</strong> para importarlos desde RP Sistemas.
                                    </template>
                                </td>
                            </tr>
                            <tr
                                v-for="articulo in articulos.data"
                                :key="articulo.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ articulo.codigo }}</td>
                                <td class="px-4 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ articulo.descripcion }}
                                    <span v-if="articulo.descripcion_adicional" class="block text-theme-xs font-normal text-gray-400">
                                        {{ articulo.descripcion_adicional }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <Badge :variant="articulo.activo ? 'emerald' : 'slate'">
                                        {{ articulo.activo ? 'Activo' : 'Discontinuado' }}
                                    </Badge>
                                </td>
                                <td class="max-w-52 truncate px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">{{ articulo.proveedor?.razon_social ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">{{ articulo.pm ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">{{ articulo.legajo ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ formatFecha(articulo.fecha_vencimiento) }}</td>
                                <td class="max-w-60 truncate px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ articulo.observaciones ?? '—' }}</td>
                                <td v-if="hasPermission('articulos.edit')" class="px-4 py-3.5 text-right">
                                    <Link
                                        :href="route('articulos.edit', articulo.id)"
                                        class="inline-flex rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                        title="Editar"
                                    >
                                        <Icon name="pencil" class="h-4.5 w-4.5" />
                                        <span class="sr-only">Editar artículo {{ articulo.descripcion }}</span>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="articulos.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="articulo in articulos.data" :key="articulo.id">
                        <template #header>
                            <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ articulo.descripcion }}</p>
                            <p v-if="articulo.descripcion_adicional" class="text-theme-xs text-gray-400">{{ articulo.descripcion_adicional }}</p>
                            <p class="font-mono text-theme-xs text-gray-400">{{ articulo.codigo }}</p>
                        </template>
                        <template v-if="hasPermission('articulos.edit')" #actions>
                            <Link
                                :href="route('articulos.edit', articulo.id)"
                                class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                title="Editar"
                            >
                                <Icon name="pencil" class="h-4.5 w-4.5" />
                                <span class="sr-only">Editar artículo {{ articulo.descripcion }}</span>
                            </Link>
                        </template>
                        <template #body>
                            <DataRow label="Estado">
                                <Badge :variant="articulo.activo ? 'emerald' : 'slate'">
                                    {{ articulo.activo ? 'Activo' : 'Discontinuado' }}
                                </Badge>
                            </DataRow>
                            <DataRow label="Proveedor">{{ articulo.proveedor?.razon_social ?? '—' }}</DataRow>
                            <DataRow label="PM">{{ articulo.pm ?? '—' }}</DataRow>
                            <DataRow label="Legajo">{{ articulo.legajo ?? '—' }}</DataRow>
                            <DataRow label="Vencimiento">{{ formatFecha(articulo.fecha_vencimiento) }}</DataRow>
                            <DataRow label="Observaciones">{{ articulo.observaciones ?? '—' }}</DataRow>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    <template v-if="search">No se encontraron artículos para "<span class="font-medium">{{ search }}</span>".</template>
                    <template v-else>No hay artículos. Usá el botón <strong>Sincronizar</strong> para importarlos desde RP Sistemas.</template>
                </p>

                <!-- Footer: total + paginación -->
                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ articulos.total }} artículo{{ articulos.total !== 1 ? 's' : '' }} encontrado{{ articulos.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="articulos.links" />
                </div>
            </div>

        </div>

        <!-- Importación masiva por Excel -->
        <Modal :show="showImportModal" title="Importar Excel de artículos" @close="showImportModal = false">
            <form @submit.prevent="submitImport" class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Archivo (.xlsx, .xls o .csv)
                    </label>
                    <input
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        class="w-full cursor-pointer rounded-lg border border-gray-300 text-sm text-gray-700 shadow-theme-xs file:mr-4 file:cursor-pointer file:border-0 file:bg-gray-100 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-gray-700 dark:border-gray-700 dark:text-gray-300 dark:file:bg-white/[0.05] dark:file:text-gray-300"
                        @change="onArchivoChange"
                    />
                    <p v-if="importForm.errors.archivo" class="mt-1.5 text-xs text-error-500 dark:text-error-400">
                        {{ importForm.errors.archivo }}
                    </p>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        Con encabezado, buscado por nombre de columna (no importa el orden ni si hay huecos):
                        <strong>código de artículo</strong>, <strong>descripción</strong>,
                        <strong>fecha de vencimiento</strong>, <strong>PM</strong>, <strong>legajo</strong>,
                        <strong>observaciones</strong> y <strong>proveedor</strong> (la razón social, como en
                        <code>proveedor_principal</code>). Se usan solo las columnas que estén; las celdas
                        vacías no pisan lo que ya está cargado.
                    </p>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        El proveedor se busca en el padrón por razón social, ignorando puntos y espacios.
                    </p>
                </div>

                <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input
                        v-model="importForm.crear_faltantes"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 shrink-0 rounded accent-brand-500 dark:accent-brand-400"
                    />
                    <span>
                        Crear los artículos y proveedores que falten
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Los artículos se dan de alta con la columna de descripción, y los proveedores con
                            su razón social (sin número, que se completa solo al importar el padrón). Sin
                            tildar, lo que no esté cargado se saltea con aviso — que es lo que corresponde
                            para la planilla de Calidad.
                        </span>
                    </span>
                </label>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="importForm.processing">
                        {{ importForm.processing ? 'Importando...' : 'Importar' }}
                    </Button>
                    <Button variant="outline" @click="showImportModal = false">Cancelar</Button>
                </div>
            </form>
        </Modal>

        <Modal :show="verCodigosInvalidos" size="lg" @close="verCodigosInvalidos = false">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                    Códigos de proveedor mal cargados en RP
                </h2>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                    Estos valores están en el campo <span class="font-mono">codigo_proveedor</span>
                    del ERP pero no son un número de proveedor del padrón, así que no vinculan nada.
                    La mayoría parecen costos tipeados en el campo equivocado.
                </p>

                <div class="mt-4 max-h-96 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-800">
                    <table class="w-full text-theme-xs">
                        <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Valor cargado</th>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Problema</th>
                                <th class="px-4 py-2.5 text-right font-medium text-gray-500 dark:text-gray-400">Artículos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="c in codigosInvalidos" :key="c.codigo_proveedor">
                                <td class="px-4 py-2 font-mono text-gray-800 dark:text-white/90">{{ c.codigo_proveedor }}</td>
                                <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ c.motivo }}</td>
                                <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ c.articulos }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 flex justify-end">
                    <Button variant="outline" @click="verCodigosInvalidos = false">Cerrar</Button>
                </div>
            </div>
        </Modal>

    </AppLayout>
</template>
