<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Input from '@/Components/Input.vue'
import Icon from '@/Components/Icon.vue'
import Button from '@/Components/Button.vue'
import Pagination from '@/Components/Pagination.vue'
import type { Cliente, PaginatedData } from '@/types'

const props = defineProps<{
    clientes: PaginatedData<Cliente>
    filters: { search: string }
    lastSync: string | null
}>()

const { hasPermission } = usePermissions()

const search = ref(props.filters.search ?? '')
let debounce: ReturnType<typeof setTimeout>

watch(search, (val) => {
    clearTimeout(debounce)
    debounce = setTimeout(() => {
        router.get(route('clientes.index'), { search: val }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        })
    }, 350)
})

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
                    <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Clientes</h1>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Última sincronización: {{ formatDate(lastSync) }}
                    </p>
                </div>

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

            <!-- Buscador -->
            <div class="max-w-sm">
                <Input v-model="search" type="text" placeholder="Buscar por razón social, CUIT, N°...">
                    <template #icon>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </template>
                </Input>
            </div>

            <!-- Tabla -->
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="overflow-x-auto">
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
                                <th v-if="hasPermission('clientes.edit')" class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="clientes.data.length === 0">
                                <td colspan="9" class="px-4 py-12 text-center text-sm text-gray-400">
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

                <!-- Footer: total + paginación -->
                <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ clientes.total }} cliente{{ clientes.total !== 1 ? 's' : '' }} encontrado{{ clientes.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="clientes.links" />
                </div>
            </div>

        </div>
    </AppLayout>
</template>
