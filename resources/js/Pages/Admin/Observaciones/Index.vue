<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import AdjuntosObservacion from '@/Components/AdjuntosObservacion.vue'
import FormSection from '@/Components/FormSection.vue'
import Icon from '@/Components/Icon.vue'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import Pagination from '@/Components/Pagination.vue'
import type { Observacion, PaginatedData } from '@/types'

interface UsuarioOption {
    id: number
    name: string
    apellido: string | null
    sector_id: number | null
    sector: { nombre: string; dias_gestion: number | null } | null
}
interface SectorOption { id: number; nombre: string }

interface Filtros {
    q?: string
    origen?: string
    prioridad?: string
    tipo_caso?: string
    responsable_id?: string | number
    creado_por?: string | number
    apertura?: string
    desde?: string
    hasta?: string
}

const props = defineProps<{
    observaciones: PaginatedData<Observacion>
    filters: Filtros
    usuarios: UsuarioOption[]
    sectores: SectorOption[]
    tipoLabels: Record<string, string>
    prioridades: Record<string, string>
    tiposCaso: string[]
    presentaciones: Record<string, string>
}>()

const { isSuperAdmin, user } = usePermissions()

const puedeEditar = (o: Observacion) => isSuperAdmin.value || o.responsable_id === user.value?.id

// ── Buscador ──────────────────────────────────────────────────────────────
// Los filtros viven en la URL (el backend los valida y los devuelve como
// prop `filters`): la búsqueda se puede compartir por link y la paginación
// los conserva vía withQueryString().

const filtros = reactive({
    q: props.filters.q ?? '',
    origen: props.filters.origen ?? '',
    prioridad: props.filters.prioridad ?? '',
    tipo_caso: props.filters.tipo_caso ?? '',
    responsable_id: String(props.filters.responsable_id ?? ''),
    creado_por: String(props.filters.creado_por ?? ''),
    apertura: props.filters.apertura ?? '',
    desde: props.filters.desde ?? '',
    hasta: props.filters.hasta ?? '',
})

const hayFiltros = computed(() => Object.values(filtros).some(v => v !== ''))

// Una fecha a medio tipear (dd/mm parcial) no dispara el request: no tiene
// sentido filtrar por un parcial y el backend la rechazaría (regla `date`).
const fechaParcial = (v: string) => v !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(v)

