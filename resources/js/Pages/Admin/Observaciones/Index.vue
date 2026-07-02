<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import Pagination from '@/Components/Pagination.vue'
import type { Observacion, PaginatedData } from '@/types'

interface UsuarioOption { id: number; name: string }

defineProps<{
    observaciones: PaginatedData<Observacion>
    usuarios: UsuarioOption[]
}>()

const { hasPermission } = usePermissions()

const origenLabels: Record<string, string> = {
    interna: 'Interna',
    externa: 'Externa',
}

const tipoLabels: Record<string, string> = {
    falla_producto: 'Falla de Producto',
    disconformidad_servicio: 'Disconformidad de Servicio',
}

const estadoLabels: Record<string, string> = {
    pendiente_clasificacion: 'Pendiente de clasificación',
    clasificada: 'Clasificada',
    en_proceso: 'En proceso',
    derivada: 'Derivada',
    resuelta: 'Resuelta',
    cerrada: 'Cerrada',
    cancelada: 'Cancelada',
}

const estadoVariant: Record<string, 'amber' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'slate' | 'red'> = {
    pendiente_clasificacion: 'amber',
    clasificada: 'blue',
    en_proceso: 'indigo',
    derivada: 'purple',
    resuelta: 'emerald',
    cerrada: 'slate',
    cancelada: 'red',
}

const formatFecha = (d: string) =>
    new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })

const observacionEnEdicion = ref<Observacion | null>(null)

const form = useForm({
    responsable_id: null as number | null,
    estado: '',
})

const abrirEdicion = (o: Observacion) => {
    observacionEnEdicion.value = o
    form.clearErrors()
    form.responsable_id = o.responsable_id
    form.estado = o.estado
}

const cerrarEdicion = () => { observacionEnEdicion.value = null }

const guardar = () => {
    if (!observacionEnEdicion.value) return
    form.put(route('observaciones.update', observacionEnEdicion.value.id), {
        onSuccess: cerrarEdicion,
    })
}
</script>

<template>
    <Head title="Observaciones" />
    <AppLayout>
        <div class="space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">Observaciones</h2>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Listado de observaciones cargadas en el sistema</p>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/40 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                <th class="px-4 py-3">N°</th>
                                <th class="px-4 py-3">Tipo</th>
                                <th class="px-4 py-3">Origen</th>
                                <th class="px-4 py-3">Título</th>
                                <th class="px-4 py-3">Cliente</th>
                                <th class="px-4 py-3">Responsable</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3">Fecha</th>
                                <th v-if="hasPermission('observaciones.edit')" class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <tr v-if="observaciones.data.length === 0">
                                <td colspan="9" class="px-4 py-12 text-center text-slate-400 dark:text-slate-500 text-sm">
                                    No hay observaciones cargadas todavía.
                                </td>
                            </tr>
                            <tr
                                v-for="o in observaciones.data"
                                :key="o.id"
                                class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors"
                            >
                                <td class="px-4 py-3 font-mono text-slate-500 dark:text-slate-400 text-xs">{{ o.numero }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ tipoLabels[o.tipo] ?? o.tipo }}</td>
                                <td class="px-4 py-3">
                                    <Badge variant="slate">{{ origenLabels[o.origen] ?? o.origen }}</Badge>
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{{ o.titulo }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.contacto_nombre }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.responsable?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabels[o.estado] ?? o.estado }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ formatFecha(o.created_at) }}</td>
                                <td v-if="hasPermission('observaciones.edit')" class="px-4 py-3 text-right">
                                    <button
                                        class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 text-xs font-medium cursor-pointer"
                                        @click="abrirEdicion(o)"
                                    >
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span>{{ observaciones.total }} observación{{ observaciones.total !== 1 ? 'es' : '' }} encontrada{{ observaciones.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="observaciones.links" />
                </div>
            </div>
        </div>

        <!-- Modal de edición -->
        <Modal :show="observacionEnEdicion !== null" title="Editar observación" size="lg" @close="cerrarEdicion">
            <template v-if="observacionEnEdicion">
                <div class="flex items-center gap-3 mb-4">
                    <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ observacionEnEdicion.numero }}</span>
                    <Badge variant="slate">{{ origenLabels[observacionEnEdicion.origen] ?? observacionEnEdicion.origen }}</Badge>
                </div>

                <div class="space-y-3 mb-5">
                    <div>
                        <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Tipo</p>
                        <p class="text-sm text-slate-800 dark:text-slate-100 mt-0.5">{{ tipoLabels[observacionEnEdicion.tipo] ?? observacionEnEdicion.tipo }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Título</p>
                        <p class="text-sm text-slate-800 dark:text-slate-100 mt-0.5">{{ observacionEnEdicion.titulo }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Descripción</p>
                        <p class="text-sm text-slate-600 dark:text-slate-300 mt-0.5 whitespace-pre-line">{{ observacionEnEdicion.descripcion }}</p>
                    </div>
                </div>

                <!-- Cliente -->
                <div class="rounded-lg border border-slate-200 dark:border-slate-600 p-4 mb-5">
                    <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-2">Cliente</p>
                    <template v-if="observacionEnEdicion.cliente">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ observacionEnEdicion.cliente.razon_social }}</p>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 mt-2 text-xs">
                            <dt class="text-slate-400 dark:text-slate-500">N° cliente</dt>
                            <dd class="text-slate-600 dark:text-slate-300 font-mono">{{ observacionEnEdicion.cliente.numero }}</dd>
                            <dt class="text-slate-400 dark:text-slate-500">Email</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.cliente.mail ?? '—' }}</dd>
                            <dt class="text-slate-400 dark:text-slate-500">Teléfono</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.cliente.telefono ?? '—' }}</dd>
                        </dl>
                    </template>
                    <template v-else>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mb-2">Sin cliente vinculado en RP Sistemas. Datos ingresados por el contacto:</p>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <dt class="text-slate-400 dark:text-slate-500">Nombre</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.contacto_nombre }}</dd>
                            <dt class="text-slate-400 dark:text-slate-500">Email</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.contacto_email }}</dd>
                            <dt class="text-slate-400 dark:text-slate-500">Teléfono</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.contacto_telefono ?? '—' }}</dd>
                        </dl>
                    </template>
                </div>

                <!-- Estado y responsable -->
                <form @submit.prevent="guardar" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <Select v-model="form.estado" label="Estado" :error="form.errors.estado">
                            <option v-for="(label, estado) in estadoLabels" :key="estado" :value="estado">
                                {{ label }}
                            </option>
                        </Select>

                        <Select v-model="form.responsable_id" label="Responsable" :error="form.errors.responsable_id">
                            <option :value="null">— Sin asignar —</option>
                            <option v-for="usuario in usuarios" :key="usuario.id" :value="usuario.id">
                                {{ usuario.name }}
                            </option>
                        </Select>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <Button type="submit" variant="primary" :disabled="form.processing">Guardar cambios</Button>
                        <button class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200" @click="cerrarEdicion">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
