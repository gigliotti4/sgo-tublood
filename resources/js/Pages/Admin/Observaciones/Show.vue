<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Badge.vue'
import Icon from '@/Components/Icon.vue'
import type { Observacion } from '@/types'

const props = defineProps<{
    observacion: Observacion
    presentaciones: Record<string, string>
    tipoLabels: Record<string, string>
    prioridades: Record<string, string>
    /** Viene de ObservacionPolicy: solo el responsable asignado (o super-admin). */
    puedeEditar: boolean
}>()

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

const formatFecha = (d: string | null) =>
    d ? new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'

const formatSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// El cliente ingresó un N° que no matcheó ningún cliente cargado (dato para revisar).
const clienteNoEncontrado =
    !props.observacion.cliente && !!props.observacion.contacto_numero_cliente

/** Las claves del bloque JSON vienen en snake_case desde config/incidencias.php. */
const humanizar = (clave: string) =>
    clave.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())
</script>

<template>
    <Head :title="`Observación ${observacion.numero}`" />

    <AppLayout>
        <!-- Encabezado -->
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-3">
                    <Link :href="route('observaciones.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
                    <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ observacion.titulo }}</h1>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="font-mono text-theme-sm text-gray-500 dark:text-gray-400">{{ observacion.numero }}</span>
                    <Badge variant="slate">{{ observacion.origen === 'interna' ? 'Interna' : 'Externa' }}</Badge>
                    <Badge :variant="estadoVariant[observacion.estado] ?? 'slate'">
                        {{ estadoLabels[observacion.estado] ?? observacion.estado }}
                    </Badge>
                    <Badge v-if="observacion.tecnovigilancia" variant="red">Tecnovigilancia</Badge>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <!-- Descarga directa, no navegación de Inertia: por eso <a> y no <Link>. -->
                <a
                    :href="route('observaciones.pdf', observacion.id)"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                >
                    <Icon name="document" class="h-4 w-4" />
                    Descargar PDF
                </a>
                <Link
                    v-if="puedeEditar"
                    :href="route('observaciones.index')"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600"
                >
                    <Icon name="pencil" class="h-4 w-4" />
                    Editar en el listado
                </Link>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Columna principal -->
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Tipo</dt>
                            <dd class="mt-0.5 text-sm text-gray-800 dark:text-white/90">{{ tipoLabels[observacion.tipo] ?? observacion.tipo }}</dd>
                        </div>
                        <div>
                            <dt class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Creada</dt>
                            <dd class="mt-0.5 text-sm text-gray-800 dark:text-white/90">{{ formatFecha(observacion.created_at) }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4">
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Descripción</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ observacion.descripcion }}</p>
                    </div>
                </div>

                <!-- Datos específicos (bloque variable por tipo de incidencia) -->
                <div
                    v-if="observacion.datos_especificos && Object.keys(observacion.datos_especificos).length"
                    class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
                >
                    <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Datos específicos</p>
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div v-for="(valor, clave) in observacion.datos_especificos" :key="clave">
                            <dt class="text-theme-xs text-gray-400">{{ humanizar(String(clave)) }}</dt>
                            <dd class="mt-0.5 text-sm text-gray-800 dark:text-white/90">{{ valor === null || valor === '' ? '—' : valor }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Productos -->
                <div v-if="observacion.productos.length" class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                        Productos ({{ observacion.productos.length }})
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
                                <tr v-for="p in observacion.productos" :key="p.id" class="text-gray-600 dark:text-gray-300">
                                    <td class="py-2 pr-4">{{ p.codigo ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ p.producto }}</td>
                                    <td class="py-2 pr-4">{{ p.cantidad_afectada }}</td>
                                    <td class="py-2 pr-4">{{ p.tipo_presentacion ? (presentaciones[p.tipo_presentacion] ?? p.tipo_presentacion) : '—' }}</td>
                                    <td class="py-2 pr-4">{{ p.lote }}</td>
                                    <td class="py-2 pr-4">{{ formatFecha(p.fecha_vencimiento) }}</td>
                                    <td class="py-2 pr-4">{{ p.numero_remito }}</td>
                                    <td class="py-2 capitalize">{{ p.tipo_comprobante }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Adjuntos -->
                <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                        Archivos adjuntos ({{ observacion.attachments?.length ?? 0 }})
                    </p>
                    <ul v-if="observacion.attachments?.length" class="space-y-2">
                        <li v-for="a in observacion.attachments" :key="a.id">
                            <a
                                :href="route('observaciones.archivos.download', [observacion.id, a.id])"
                                class="flex items-center gap-2 text-theme-sm text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                            >
                                <Icon name="paperclip" class="h-4 w-4 shrink-0 text-gray-400" />
                                <span class="truncate">{{ a.original_name }}</span>
                                <span class="shrink-0 text-theme-xs text-gray-400">({{ formatSize(a.size) }})</span>
                            </a>
                        </li>
                    </ul>
                    <p v-else class="text-theme-sm text-gray-400">Sin archivos adjuntos.</p>
                </div>
            </div>

            <!-- Barra lateral -->
            <div class="space-y-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Gestión</p>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-theme-xs text-gray-400">Responsable</dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ observacion.responsable?.name ?? 'Sin asignar' }}</dd>
                        </div>
                        <div>
                            <dt class="text-theme-xs text-gray-400">Sector</dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ observacion.sector?.nombre ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-theme-xs text-gray-400">Prioridad</dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-white/90">
                                {{ observacion.prioridad ? (prioridades[observacion.prioridad] ?? observacion.prioridad) : 'Sin clasificar' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-theme-xs text-gray-400">Tipo de caso</dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ observacion.tipo_caso ?? 'Sin clasificar' }}</dd>
                        </div>
                        <div>
                            <dt class="text-theme-xs text-gray-400">Vence</dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-white/90">
                                {{ formatFecha(observacion.vence_at) }}
                                <span v-if="!observacion.vence_at" class="block text-theme-xs text-gray-400">
                                    El plazo arranca al asignar un responsable con sector.
                                </span>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Cliente -->
                <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Cliente</p>
                    <template v-if="observacion.cliente">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ observacion.cliente.razon_social }}</p>
                        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-theme-xs">
                            <dt class="text-gray-400">N° cliente</dt>
                            <dd class="font-mono text-gray-600 dark:text-gray-300">{{ observacion.cliente.numero }}</dd>
                            <dt class="text-gray-400">Email</dt>
                            <dd class="break-all text-gray-600 dark:text-gray-300">{{ observacion.cliente.mail ?? '—' }}</dd>
                            <dt class="text-gray-400">Teléfono</dt>
                            <dd class="text-gray-600 dark:text-gray-300">{{ observacion.cliente.telefono ?? '—' }}</dd>
                        </dl>
                    </template>
                    <template v-else>
                        <div v-if="clienteNoEncontrado" class="mb-3 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 dark:border-warning-500/30 dark:bg-warning-500/15">
                            <p class="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
                                N° ingresado <span class="font-mono">{{ observacion.contacto_numero_cliente }}</span> — no coincide con ningún cliente cargado. Revisar.
                            </p>
                        </div>
                        <p class="mb-2 text-theme-xs text-gray-400">Datos ingresados por el contacto:</p>
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-theme-xs">
                            <dt class="text-gray-400">Nombre</dt>
                            <dd class="text-gray-600 dark:text-gray-300">{{ observacion.contacto_nombre || '—' }}</dd>
                            <dt class="text-gray-400">Email</dt>
                            <dd class="break-all text-gray-600 dark:text-gray-300">{{ observacion.contacto_email || '—' }}</dd>
                            <dt class="text-gray-400">N° cliente</dt>
                            <dd class="font-mono text-gray-600 dark:text-gray-300">{{ observacion.contacto_numero_cliente || '—' }}</dd>
                            <dt class="text-gray-400">Teléfono</dt>
                            <dd class="text-gray-600 dark:text-gray-300">{{ observacion.contacto_telefono ?? '—' }}</dd>
                        </dl>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