const aplicarFiltros = () => {
    if (fechaParcial(filtros.desde) || fechaParcial(filtros.hasta)) return
    const params = Object.fromEntries(Object.entries(filtros).filter(([, v]) => v !== ''))
    router.get(route('observaciones.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

// Un solo debounce para todos los filtros: absorbe el tipeo en el campo de
// texto y hace que "Limpiar" (que resetea todo junto) dispare un único request.
let debounce: ReturnType<typeof setTimeout>
watch(filtros, () => {
    clearTimeout(debounce)
    debounce = setTimeout(aplicarFiltros, 300)
})

const limpiarFiltros = () => {
    Object.assign(filtros, {
        q: '', origen: '', prioridad: '', tipo_caso: '',
        responsable_id: '', creado_por: '', apertura: '', desde: '', hasta: '',
    })
}

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
 * Plazo de gestión: arranca al asignar el responsable y sale del sector de esa
 * persona. Sin responsable (o sin plazo cargado en su sector) no hay vencimiento.
 */
const estadoPlazo = (o: Observacion): { label: string; variant: 'red' | 'amber' } | null => {
    if (!o.vence_at || ESTADOS_FINALES.includes(o.estado)) return null

    const dias = Math.ceil((new Date(o.vence_at).getTime() - Date.now()) / 86400000)

    if (dias < 0) return { label: `Vencida hace ${Math.abs(dias)} d`, variant: 'red' }
    if (dias <= 1) return { label: dias === 0 ? 'Vence hoy' : 'Vence mañana', variant: 'amber' }

    return null
}

/**
 * Se guarda el id, no la fila: al subir o borrar un adjunto el listado se
 * recarga con `preserveState`, y una copia del objeto quedaría vieja (el
 * archivo nuevo no aparecería hasta cerrar y reabrir el modal). Derivándolo de
 * los props, cualquier refresco se refleja solo.
 */
const idEnEdicion = ref<number | null>(null)

const observacionEnEdicion = computed(
    () => props.observaciones.data.find(o => o.id === idEnEdicion.value) ?? null,
)

const form = useForm({
    responsable_id: null as number | null,
    sector_id: null as number | null,
    estado: '',
    prioridad: null as string | null,
    tipo_caso: null as string | null,
})

const abrirEdicion = (o: Observacion) => {
    idEnEdicion.value = o.id
    form.clearErrors()
    form.responsable_id = o.responsable_id
    form.sector_id = o.sector_id
    form.estado = o.estado
    form.prioridad = o.prioridad
    form.tipo_caso = o.tipo_caso
}

const cerrarEdicion = () => { idEnEdicion.value = null }

const nombreCompleto = (u: UsuarioOption) => [u.name, u.apellido].filter(Boolean).join(' ')

/**
 * Elegir sector recorta la lista de responsables a ese sector. Se deja pasar
 * igual al responsable ya asignado aunque sea de otro: la observación puede
 * venir con una combinación cargada antes, y no hay que hacerlo desaparecer.
 */
const usuariosFiltrados = computed(() => {
    if (!form.sector_id) return props.usuarios

    return props.usuarios.filter(u => u.sector_id === form.sector_id || u.id === form.responsable_id)
})

/**
 * Al cambiar de sector se suelta el responsable si era de otro. Va en @change y
 * no en un watch a propósito: abrir el modal setea form.sector_id y un watch
 * borraría el responsable que ya tenía la observación.
 */
const alCambiarSector = () => {
    if (!form.sector_id) return

    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (elegido && elegido.sector_id !== form.sector_id) form.responsable_id = null
}

const gentePorSector = computed(() => {
    if (!form.sector_id) return null

    const total = props.usuarios.filter(u => u.sector_id === form.sector_id).length

    return total === 0
        ? 'Este sector no tiene usuarios cargados.'
        : `${total} ${total === 1 ? 'persona' : 'personas'} en este sector.`
})

/**
 * Asignar responsable arranca el reloj de gestión, y el plazo sale del sector de
 * esa persona: conviene verlo antes de guardar.
 */
const plazoDelResponsable = computed(() => {
    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (!elegido) return null

    if (!elegido.sector) return 'Esta persona no tiene sector, así que la observación no va a generar alertas.'
    if (!elegido.sector.dias_gestion) return `El sector ${elegido.sector.nombre} no tiene plazo cargado: no va a generar alertas.`

    return `El plazo pasa a ser de ${elegido.sector.dias_gestion} días hábiles (${elegido.sector.nombre}).`
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
        <div class="space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Observaciones</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">Listado de observaciones cargadas en el sistema</p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <!-- Buscador -->
                <div class="border-b border-gray-100 p-5 dark:border-gray-800">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="sm:col-span-2">
                            <Input v-model="filtros.q" label="Buscar" placeholder="N°, título, cliente, producto, código, lote…">
                                <template #icon>
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                </template>
                            </Input>
                        </div>

                        <Select v-model="filtros.apertura" label="Estado">
                            <option value="">Todas</option>
                            <option value="abierta">Abiertas</option>
                            <option value="cerrada">Cerradas</option>
                        </Select>

                        <Select v-model="filtros.origen" label="Origen">
                            <option value="">Todos</option>
                            <option v-for="(label, key) in origenLabels" :key="key" :value="key">{{ label }}</option>
                        </Select>

                        <Select v-model="filtros.prioridad" label="Prioridad">
                            <option value="">Todas</option>
                            <option v-for="(label, key) in prioridades" :key="key" :value="key">{{ label }}</option>
                        </Select>

                        <Select v-model="filtros.tipo_caso" label="Tipo de caso">
                            <option value="">Todos</option>
                            <option v-for="tc in tiposCaso" :key="tc" :value="tc">{{ tc }}</option>
                        </Select>

                        <Select v-model="filtros.responsable_id" label="Responsable">
                            <option value="">Todos</option>
                            <option v-for="u in usuarios" :key="u.id" :value="String(u.id)">{{ nombreCompleto(u) }}</option>
                        </Select>

                        <Select v-model="filtros.creado_por" label="Creador">
                            <option value="">Todos</option>
                            <option v-for="u in usuarios" :key="u.id" :value="String(u.id)">{{ nombreCompleto(u) }}</option>
                        </Select>

                        <InputFecha v-model="filtros.desde" label="Creada desde" />
                        <InputFecha v-model="filtros.hasta" label="Creada hasta" />

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

                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">N°</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tipo</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Origen</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Título</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cliente</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Sector</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Responsable</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                                <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                                <th class="px-5 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-if="observaciones.data.length === 0">
                                <td colspan="10" class="px-5 py-12 text-center text-sm text-gray-400">
                                    {{ hayFiltros ? 'Sin resultados para los filtros aplicados.' : 'No hay observaciones cargadas todavía.' }}
                                </td>
                            </tr>
                            <tr
                                v-for="o in observaciones.data"
                                :key="o.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            >
                                <td class="px-5 py-3.5 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ o.numero }}</td>
                                <td class="px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">{{ tipoLabels[o.tipo] ?? o.tipo }}</td>
                                <td class="px-5 py-3.5">
                                    <Badge variant="slate">{{ origenLabels[o.origen] ?? o.origen }}</Badge>
                                </td>
                                <td class="px-5 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ o.titulo }}</td>
                                <td class="px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">
                                    <div class="flex items-center gap-2">
                                        <span>{{ o.contacto_nombre }}</span>
                                        <Badge v-if="clienteNoEncontrado(o)" variant="amber">N° no encontrado</Badge>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">{{ o.sector?.nombre ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-theme-sm text-gray-600 dark:text-gray-300">{{ o.responsable?.name ?? '—' }}</td>
                                <td class="px-5 py-3.5">
                                    <Badge :variant="estadoVariant[o.estado] ?? 'slate'">{{ estadoLabels[o.estado] ?? o.estado }}</Badge>
                                    <Badge v-if="estadoPlazo(o)" :variant="estadoPlazo(o)!.variant">
                                        {{ estadoPlazo(o)!.label }}
                                    </Badge>
                                </td>
                                <td class="px-5 py-3.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ formatFecha(o.created_at) }}</td>
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
                                            v-if="puedeEditar(o)"
                                            type="button"
                                            class="cursor-pointer rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                            title="Editar"
                                            @click="abrirEdicion(o)"
                                        >
                                            <Icon name="pencil" class="h-4.5 w-4.5" />
                                            <span class="sr-only">Editar {{ o.numero }}</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-100 px-5 py-3.5 text-theme-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>{{ observaciones.total }} observación{{ observaciones.total !== 1 ? 'es' : '' }} encontrada{{ observaciones.total !== 1 ? 's' : '' }}</span>
                    <Pagination :links="observaciones.links" />
                </div>
            </div>
        </div>

        <!-- Modal de edición -->

        <Modal :show="observacionEnEdicion !== null" title="Editar observación" size="2xl" @close="cerrarEdicion">
            <template v-if="observacionEnEdicion">
                <!-- Encabezado: identificación de un vistazo -->
                <div class="mb-6 flex flex-wrap items-center gap-3">
                    <span class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ observacionEnEdicion.numero }}</span>
                    <Badge variant="slate">{{ origenLabels[observacionEnEdicion.origen] ?? observacionEnEdicion.origen }}</Badge>
                    <Badge :variant="estadoVariant[observacionEnEdicion.estado] ?? 'slate'">{{ estadoLabels[observacionEnEdicion.estado] ?? observacionEnEdicion.estado }}</Badge>
                    <span class="ml-auto text-theme-xs text-gray-400">Creada el {{ formatFecha(observacionEnEdicion.created_at) }}</span>
                </div>

                <!-- Dos columnas: detalle (solo lectura) a la izquierda, edición a la derecha -->
                <div class="grid gap-6 lg:grid-cols-5">
                    <div class="space-y-5 lg:col-span-3">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Tipo</p>
                                <p class="mt-0.5 text-sm text-gray-800 dark:text-white/90">{{ tipoLabels[observacionEnEdicion.tipo] ?? observacionEnEdicion.tipo }}</p>
                            </div>
                            <div>
                                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Título</p>
                                <p class="mt-0.5 text-sm text-gray-800 dark:text-white/90">{{ observacionEnEdicion.titulo }}</p>
                            </div>
                        </div>

                        <div>
                            <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Descripción</p>
                            <p class="mt-0.5 max-h-40 overflow-y-auto whitespace-pre-line pr-2 text-sm text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.descripcion }}</p>
                        </div>

                        <!-- Cliente -->
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="mb-2 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Cliente</p>
                            <template v-if="observacionEnEdicion.cliente">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ observacionEnEdicion.cliente.razon_social }}</p>
                                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-theme-xs">
                                    <dt class="text-gray-400">N° cliente</dt>
                                    <dd class="font-mono text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.cliente.numero }}</dd>
                                    <dt class="text-gray-400">Email</dt>
                                    <dd class="text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.cliente.mail ?? '—' }}</dd>
                                    <dt class="text-gray-400">Teléfono</dt>
                                    <dd class="text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.cliente.telefono ?? '—' }}</dd>
                                </dl>
                            </template>
                            <template v-else>
                                <div v-if="clienteNoEncontrado(observacionEnEdicion)" class="mb-3 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 dark:border-warning-500/30 dark:bg-warning-500/15">
                                    <p class="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
                                        N° ingresado <span class="font-mono">{{ observacionEnEdicion.contacto_numero_cliente }}</span> — no coincide con ningún cliente cargado. Revisar.
                                    </p>
                                </div>
                                <p class="mb-2 text-theme-xs text-gray-400">Sin cliente vinculado en RP Sistemas. Datos ingresados por el contacto:</p>
                                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-theme-xs">
                                    <dt class="text-gray-400">Nombre</dt>
                                    <dd class="text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.contacto_nombre || '—' }}</dd>
                                    <dt class="text-gray-400">Email</dt>
                                    <dd class="text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.contacto_email || '—' }}</dd>
                                    <dt class="text-gray-400">N° cliente</dt>
                                    <dd class="font-mono text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.contacto_numero_cliente || '—' }}</dd>
                                    <dt class="text-gray-400">Teléfono</dt>
                                    <dd class="text-gray-600 dark:text-gray-300">{{ observacionEnEdicion.contacto_telefono ?? '—' }}</dd>
                                </dl>
                            </template>
                        </div>

                        <!-- Productos -->
                        <div v-if="observacionEnEdicion.productos.length" class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="mb-2 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                                Productos ({{ observacionEnEdicion.productos.length }})
                            </p>
                            <div class="overflow-x-auto">
                                <table class="w-full text-theme-xs">
                                    <thead>
                                        <tr class="text-left text-gray-400">
                                            <th class="py-1.5 pr-4 font-medium">Código</th>
                                            <th class="py-1.5 pr-4 font-medium">Producto</th>
                                            <th class="py-1.5 pr-4 font-medium">Cantidad</th>
                                            <th class="py-1.5 pr-4 font-medium">Presentación</th>
                                            <th class="py-1.5 pr-4 font-medium">Lote</th>
                                            <th class="py-1.5 pr-4 font-medium">Vencimiento</th>
                                            <th class="py-1.5 pr-4 font-medium">Remito</th>
                                            <th class="py-1.5 font-medium">Comprobante</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        <tr v-for="p in observacionEnEdicion.productos" :key="p.id" class="text-gray-600 dark:text-gray-300">
                                            <td class="py-2 pr-4">{{ p.codigo ?? '—' }}</td>
                                            <td class="py-2 pr-4">{{ p.producto }}</td>
                                            <td class="py-2 pr-4">{{ p.cantidad_afectada }}</td>
                                            <td class="py-2 pr-4">{{ p.tipo_presentacion ? (presentaciones[p.tipo_presentacion] ?? p.tipo_presentacion) : '—' }}</td>
                                            <td class="py-2 pr-4">{{ p.lote }}</td>
                                            <td class="py-2 pr-4">{{ formatFecha(p.fecha_vencimiento) }}</td>
                                            <td class="py-2 pr-4">{{ p.numero_remito ?? '—' }}</td>
                                            <td class="py-2 capitalize">{{ p.tipo_comprobante ?? '—' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Adjuntos: del portal, del alta, o cargados acá mismo -->
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="mb-2 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                                Archivos adjuntos ({{ observacionEnEdicion.attachments?.length ?? 0 }})
                            </p>
                            <AdjuntosObservacion
                                compacto
                                :observacion-id="observacionEnEdicion.id"
                                :adjuntos="observacionEnEdicion.attachments ?? []"
                                :puede-editar="puedeEditar(observacionEnEdicion)"
                            />
                        </div>
                    </div>

                    <!-- Clasificación y asignación -->
                    <form @submit.prevent="guardar" class="space-y-6 lg:col-span-2 lg:border-l lg:border-gray-100 lg:pl-6 dark:lg:border-gray-800">
                        <FormSection title="Clasificación" :columns="1">
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
                                class="text-theme-xs text-warning-600 dark:text-warning-400"
                            >
                                Al guardar, la observación pasará a <strong>Clasificada</strong>.
                            </p>
                        </FormSection>

                        <FormSection title="Asignación" :columns="1">
                            <Select v-model="form.estado" label="Estado" :error="form.errors.estado">
                                <option v-for="(label, estado) in estadoLabels" :key="estado" :value="estado">
                                    {{ label }}
                                </option>
                            </Select>

                            <Select
                                v-model="form.sector_id"
                                label="Sector"
                                :hint="gentePorSector ?? undefined"
                                :error="form.errors.sector_id"
                                @change="alCambiarSector"
                            >
                                <option :value="null">— Sin asignar —</option>
                                <option v-for="sector in sectores" :key="sector.id" :value="sector.id">
                                    {{ sector.nombre }}
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
                            <Button variant="outline" @click="cerrarEdicion">Cancelar</Button>
                        </div>
                    </form>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>
