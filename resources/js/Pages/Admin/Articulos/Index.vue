<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Input from '@/Components/Input.vue'
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
    filters: { search: string }
    lastSync: string | null
    total: number
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
let debounce: ReturnType<typeof setTimeout>

watch(search, (val) => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get(route('articulos.index'), { search: val }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        })
    }, 350)
})

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
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
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

            <!-- Buscador -->
            <div class="max-w-sm">
                <Input v-model="search" type="text" placeholder="Buscar por código, descripción, PM, legajo...">
                    <template #icon>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </template>
                </Input>
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Código</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Descripción</th>
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
                                <td colspan="8" class="px-4 py-12 text-center text-sm text-gray-400">
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
    </AppLayout>
</template>
