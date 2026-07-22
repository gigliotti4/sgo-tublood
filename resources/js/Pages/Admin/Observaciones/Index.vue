<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import FormSection from '@/Components/FormSection.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import Pagination from '@/Components/Pagination.vue'
import type { Observacion, PaginatedData } from '@/types'

interface UsuarioOption {
    id: number
    name: string
    apellido: string | null
    area_id: number | null
    area: { nombre: string; dias_gestion: number | null } | null
}
interface SectorOption { id: number; nombre: string }
interface AreaOption { id: number; nombre: string }

const props = defineProps<{
    observaciones: PaginatedData<Observacion>
    usuarios: UsuarioOption[]
    sectores: SectorOption[]
    areas: AreaOption[]
    tipoLabels: Record<string, string>
    prioridades: Record<string, string>
    tiposCaso: string[]
}>()

const { isSuperAdmin, user } = usePermissions()

const puedeEditar = (o: Observacion) => isSuperAdmin.value || o.responsable_id === user.value?.id

// El cliente ingresó un N° que no matcheó ningún cliente cargado (dato para revisar).
const clienteNoEncontrado = (o: Observacion) => !o.cliente && !!o.contacto_numero_cliente

const origenLabels: Record<string, string> = {
    interna: 'Interna',
    externa: 'Externa',
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

const ESTADOS_FINALES = ['cerrada', 'cancelada']

/**
 * Plazo de gestión: arranca al asignar el responsable y sale del área de esa
 * persona. Sin responsable (o sin plazo cargado en su área) no hay vencimiento.
 */
const estadoPlazo = (o: Observacion): { label: string; variant: 'red' | 'amber' } | null => {
    if (!o.vence_at || ESTADOS_FINALES.includes(o.estado)) return null

    const dias = Math.ceil((new Date(o.vence_at).getTime() - Date.now()) / 86400000)

    if (dias < 0) return { label: `Vencida hace ${Math.abs(dias)} d`, variant: 'red' }
    if (dias <= 1) return { label: dias === 0 ? 'Vence hoy' : 'Vence mañana', variant: 'amber' }

    return null
}

const observacionEnEdicion = ref<Observacion | null>(null)

const form = useForm({
    responsable_id: null as number | null,
    sector_id: null as number | null,
    area_id: null as number | null,
    estado: '',
    prioridad: null as string | null,
    tipo_caso: null as string | null,
})

const abrirEdicion = (o: Observacion) => {
    observacionEnEdicion.value = o
    form.clearErrors()
    form.responsable_id = o.responsable_id
    form.sector_id = o.sector_id
    form.area_id = o.area_id
    form.estado = o.estado
    form.prioridad = o.prioridad
    form.tipo_caso = o.tipo_caso
}

const cerrarEdicion = () => { observacionEnEdicion.value = null }

const nombreCompleto = (u: UsuarioOption) => [u.name, u.apellido].filter(Boolean).join(' ')

/**
 * Elegir área recorta la lista de responsables a esa área. Se deja pasar igual
 * al responsable ya asignado aunque sea de otra: la observación puede venir
 * con una combinación cargada antes, y no hay que hacerlo desaparecer.
 */
const usuariosFiltrados = computed(() => {
    if (!form.area_id) return props.usuarios

    return props.usuarios.filter(u => u.area_id === form.area_id || u.id === form.responsable_id)
})

/**
 * Al cambiar de área se suelta el responsable si era de otra. Va en @change y
 * no en un watch a propósito: abrir el modal setea form.area_id y un watch
 * borraría el responsable que ya tenía la observación.
 */
const alCambiarArea = () => {
    if (!form.area_id) return

    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (elegido && elegido.area_id !== form.area_id) form.responsable_id = null
}

const gentePorArea = computed(() => {
    if (!form.area_id) return null

    const total = props.usuarios.filter(u => u.area_id === form.area_id).length

    return total === 0
        ? 'Esta área no tiene usuarios cargados.'
        : `${total} ${total === 1 ? 'persona' : 'personas'} en esta área.`
})

/**
 * Asignar responsable arranca el reloj de gestión, y el plazo sale del área de
 * esa persona: conviene verlo antes de guardar.
 */
const plazoDelResponsable = computed(() => {
    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (!elegido) return null

    if (!elegido.area) return 'Esta persona no tiene área, así que la observación no va a generar alertas.'
    if (!elegido.area.dias_gestion) return `El área ${elegido.area.nombre} no tiene plazo cargado: no va a generar alertas.`

    return `El plazo pasa a ser de ${elegido.area.dias_gestion} días hábiles (${elegido.area.nombre}).`
})

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
                                <th class="px-4 py-3">Sector</th>
                                <th class="px-4 py-3">Área</th>
                                <th class="px-4 py-3">Responsable</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3">Fecha</th>
                                <th class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <tr v-if="observaciones.data.length === 0">
                                <td colspan="11" class="px-4 py-12 text-center text-slate-400 dark:text-slate-500 text-sm">
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
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                    <div class="flex items-center gap-2">
                                        <span>{{ o.contacto_nombre }}</span>
                                        <Badge v-if="clienteNoEncontrado(o)" variant="amber">N° no encontrado</Badge>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.sector?.nombre ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.area?.nombre ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ o.responsable?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabels[o.estado] ?? o.estado }}</Badge>
                                    <Badge v-if="estadoPlazo(o)" :variant="estadoPlazo(o)!.variant">
                                        {{ estadoPlazo(o)!.label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ formatFecha(o.created_at) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="puedeEditar(o)"
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

        <Modal :show="observacionEnEdicion !== null" title="Editar observación" size="xl" @close="cerrarEdicion">
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

                <!-- Productos -->
                <div v-if="observacionEnEdicion.productos.length" class="rounded-lg border border-slate-200 dark:border-slate-600 p-4 mb-5">
                    <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-2">Productos</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-slate-400 dark:text-slate-500">
                                    <th class="pr-4 py-1">Producto</th>
                                    <th class="pr-4 py-1">Código</th>
                                    <th class="pr-4 py-1">Cantidad</th>
                                    <th class="pr-4 py-1">Lote</th>
                                    <th class="pr-4 py-1">Vencimiento</th>
                                    <th class="pr-4 py-1">Remito</th>
                                    <th class="py-1">Comprobante</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                                <tr v-for="p in observacionEnEdicion.productos" :key="p.id" class="text-slate-600 dark:text-slate-300">
                                    <td class="pr-4 py-1.5">{{ p.producto }}</td>
                                    <td class="pr-4 py-1.5">{{ p.codigo ?? '—' }}</td>
                                    <td class="pr-4 py-1.5">{{ p.cantidad_afectada }}</td>
                                    <td class="pr-4 py-1.5">{{ p.lote }}</td>
                                    <td class="pr-4 py-1.5">{{ formatFecha(p.fecha_vencimiento) }}</td>
                                    <td class="pr-4 py-1.5">{{ p.numero_remito }}</td>
                                    <td class="py-1.5 capitalize">{{ p.tipo_comprobante }}</td>
                                </tr>
                            </tbody>
                        </table>
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
                        <div v-if="clienteNoEncontrado(observacionEnEdicion)" class="mb-3 rounded-md bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-3 py-2">
                            <p class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                N° ingresado <span class="font-mono">{{ observacionEnEdicion.contacto_numero_cliente }}</span> — no coincide con ningún cliente cargado. Revisar.
                            </p>
                        </div>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mb-2">Sin cliente vinculado en RP Sistemas. Datos ingresados por el contacto:</p>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <dt class="text-slate-400 dark:text-slate-500">Nombre</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.contacto_nombre }}</dd>
                            <dt class="text-slate-400 dark:text-slate-500">Email</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.contacto_email }}</dd>
                            <dt class="text-slate-400 dark:text-slate-500">N° cliente</dt>
                            <dd class="text-slate-600 dark:text-slate-300 font-mono">{{ observacionEnEdicion.contacto_numero_cliente || '—' }}</dd>
                            <dt class="text-slate-400 dark:text-slate-500">Teléfono</dt>
                            <dd class="text-slate-600 dark:text-slate-300">{{ observacionEnEdicion.contacto_telefono ?? '—' }}</dd>
                        </dl>
                    </template>
                </div>

                <!-- Clasificación y asignación -->
                <form @submit.prevent="guardar" class="space-y-6">
                    <FormSection title="Clasificación">
                        <Select v-model="form.prioridad" label="Prioridad" :error="form.errors.prioridad">
                            <option :value="null">— Sin clasificar —</option>
                            <option v-for="(label, key) in prioridades" :key="key" :value="key">
                                {{ label }}
                            </option>
                        </Select>

                        <Select v-model="form.tipo_caso" label="Tipo de caso" :error="form.errors.tipo_caso">
                            <option :value="null">— Sin clasificar —</option>
                            <option v-for="tc in tiposCaso" :key="tc" :value="tc">
                                {{ tc }}
                            </option>
                        </Select>

                        <p
                            v-if="observacionEnEdicion.estado === 'pendiente_clasificacion' && form.prioridad && form.tipo_caso"
                            class="sm:col-span-2 text-xs text-amber-600 dark:text-amber-400"
                        >
                            Al guardar, la observación pasará a <strong>Clasificada</strong>.
                        </p>
                    </FormSection>

                    <FormSection title="Asignación">
                        <Select v-model="form.estado" label="Estado" :error="form.errors.estado">
                            <option v-for="(label, estado) in estadoLabels" :key="estado" :value="estado">
                                {{ label }}
                            </option>
                        </Select>

                        <Select v-model="form.sector_id" label="Sector" :error="form.errors.sector_id">
                            <option :value="null">— Sin asignar —</option>
                            <option v-for="sector in sectores" :key="sector.id" :value="sector.id">
                                {{ sector.nombre }}
                            </option>
                        </Select>

                        <Select
                            v-model="form.area_id"
                            label="Área"
                            :hint="gentePorArea ?? undefined"
                            :error="form.errors.area_id"
                            @change="alCambiarArea"
                        >
                            <option :value="null">— Sin asignar —</option>
                            <option v-for="area in areas" :key="area.id" :value="area.id">
                                {{ area.nombre }}
                            </option>
                        </Select>

                        <Select
                            v-model="form.responsable_id"
                            label="Responsable"
                            :hint="plazoDelResponsable ?? undefined"
                            :error="form.errors.responsable_id"
                        >
                            <option :value="null">— Sin asignar —</option>
                            <option v-for="usuario in usuariosFiltrados" :key="usuario.id" :value="usuario.id">
                                {{ nombreCompleto(usuario) }}
                            </option>
                        </Select>
                    </FormSection>

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
