<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
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
</script>

<template>
    <Head title="Clientes" />
    <AppLayout>
        <div class="space-y-5">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-800">Clientes</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Última sincronización: {{ formatDate(lastSync) }}
                    </p>
                </div>

                <button
                    v-if="hasPermission('clientes.sync')"
                    :disabled="syncing"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-[#2a3182] text-white text-sm font-medium hover:bg-[#1e2460] transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                    @click="triggerSync"
                >
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
                </button>
            </div>

            <!-- Buscador -->
            <div class="relative max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar por razón social, CUIT, N°..."
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-lg bg-white text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-[#2a3182]/30 focus:border-[#2a3182]"
                />
            </div>

            <!-- Tabla -->
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                <th class="px-4 py-3">N°</th>
                                <th class="px-4 py-3">Razón Social</th>
                                <th class="px-4 py-3">CUIT</th>
                                <th class="px-4 py-3">IVA</th>
                                <th class="px-4 py-3">Localidad</th>
                                <th class="px-4 py-3">Teléfono</th>
                                <th class="px-4 py-3">Mail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-if="clientes.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-slate-400 text-sm">
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
                                class="hover:bg-slate-50 transition-colors"
                            >
                                <td class="px-4 py-3 font-mono text-slate-500 text-xs">{{ cliente.numero }}</td>
                                <td class="px-4 py-3 font-medium text-slate-800">
                                    {{ cliente.razon_social }}
                                    <span v-if="cliente.nombre_fantasia" class="block text-xs text-slate-400 font-normal">
                                        {{ cliente.nombre_fantasia }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-slate-600 text-xs">{{ cliente.cuit ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-500 text-xs">{{ cliente.descripcion_iva ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-600 text-xs">
                                    {{ [cliente.localidad, cliente.descripcion_provincia].filter(Boolean).join(', ') || '—' }}
                                </td>
                                <td class="px-4 py-3 text-slate-500 text-xs">{{ cliente.telefono || '—' }}</td>
                                <td class="px-4 py-3 text-slate-500 text-xs truncate max-w-[180px]">
                                    <a v-if="cliente.mail" :href="`mailto:${cliente.mail}`" class="hover:text-[#2a3182] hover:underline">
                                        {{ cliente.mail }}
                                    </a>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer: total + paginación -->
                <div class="px-4 py-3 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-slate-500">
                    <span>{{ clientes.total }} cliente{{ clientes.total !== 1 ? 's' : '' }} encontrado{{ clientes.total !== 1 ? 's' : '' }}</span>

                    <div class="flex items-center gap-1">
                        <component
                            :is="link.url ? 'a' : 'span'"
                            v-for="link in clientes.links"
                            :key="link.label"
                            :href="link.url ?? undefined"
                            v-html="link.label"
                            class="px-2.5 py-1 rounded-md border transition-colors"
                            :class="[
                                link.active
                                    ? 'bg-[#2a3182] text-white border-[#2a3182]'
                                    : link.url
                                        ? 'border-slate-200 hover:bg-slate-50 cursor-pointer'
                                        : 'border-transparent text-slate-300 cursor-default'
                            ]"
                            @click.prevent="link.url && router.get(link.url)"
                        />
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
