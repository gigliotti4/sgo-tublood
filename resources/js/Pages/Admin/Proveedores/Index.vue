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
import type { PaginatedData, Proveedor } from '@/types'

const props = defineProps<{
    proveedores: PaginatedData<Proveedor>
    filters: { search: string }
    total: number
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
let debounce: ReturnType<typeof setTimeout>

watch(search, (val) => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get(route('proveedores.index'), { search: val }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        })
    }, 350)
})

const showImportModal = ref(false)
const importForm = useForm({
    archivo: null as File | null,
})

const onArchivoChange = (e: Event) => {
    importForm.archivo = (e.target as HTMLInputElement).files?.[0] ?? null
}

const submitImport = () => {
    importForm.post(route('proveedores.import'), {
        forceFormData: true,
        onSuccess: () => {
            showImportModal.value = false
            importForm.reset()
        },
    })
}
</script>

<template>
    <Head title="Proveedores" />
    <AppLayout>
        <div class="space-y-6">

            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Proveedores</h1>
                        <ContadorRegistros :total="total" :filtrados="proveedores.total" />
                    </div>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        El padrón se carga desde la planilla Excel.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button v-if="hasPermission('proveedores.import')" variant="primary" @click="showImportModal = true">
                        Importar Excel
                    </Button>
                </div>
            </div>

            <!-- Buscador -->
            <div class="max-w-sm">
                <Input v-model="search" type="text" placeholder="Buscar por número, razón social, domicilio...">
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
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">N°</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Razón Social</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Domicilio</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Localidad</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Teléfono</th>
                                <th class="px-4 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Mail</th>
                                <th v-if="hasPermission('proveedores.edit')" class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="proveedores.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400">
                                    <template v-if="search">
                                        No se encontraron proveedores para "<span class="font-medium">{{ search }}</span>".
                                    </template>
                                    <template v-else>
                                        No hay proveedores. Usá el botón <strong>Importar Excel</strong> para cargar el padrón.
                                    </template>
                                </td>
                            </tr>
                            <tr
                                v-for="proveedor in proveedores.data"
                                :key="proveedor.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="px-4 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="proveedor.numero">{{ proveedor.numero }}</span>
                                    <span v-else class="font-sans italic text-gray-400" title="Lo creó el import de artículos; se completa al importar el padrón">sin número</span>
                                </td>
                                <td class="px-4 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ proveedor.razon_social }}
                                    <span v-if="proveedor.cuit" class="block text-theme-xs font-normal text-gray-400">
                                        CUIT {{ proveedor.cuit }}
                                    </span>
                                </td>
                                <td class="max-w-60 truncate px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">{{ proveedor.domicilio ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-600 dark:text-gray-300">{{ proveedor.localidad ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ proveedor.telefono ?? '—' }}</td>
                                <td class="px-4 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ proveedor.mail ?? '—' }}</td>
                                <td v-if="hasPermission('proveedores.edit')" class="px-4 py-3.5 text-right">
                                    <Link
                                        :href="route('proveedores.edit', proveedor.id)"
                                        class="inline-flex rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                        title="Editar"
                                    >
                                        <Icon name="pencil" class="h-4.5 w-4.5" />
                                        <span class="sr-only">Editar proveedor {{ proveedor.razon_social }}</span>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
                <div v-if="proveedores.data.length" class="space-y-3 p-4 md:hidden">
                    <TableCard v-for="proveedor in proveedores.data" :key="proveedor.id">
                        <template #header>
                            <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ proveedor.razon_social }}</p>
                            <p class="font-mono text-theme-xs text-gray-400">{{ proveedor.numero ? `N° ${proveedor.numero}` : 'sin número' }}</p>
                        </template>
                        <template v-if="hasPermission('proveedores.edit')" #actions>
                            <Link
                                :href="route('proveedores.edit', proveedor.id)"
                                class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                title="Editar"
                            >
                                <Icon name="pencil" class="h-4.5 w-4.5" />
                                <span class="sr-only">Editar proveedor {{ proveedor.razon_social }}</span>
                            </Link>
                        </template>
                        <template #body>
                            <DataRow label="Domicilio">{{ proveedor.domicilio ?? '—' }}</DataRow>
                            <DataRow label="Localidad">{{ proveedor.localidad ?? '—' }}</DataRow>
                            <DataRow label="CUIT">{{ proveedor.cuit ?? '—' }}</DataRow>
                            <DataRow label="Teléfono">{{ proveedor.telefono ?? '—' }}</DataRow>
                            <DataRow label="Mail">{{ proveedor.mail ?? '—' }}</DataRow>
                        </template>
                    </TableCard>
                </div>
                <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                    <template v-if="search">No se encontraron proveedores para "<span class="font-medium">{{ search }}</span>".</template>
                    <template v-else>No hay proveedores. Usá el botón <strong>Importar Excel</strong> para cargar el padrón.</template>
                </p>

                <!-- Footer: total + paginación -->
                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ proveedores.total }} proveedor{{ proveedores.total !== 1 ? 'es' : '' }} encontrado{{ proveedores.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="proveedores.links" />
                </div>
            </div>

        </div>

        <!-- Importación masiva por Excel -->
        <Modal :show="showImportModal" title="Importar Excel de proveedores" @close="showImportModal = false">
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
                        <strong>NUM_PROV</strong>, <strong>RAZON</strong> y <strong>DOMICILIO</strong>.
                        Los proveedores que no existan se crean y los que ya estén se actualizan.
                        Los datos cargados a mano (CUIT, teléfono, mail, localidad y observaciones) no se pisan.
                    </p>
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
