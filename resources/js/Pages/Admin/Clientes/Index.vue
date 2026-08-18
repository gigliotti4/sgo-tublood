<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Input from '@/Components/Input.vue'
import Select from '@/Components/Select.vue'
import Badge from '@/Components/Badge.vue'
import Modal from '@/Components/Modal.vue'
import Icon from '@/Components/Icon.vue'
import Button from '@/Components/Button.vue'
import Pagination from '@/Components/Pagination.vue'
import ContadorRegistros from '@/Components/ContadorRegistros.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import type { Cliente, PaginatedData } from '@/types'

const props = defineProps<{
    clientes: PaginatedData<Cliente>
    filters: { search: string; tipo_cliente: string; estado_documental: string }
    tipos: Record<string, string>
    lastSync: string | null
    total: number
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
const tipoCliente = ref(props.filters.tipo_cliente ?? '')
const filtroEstado = ref(props.filters.estado_documental ?? '')

const recargar = () => {
    router.get(route('clientes.index'), {
        search: search.value,
        tipo_cliente: tipoCliente.value,
        estado_documental: filtroEstado.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

let debounce: ReturnType<typeof setTimeout>

// Solo el buscador necesita debounce: los selects cambian de a un valor.
watch(search, () => {
    clearTimeout(debounce)
    debounce = setTimeout(recargar, 350)
})

watch([tipoCliente, filtroEstado], recargar)

const ESTADOS_DOCUMENTALES: Record<string, string> = {
    completa: 'Documentación completa',
    incompleta: 'Documentación incompleta',
    vencida: 'Con documentación vencida',
    sin_tipo: 'Sin tipo de cliente',
}

/** Mismo criterio que Cliente::estadoDocumentacion(): vencida gana sobre completa. */
const semaforoDocumental = (c: Cliente) => {
    if (!c.tipo_cliente) return { label: 'Sin tipo', variant: 'slate' as const }
    if (c.tiene_vencidos) return { label: 'Vencida', variant: 'red' as const }
    if (c.documentacion_completa) return { label: 'Completa', variant: 'emerald' as const }
    return { label: 'Incompleta', variant: 'amber' as const }
}

// Descarga directa (no navegación de Inertia): con los mismos filtros que el
// listado, así lo que ves es lo que baja. El archivo que sale es además la
// plantilla del import — ver ClienteExportService.
const urlExportar = computed(() => route('clientes.export', {
    search: search.value || undefined,
    tipo_cliente: tipoCliente.value || undefined,
    estado_documental: filtroEstado.value || undefined,
}))

const showImportModal = ref(false)
const importForm = useForm({
    archivo: null as File | null,
})

const onArchivoChange = (e: Event) => {
    importForm.archivo = (e.target as HTMLInputElement).files?.[0] ?? null
}

const submitImport = () => {
    importForm.post(route('clientes.import'), {
        forceFormData: true,
        onSuccess: () => {
            showImportModal.value = false
            importForm.reset()
        },
    })
}

const syncing = ref(false)

const triggerSync = () => {
    syncing.value = true
    router.post(route('clientes.sync'), {}, {
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

const formatFechaVencimiento = (d: string | null) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('es-AR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
    })
}
</script>

<template>
    <Head title="Clientes" />
    <AppLayout>
        <div class="space-y-6">

            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Clientes</h1>
                        <ContadorRegistros :total="total" :filtrados="clientes.total" />
                    </div>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Última sincronización: {{ formatDate(lastSync) }}
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
                    <Button v-if="hasPermission('clientes.import')" variant="outline" @click="showImportModal = true">
                        Importar Excel
                    </Button>
                    <Button v-if="hasPermission('clientes.sync')" variant="brand" :disabled="syncing" @click="triggerSync">
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

            <!-- Buscador y filtros -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Input v-model="search" type="text" placeholder="Buscar por razón social, CUIT, N°...">
                    <template #icon>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </template>
                </Input>
                <Select v-model="tipoCliente">
                    <option value="">Todos los tipos</option>
                    <option v-for="(label, slug) in tipos" :key="slug" :value="slug">{{ label }}</option>
                </Select>
                <Select v-model="filtroEstado">
                    <option value="">Toda la documentación</option>
                    <option v-for="(label, valor) in ESTADOS_DOCUMENTALES" :key="valor" :value="valor">{{ label }}</option>
                </Select>
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">N°</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Razón Social</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">CUIT</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">IVA</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Localidad</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Teléfono</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Mail</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Vencimiento</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tipo</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Documentación</th>
                                <th v-if="hasPermission('clientes.edit')" class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="clientes.data.length === 0">
                                <td colspan="11" class="px-4 py-12 text-center text-sm text-gray-400">
                                    <template v-if="search">
                                        No se encontraron clientes para "<span class="font-medium">{{ search }}</span>".
                                    </template>
                                    <template v-else>
                                        No hay clientes. Usá el botón <strong>Sincronizar</strong> para importarlos desde RP Sistemas.
                                    </template>
                                </td>
                            </tr>
                            <tr
                                v-for="cliente in clientes.data"
                                :key="cliente.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ cliente.numero }}</td>
                                <td class="px-4 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ cliente.razon_social }}
                                    <span v-if="cliente.nombre_fantasia" class="block text-theme-xs font-normal text-gray-400">
                                        {{ cliente.nombre_fantasia }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-600 dark:text-gray-300">{{ cliente.cuit ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ cliente.descripcion_iva ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">
                                    {{ [cliente.localidad, cliente.descripcion_provincia].filter(Boolean).join(', ') || '—' }}
                                </td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ cliente.telefono || '—' }}</td>
                                <td class="max-w-45 truncate px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                    <a v-if="cliente.mail" :href="`mailto:${cliente.mail}`" class="hover:text-brand-500 hover:underline dark:hover:text-brand-300">
                                        {{ cliente.mail }}
                                    </a>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ formatFechaVencimiento(cliente.fecha_vencimiento) }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ cliente.tipo_cliente ? tipos[cliente.tipo_cliente] : '—' }}
                                </td>
                                <td class="px-4 py-3.5">
                                    <Badge :variant="semaforoDocumental(cliente).variant">{{ semaforoDocumental(cliente).label }}</Badge>
                                </td>
                                <td v-if="hasPermission('clientes.edit')" class="px-4 py-3.5 text-right">
                                    <Link
                                        :href="route('clientes.edit', cliente.id)"
                                        class="inline-flex rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                        title="Editar"
                                    >
                                        <Icon name="pencil" class="h-4.5 w-4.5" />
                                        <span class="sr-only">Editar cliente {{ cliente.razon_social }}</span>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="clientes.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="cliente in clientes.data" :key="cliente.id">
                        <template #header>
                            <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ cliente.razon_social }}</p>
                            <p v-if="cliente.nombre_fantasia" class="text-theme-xs text-gray-400">{{ cliente.nombre_fantasia }}</p>
                            <p class="font-mono text-theme-xs text-gray-400">{{ cliente.numero }}</p>
                        </template>
                        <template v-if="hasPermission('clientes.edit')" #actions>
                            <Link
                                :href="route('clientes.edit', cliente.id)"
                                class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                title="Editar"
                            >
                                <Icon name="pencil" class="h-4.5 w-4.5" />
                                <span class="sr-only">Editar cliente {{ cliente.razon_social }}</span>
                            </Link>
                        </template>
                        <template #body>
                            <DataRow label="CUIT"><span class="font-mono">{{ cliente.cuit ?? '—' }}</span></DataRow>
                            <DataRow label="IVA">{{ cliente.descripcion_iva ?? '—' }}</DataRow>
                            <DataRow label="Localidad">{{ [cliente.localidad, cliente.descripcion_provincia].filter(Boolean).join(', ') || '—' }}</DataRow>
                            <DataRow label="Teléfono">{{ cliente.telefono || '—' }}</DataRow>
                            <DataRow label="Mail">
                                <a v-if="cliente.mail" :href="`mailto:${cliente.mail}`" class="hover:text-brand-500 hover:underline dark:hover:text-brand-300">{{ cliente.mail }}</a>
                                <span v-else>—</span>
                            </DataRow>
                            <DataRow label="Vencimiento">{{ formatFechaVencimiento(cliente.fecha_vencimiento) }}</DataRow>
                            <DataRow label="Tipo">{{ cliente.tipo_cliente ? tipos[cliente.tipo_cliente] : '—' }}</DataRow>
                            <DataRow label="Documentación">
                                <Badge :variant="semaforoDocumental(cliente).variant">{{ semaforoDocumental(cliente).label }}</Badge>
                            </DataRow>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    <template v-if="search">No se encontraron clientes para "<span class="font-medium">{{ search }}</span>".</template>
                    <template v-else>No hay clientes. Usá el botón <strong>Sincronizar</strong> para importarlos desde RP Sistemas.</template>
                </p>

                <!-- Footer: total + paginación -->
                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ clientes.total }} cliente{{ clientes.total !== 1 ? 's' : '' }} encontrado{{ clientes.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="clientes.links" />
                </div>
            </div>

        </div>

        <!-- Carga masiva por Excel. La plantilla es el propio archivo que baja
             "Exportar a Excel": mismos encabezados. -->
        <Modal :show="showImportModal" title="Importar Excel de clientes" @close="showImportModal = false">
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
                    <div class="mt-2 space-y-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <p>
                            La forma más simple es <strong>exportar, editar el archivo y volver a subirlo</strong>:
                            los encabezados son los mismos y las columnas se buscan por nombre, así que no importa
                            el orden ni si faltan. El archivo que baja trae una hoja
                            <strong>“Instructivo”</strong> con cómo se completa cada columna y qué documentación
                            pide cada tipo de cliente.
                        </p>
                        <p>
                            Se identifica cada fila por el <strong>N°</strong> de cliente. Se actualizan
                            <strong>Tipo de cliente</strong>, <strong>Tiene legajo</strong>,
                            <strong>Habilitado</strong>, <strong>Observaciones</strong> y cada documento
                            (<strong>SÍ</strong>/<strong>NO</strong> y su columna <strong>“- Vto”</strong> en dd/mm/aaaa).
                        </p>
                        <p>
                            Los clientes vienen de RP Sistemas: un N° que no exista <strong>no se crea</strong>, se avisa.
                            Las celdas vacías <strong>no borran</strong> lo ya cargado — para dar de baja un documento
                            hay que escribir “NO”. Las columnas calculadas se ignoran: se recalculan solas.
                        </p>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="importForm.processing">
                        {{ importForm.processing ? 'Importando...' : 'Importar' }}
                    </Button>
                    <Button variant="outline" @click="showImportModal = false">Cancelar</Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
