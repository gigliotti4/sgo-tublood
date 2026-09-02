<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AdjuntosObservacion from '@/Components/AdjuntosObservacion.vue'
import BitacoraObservacion from '@/Components/BitacoraObservacion.vue'
import Badge from '@/Components/Badge.vue'
import Icon from '@/Components/Icon.vue'
import type { Observacion } from '@/types'
import { proveedorDeProducto } from '@/lib/productos'
import { partidaDeProducto, vencimientoDiscrepa } from '@/lib/partidas'

const props = defineProps<{
    observacion: Observacion
    presentaciones: Record<string, string>
    tipoLabels: Record<string, string>
    prioridades: Record<string, string>
    /** Viene de ObservacionPolicy: solo el responsable asignado (o super-admin). */
    puedeEditar: boolean
    /** Más amplio que `puedeEditar`: incluye a los usuarios a notificar. */
    puedeComentar: boolean
}>()

const estadoLabels: Record<string, string> = {
    pendiente_clasificacion: 'Pendiente de clasificación',
    clasificada: 'Clasificada',
    en_proceso: 'En proceso',
    derivada: 'Derivada',
    cerrada: 'Cerrada',
    cancelada: 'Cancelada',
}

const estadoVariant: Record<string, 'amber' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'slate' | 'red'> = {
    pendiente_clasificacion: 'amber',
    clasificada: 'blue',
    en_proceso: 'indigo',
    derivada: 'purple',
    cerrada: 'emerald',
    cancelada: 'red',
}

const formatFecha = (d: string | null) =>
    d ? new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'

// El cliente ingresó un N° que no matcheó ningún cliente cargado (dato para revisar).
const clienteNoEncontrado =
    !props.observacion.cliente && !!props.observacion.contacto_numero_cliente

/**
 * Productos con lote declarado: los únicos que se pueden rastrear contra el
 * padrón de partidas. Si ninguno lo trae, el bloque no se muestra.
 */
const productosConLote = computed(() =>
    props.observacion.productos.filter(p => p.lote?.trim()),
)

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
                    <Badge v-if="observacion.prioridad === 'critica'" variant="red">Crítica</Badge>
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

        <!-- Borrada: solo se llega acá desde la pantalla de Bajas -->
        <div
            v-if="observacion.deleted_at"
            class="mb-6 rounded-2xl border border-error-200 bg-error-50 p-4 dark:border-error-500/30 dark:bg-error-500/15"
        >
            <p class="text-sm font-medium text-error-700 dark:text-error-400">
                Esta observación está borrada ({{ formatFecha(observacion.deleted_at) }}).
            </p>
            <p v-if="observacion.baja?.nota" class="mt-1 whitespace-pre-line text-theme-sm text-error-600 dark:text-error-400/80">
                Motivo: {{ observacion.baja.nota }}
            </p>
            <p class="mt-1 text-theme-xs text-error-600 dark:text-error-400/80">
                No se puede editar ni comentar. Restaurarla se hace desde <Link :href="route('bajas.index')" class="underline">Bajas</Link>.
            </p>
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
                                    <th class="py-1.5 pr-4 font-medium">Artículo</th>
                                    <th class="py-1.5 pr-4 font-medium">PM</th>
                                    <th class="py-1.5 pr-4 font-medium">Proveedor</th>
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
                                    <td class="py-2 pr-4">{{ p.articulo?.descripcion ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ p.articulo?.pm ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <span :class="proveedorDeProducto(p).atribuido ? '' : 'italic text-gray-400'">
                                            {{ proveedorDeProducto(p).texto }}
                                        </span>
                                    </td>
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

                <!--
                    Trazabilidad contra el padrón de partidas (espejo de COMPRO_PARTIDAS
                    del ERP). Va aparte y no como columnas de la tabla de arriba porque
                    son dos fuentes distintas: arriba está lo que declaró el cliente,
                    acá lo que sabe el ERP de ese lote. Verlas mezcladas escondía
                    justamente la discrepancia, que es el dato que importa.
                -->
                <div v-if="productosConLote.length" class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                        Trazabilidad de partida
                    </p>
                    <p class="mb-3 text-theme-xs text-gray-400">
                        Lo que el padrón del ERP sabe de los lotes declarados.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-theme-xs">
                            <thead>
                                <tr class="text-left text-gray-400">
                                    <th class="py-1.5 pr-4 font-medium">Lote declarado</th>
                                    <th class="py-1.5 pr-4 font-medium">Partida</th>
                                    <th class="py-1.5 pr-4 font-medium">Artículo del padrón</th>
                                    <th class="py-1.5 pr-4 font-medium">Proveedor de la partida</th>
                                    <th class="py-1.5 pr-4 font-medium">Vence (padrón)</th>
                                    <th class="py-1.5 pr-4 font-medium">Ubicación</th>
                                    <th class="py-1.5 font-medium">Último movimiento</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <tr v-for="p in productosConLote" :key="p.id" class="text-gray-600 dark:text-gray-300">
                                    <td class="py-2 pr-4">{{ p.lote }}</td>
                                    <td class="py-2 pr-4">
                                        <span :class="partidaDeProducto(p).atribuida ? '' : 'italic text-gray-400'">
                                            {{ partidaDeProducto(p).texto }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">{{ p.partida?.articulo?.descripcion ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ p.partida?.proveedor?.razon_social ?? '—' }}</td>
                                    <td class="py-2 pr-4">
                                        <span v-if="p.partida?.fecha_vencimiento" class="inline-flex items-center gap-1.5">
                                            {{ formatFecha(p.partida.fecha_vencimiento) }}
                                            <!-- El cliente declaró otro vencimiento: uno de los dos está mal. -->
                                            <Badge v-if="vencimientoDiscrepa(p)" variant="amber">
                                                ≠ declarado
                                            </Badge>
                                        </span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="py-2 pr-4">{{ p.partida?.ubicacion ?? '—' }}</td>
                                    <td class="py-2">{{ formatFecha(p.partida?.ultimo_movimiento_at ?? null) }}</td>
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
                    <AdjuntosObservacion
                        :observacion-id="observacion.id"
                        :adjuntos="observacion.attachments ?? []"
                        :puede-editar="puedeEditar"
                    />
                </div>

                <!-- Bitácora -->
                <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Bitácora</p>
                    <BitacoraObservacion
                        :observacion-id="observacion.id"
                        :entradas="observacion.historial ?? []"
                        :puede-editar="puedeComentar"
                    />
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
                        <div v-if="observacion.notificados?.length">
                            <dt class="text-theme-xs text-gray-400">A notificar</dt>
                            <dd class="mt-0.5 text-gray-800 dark:text-white/90">
                                {{ observacion.notificados.map(u => [u.name, u.apellido].filter(Boolean).join(' ')).join(', ') }}
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
