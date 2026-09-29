<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'
import BitacoraNoConformidad from '@/Components/BitacoraNoConformidad.vue'
import Contencion from '@/Components/NoConformidad/Contencion.vue'
import CausaRaiz from '@/Components/NoConformidad/CausaRaiz.vue'
import Investigacion from '@/Components/NoConformidad/Investigacion.vue'
import PlanDeAccion from '@/Components/NoConformidad/PlanDeAccion.vue'
import SeccionNc from '@/Components/NoConformidad/SeccionNc.vue'
import Verificacion from '@/Components/NoConformidad/Verificacion.vue'
import SelectorMultipleAsync, { type OpcionAsync } from '@/Components/SelectorMultipleAsync.vue'
import {
    condicionesCierreLabels,
    estadoVariant,
    numeroDe,
} from '@/lib/nc'
// ⚠️ Aliaseados: `@/lib/nc` ya exporta un `estadoVariant` con los estados del
// DESVÍO. Éstos son los de la OBSERVACIÓN vinculada, que es otro catálogo.
import {
    estadoLabels as labelObservacion,
    estadoVariant as variantObservacion,
} from '@/lib/estados'
import type { NoConformidad, PermisosNoConformidad } from '@/types'

const props = defineProps<{
    noConformidad: NoConformidad
    permisos: PermisosNoConformidad
    estados: Record<string, string>
    tiposDesvio: Record<string, string>
    factoresCausa: Record<string, string>
    estadosAccion: Record<string, string>
    resultadosEficacia: Record<string, string>
    /** Las cinco que traban, calculadas por el backend. La pantalla solo las lista. */
    condicionesCierre: Record<string, boolean>
    /** Lo que conviene mirar antes de cerrar, ya redactado. No traba. */
    advertenciasCierre: string[]
    usuarios: { id: number; name: string; apellido: string | null }[]
}>()

/**
 * Las siete secciones del formulario están **siempre presentes**, en orden. Lo
 * que cambia con la etapa es si se pueden editar: una sección bloqueada se ve
 * en gris con el motivo al lado.
 *
 * Esconderlas sería más limpio pero peor: quien abre una NC recién aprobada no
 * tendría forma de saber que después viene un plan de acción y una
 * verificación. El papel muestra el recorrido entero desde el minuto cero, y
 * esa es justamente la ventaja que se está copiando.
 */
const ETAPAS_CON_INVESTIGACION = ['abierta', 'plan_accion', 'en_implementacion', 'verificacion_eficacia', 'cerrada']

const enEtapa = (etapas: string[]) => etapas.includes(props.noConformidad.estado)

const seccionesHabilitadas = computed(() => ({
    investigacion: enEtapa(ETAPAS_CON_INVESTIGACION),
    /**
     * ⚠️ La contención es la ÚNICA sección que se escribe antes de aprobar, y
     * por eso tiene flag propio en vez de compartir el de investigación como
     * hasta el 29/9/2026. Cuando se detecta un desvío lo primero que se hace es
     * contenerlo; el papelerío viene después. Queda viva en todos los estados
     * menos los dos que terminan el caso sin tratarlo.
     */
    contencion: !enEtapa(['rechazada', 'cancelada']),
    plan: enEtapa(['plan_accion', 'en_implementacion', 'verificacion_eficacia', 'cerrada']),
    verificacion: enEtapa(['verificacion_eficacia', 'cerrada']),
}))

const MOTIVO_BLOQUEO = {
    investigacion: 'Se habilita cuando se apruebe la apertura',
    contencion: 'El desvío ya no está en curso',
    plan: 'Se habilita al completar la investigación y la causa raíz',
    verificacion: 'Se habilita al resolver todas las acciones del plan',
}

/**
 * Qué falta para poder cerrar (§4.8), en el orden del catálogo.
 *
 * Se arma desde `condicionesCierreLabels` y no desde las claves que manda el
 * backend para que el orden sea el de la checklist y no el del objeto PHP.
 */
const condiciones = computed(() =>
    Object.entries(condicionesCierreLabels).map(([clave, label]) => ({
        clave,
        label,
        cumplida: props.condicionesCierre[clave] === true,
    })),
)

const puedeCerrarse = computed(() => condiciones.value.every((c) => c.cumplida))

const formatFecha = (d: string | null) =>
    d ? new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'

const nombre = (u: { name: string; apellido: string | null } | null | undefined) =>
    u ? [u.name, u.apellido].filter(Boolean).join(' ') : '—'

const numero = computed(() => numeroDe(props.noConformidad))

/**
 * Errores que el backend manda con una clave que **no es un campo del
 * formulario**: `estado` (la transición no corresponde) y `cierre` (falta
 * cumplir alguna de las siete condiciones). `form.errors` está tipado por las
 * claves del `useForm`, así que estos salen de la bolsa compartida de Inertia.
 */
const page = usePage()
const erroresCompartidos = computed(() => (page.props.errors ?? {}) as Record<string, string>)

/**
 * Responsable del caso. Se puede dejar vacío: una NC sin responsable vuelve a
 * ser de Garantía de Calidad, que es el estado en el que nace.
 */
const responsableForm = useForm({ responsable_id: props.noConformidad.responsable_id })

const responsableCambio = computed(
    () => responsableForm.responsable_id !== props.noConformidad.responsable_id,
)

const guardarResponsable = () => {
    responsableForm.post(route('no-conformidades.responsable', props.noConformidad.id), {
        preserveScroll: true,
    })
}

/** Acciones simples (sin motivo): un POST y listo. */
const accion = useForm({})

const enviarAAprobacion = () => accion.post(route('no-conformidades.enviar', props.noConformidad.id), { preserveScroll: true })
const aprobar = () => accion.post(route('no-conformidades.aprobar', props.noConformidad.id), { preserveScroll: true })

/**
 * Devolver y rechazar **exigen motivo** (§4.3), así que van por un modal.
 * Es el mismo formulario para las dos: solo cambia a qué ruta postea.
 */
const decision = ref<'devolver' | 'rechazar' | 'reabrir' | 'cancelar' | null>(null)
const motivoForm = useForm({ motivo_decision: '' })

const abrirDecision = (cual: 'devolver' | 'rechazar') => {
    decision.value = cual
    motivoForm.reset()
    motivoForm.clearErrors()
}

const confirmarDecision = () => {
    if (!decision.value) return

    motivoForm.post(route(`no-conformidades.${decision.value}`, props.noConformidad.id), {
        preserveScroll: true,
        onSuccess: () => { decision.value = null },
    })
}

/**
 * Reabrir y cancelar comparten el modal de motivo con devolver/rechazar: las
 * cuatro son la misma pregunta ("¿por qué?") y cambia solo la ruta.
 */
const abrirExcepcional = (cual: 'reabrir' | 'cancelar') => {
    decision.value = cual
    motivoForm.reset()
    motivoForm.clearErrors()
}

const TITULOS_DECISION: Record<string, string> = {
    devolver: 'Devolver a borrador',
    rechazar: 'Rechazar la apertura',
    reabrir: 'Reabrir la No Conformidad',
    cancelar: 'Cancelar la No Conformidad',
}

/**
 * Observaciones vinculadas (§5).
 *
 * El v-model del selector es `string[]` porque el componente es genérico; el
 * backend recibe enteros y los castea. La comparación para habilitar el botón
 * se hace sobre conjuntos ordenados: reordenar la lista no es un cambio.
 */
const idsVinculados = () => (props.noConformidad.observaciones ?? []).map(o => String(o.id))

const observacionesForm = useForm<{ observaciones: string[] }>({ observaciones: idsVinculados() })

const observacionesIniciales = computed<OpcionAsync[]>(() =>
    (props.noConformidad.observaciones ?? []).map(o => ({
        id: String(o.id),
        label: `${o.numero} · ${o.titulo}`,
    })),
)

const mapearObservacion = (item: unknown): OpcionAsync => {
    const o = item as { id: number; numero: string; titulo: string }

    return { id: String(o.id), label: `${o.numero} · ${o.titulo}` }
}

const observacionesCambiaron = computed(() => {
    const antes = [...idsVinculados()].sort().join(',')
    const ahora = [...observacionesForm.observaciones].sort().join(',')

    return antes !== ahora
})

const guardarObservaciones = () => {
    observacionesForm.put(route('no-conformidades.observaciones', props.noConformidad.id), {
        preserveScroll: true,
    })
}

/** Cierre (§4.8): pide el resultado final, no un motivo. */
const mostrarCierre = ref(false)
const cierreForm = useForm({ resultado_final: '', observaciones_finales: '' })

const abrirCierre = () => {
    cierreForm.reset()
    cierreForm.clearErrors()
    mostrarCierre.value = true
}

const confirmarCierre = () => {
    cierreForm.post(route('no-conformidades.cerrar', props.noConformidad.id), {
        preserveScroll: true,
        onSuccess: () => { mostrarCierre.value = false },
    })
}
</script>

<template>
    <Head :title="`No Conformidad ${numero.texto}`" />
    <AppLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <Link
                        :href="route('no-conformidades.index')"
                        class="text-theme-xs text-brand-500 hover:underline dark:text-brand-300"
                    >
                        ← Volver a No Conformidades
                    </Link>
                    <div class="mt-1 flex flex-wrap items-center gap-2.5">
                        <h1
                            class="font-mono text-xl font-semibold text-gray-800 dark:text-white/90"
                            :class="{ 'italic font-normal text-gray-400 dark:text-gray-500': !numero.asignado }"
                        >
                            {{ numero.texto }}
                        </h1>
                        <Badge :variant="estadoVariant[noConformidad.estado] ?? 'slate'">
                            {{ estados[noConformidad.estado] }}
                        </Badge>
                    </div>
                    <!--
                        Que una NC no tenga número no es un dato faltante: recién
                        se asigna al aprobarla. Se explica en vez de mostrar un
                        guion mudo.
                    -->
                    <p v-if="!numero.asignado" class="mt-1 text-theme-xs text-gray-400">
                        El número se asigna cuando se apruebe la apertura.
                    </p>
                </div>

                <!-- Acciones del flujo -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- El mismo formulario, en papel: para archivar o mandar. -->
                    <a :href="route('no-conformidades.pdf', noConformidad.id)">
                        <Button variant="outline" type="button">Descargar informe</Button>
                    </a>

                    <Button
                        v-if="permisos.enviarAAprobacion"
                        variant="brand"
                        :disabled="accion.processing"
                        @click="enviarAAprobacion"
                    >
                        Enviar a aprobación
                    </Button>

                    <template v-if="permisos.aprobar">
                        <Button variant="brand" :disabled="accion.processing" @click="aprobar">Aprobar</Button>
                        <Button variant="outline" @click="abrirDecision('devolver')">Devolver</Button>
                        <Button variant="danger" @click="abrirDecision('rechazar')">Rechazar</Button>
                    </template>

                    <Button
                        v-if="permisos.gestionar && noConformidad.estado === 'verificacion_eficacia'"
                        variant="brand"
                        :disabled="!puedeCerrarse"
                        @click="abrirCierre"
                    >
                        Cerrar
                    </Button>

                    <Button
                        v-if="permisos.reabrir && noConformidad.estado === 'cerrada'"
                        variant="outline"
                        @click="abrirExcepcional('reabrir')"
                    >
                        Reabrir
                    </Button>

                    <!--
                        `permisos.cancelar` viene en true para el super-admin
                        siempre (lo habilita el bypass de Gate::before, que no
                        mira el estado). Que ya esté finalizada lo chequea el
                        backend; acá se oculta para no ofrecer un botón que
                        solo puede devolver un error.
                    -->
                    <Button
                        v-if="permisos.cancelar && !['cerrada', 'rechazada', 'cancelada'].includes(noConformidad.estado)"
                        variant="danger"
                        @click="abrirExcepcional('cancelar')"
                    >
                        Cancelar NC
                    </Button>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Columna principal -->
                <div class="space-y-6 lg:col-span-2">
                    <SeccionNc :numero="1" titulo="Descripción del problema">
                        <p class="whitespace-pre-line text-theme-sm text-gray-800 dark:text-white/90">
                            {{ noConformidad.descripcion }}
                        </p>
                    </SeccionNc>

                    <SeccionNc
                        :numero="2"
                        titulo="Investigación"
                        :habilitada="seccionesHabilitadas.investigacion"
                        :motivo-bloqueo="MOTIVO_BLOQUEO.investigacion"
                    >
                        <Investigacion
                            :no-conformidad="noConformidad"
                            :puede-gestionar="permisos.gestionar && seccionesHabilitadas.investigacion"
                        />
                    </SeccionNc>

                    <SeccionNc
                        :numero="3"
                        titulo="Acción inmediata de contención / corrección"
                        :habilitada="seccionesHabilitadas.contencion"
                        :motivo-bloqueo="MOTIVO_BLOQUEO.contencion"
                    >
                        <Contencion
                            :no-conformidad="noConformidad"
                            :usuarios="usuarios"
                            :puede-gestionar="permisos.contencion && seccionesHabilitadas.contencion"
                        />

                        <!--
                            Aprobar desde acá: quien decide la apertura lee la
                            acción inmediata que ya se tomó y resuelve en el
                            mismo lugar, sin volver al encabezado. Son los
                            mismos botones de arriba, con el mismo permiso.
                        -->
                        <div
                            v-if="permisos.aprobar"
                            class="mt-5 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-800"
                        >
                            <p class="mr-auto text-theme-sm text-gray-500 dark:text-gray-400">
                                ¿La acción inmediata alcanza? Podés resolver la apertura desde acá.
                            </p>
                            <Button variant="brand" :disabled="accion.processing" @click="aprobar">Aprobar</Button>
                            <Button variant="outline" @click="abrirDecision('devolver')">Devolver</Button>
                            <Button variant="danger" @click="abrirDecision('rechazar')">Rechazar</Button>
                        </div>
                    </SeccionNc>

                    <SeccionNc
                        :numero="4"
                        titulo="Análisis de causa raíz — CAPA"
                        :habilitada="seccionesHabilitadas.investigacion"
                        :motivo-bloqueo="MOTIVO_BLOQUEO.investigacion"
                    >
                        <CausaRaiz
                            :no-conformidad="noConformidad"
                            :factores-causa="factoresCausa"
                            :puede-gestionar="permisos.gestionar && seccionesHabilitadas.investigacion"
                        />
                    </SeccionNc>

                    <SeccionNc
                        :numero="5"
                        titulo="Plan de acción correctiva / preventiva"
                        :habilitada="seccionesHabilitadas.plan"
                        :motivo-bloqueo="MOTIVO_BLOQUEO.plan"
                    >
                        <PlanDeAccion
                            :no-conformidad="noConformidad"
                            :estados-accion="estadosAccion"
                            :usuarios="usuarios"
                            :puede-gestionar="permisos.gestionar && seccionesHabilitadas.plan"
                        />
                    </SeccionNc>

                    <SeccionNc
                        numero="6 y 7"
                        titulo="Seguimiento del plan y verificación de la eficacia"
                        :habilitada="seccionesHabilitadas.verificacion"
                        :motivo-bloqueo="MOTIVO_BLOQUEO.verificacion"
                    >
                        <Verificacion
                            :no-conformidad="noConformidad"
                            :resultados-eficacia="resultadosEficacia"
                            :puede-gestionar="permisos.gestionar"
                            :habilitada="seccionesHabilitadas.verificacion"
                        />
                    </SeccionNc>

                    <!-- Observaciones vinculadas (§5) -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                            Observaciones vinculadas
                        </p>
                        <ul v-if="noConformidad.observaciones?.length" class="mt-3 space-y-2">
                            <li
                                v-for="o in noConformidad.observaciones"
                                :key="o.id"
                                class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"
                            >
                                <Link
                                    :href="route('observaciones.show', o.id)"
                                    class="min-w-0 flex-1 truncate text-theme-sm text-brand-500 hover:underline dark:text-brand-300"
                                >
                                    <span class="font-mono">{{ o.numero }}</span> · {{ o.titulo }}
                                </Link>
                                <Badge v-if="o.estado" :variant="variantObservacion[o.estado] ?? 'slate'" size="sm">
                                    {{ labelObservacion[o.estado] ?? o.estado }}
                                </Badge>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-theme-sm text-gray-400">
                            Ninguna. Esta No Conformidad no se originó en una observación del sistema.
                        </p>

                        <!-- Vincular más. El backend lo gatea con `gestionar`, no
                             con `update`: el responsable del caso también puede. -->
                        <div v-if="permisos.gestionar" class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <SelectorMultipleAsync
                                v-model="observacionesForm.observaciones"
                                route="observaciones.buscar"
                                label="Vincular observaciones"
                                placeholder="Buscar por número o título…"
                                :mapear="mapearObservacion"
                                :inicial="observacionesIniciales"
                            />
                            <div class="mt-3 flex items-center gap-3">
                                <Button
                                    size="sm"
                                    :disabled="observacionesForm.processing || !observacionesCambiaron"
                                    @click="guardarObservaciones"
                                >
                                    Guardar vínculos
                                </Button>
                                <p v-if="observacionesCambiaron" class="text-theme-xs text-gray-400">
                                    Hay cambios sin guardar.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Evidencias sueltas -->
                    <div
                        v-if="noConformidad.attachments?.length"
                        class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
                    >
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Evidencias</p>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="a in noConformidad.attachments"
                                :key="a.id"
                                class="truncate text-theme-sm text-gray-600 dark:text-gray-300"
                            >
                                {{ a.original_name }}
                            </li>
                        </ul>
                    </div>

                    <!-- Resultado del cierre (§4.8): queda como registro. -->
                    <div
                        v-if="noConformidad.resultado_final"
                        class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
                    >
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Cierre</p>
                        <p class="mt-2 whitespace-pre-line text-theme-sm text-gray-800 dark:text-white/90">
                            {{ noConformidad.resultado_final }}
                        </p>
                        <p
                            v-if="noConformidad.observaciones_finales"
                            class="mt-2 whitespace-pre-line text-theme-sm text-gray-500 dark:text-gray-400"
                        >
                            {{ noConformidad.observaciones_finales }}
                        </p>
                    </div>

                    <BitacoraNoConformidad
                        :no-conformidad-id="noConformidad.id"
                        :entradas="noConformidad.historial ?? []"
                    />
                </div>

                <!-- Barra lateral -->
                <div class="space-y-6">
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Ficha</p>
                        <dl class="mt-3 space-y-3 text-theme-sm">
                            <div>
                                <dt class="text-theme-xs text-gray-400">Tipo de desvío</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ tiposDesvio[noConformidad.tipo_desvio] }}</dd>
                            </div>
                            <div>
                                <dt class="text-theme-xs text-gray-400">Motivo</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ noConformidad.motivo }}</dd>
                            </div>
                            <div>
                                <dt class="text-theme-xs text-gray-400">Sector involucrado</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ noConformidad.sector?.nombre ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-theme-xs text-gray-400">Detectada</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ formatFecha(noConformidad.fecha_deteccion) }}</dd>
                            </div>
                            <div v-if="noConformidad.cliente || noConformidad.proveedor">
                                <dt class="text-theme-xs text-gray-400">
                                    {{ noConformidad.cliente ? 'Cliente' : 'Proveedor' }}
                                </dt>
                                <dd class="text-gray-800 dark:text-white/90">
                                    {{ noConformidad.cliente?.razon_social ?? noConformidad.proveedor?.razon_social }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!--
                        Checklist de cierre (§4.8). Se muestra desde que la NC
                        entró al circuito: sirve como hoja de ruta, no solo como
                        traba al final.
                    -->
                    <div
                        v-if="seccionesHabilitadas.investigacion && noConformidad.estado !== 'cerrada'"
                        class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
                    >
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                            Para poder cerrar
                        </p>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="c in condiciones"
                                :key="c.clave"
                                class="flex items-start gap-2 text-theme-sm"
                                :class="c.cumplida
                                    ? 'text-gray-500 dark:text-gray-400'
                                    : 'text-gray-800 dark:text-white/90'"
                            >
                                <span
                                    class="mt-0.5 shrink-0"
                                    :class="c.cumplida ? 'text-success-500' : 'text-gray-300 dark:text-gray-600'"
                                >
                                    {{ c.cumplida ? '✓' : '○' }}
                                </span>
                                {{ c.label }}
                            </li>
                        </ul>
                    </div>

                    <!--
                        Responsable del caso. Garantía de Calidad hace
                        seguimiento y control, pero no es responsable de todas
                        las NC: quien figure acá la gestiona de punta a punta, y
                        puede pasarle la posta a otro sin pedirle permiso a nadie.
                    -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                            Responsable del caso
                        </p>

                        <form v-if="permisos.asignarResponsable" class="mt-3 space-y-3" @submit.prevent="guardarResponsable">
                            <Select
                                v-model="responsableForm.responsable_id"
                                :error="responsableForm.errors.responsable_id"
                            >
                                <option :value="null">Sin asignar — la gestiona Garantía de Calidad</option>
                                <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ nombre(u) }}</option>
                            </Select>
                            <div class="flex justify-end">
                                <Button
                                    variant="outline"
                                    type="submit"
                                    :disabled="responsableForm.processing || !responsableCambio"
                                >
                                    {{ noConformidad.responsable_id ? 'Derivar' : 'Asignar' }}
                                </Button>
                            </div>
                        </form>

                        <p v-else class="mt-2 text-theme-sm text-gray-800 dark:text-white/90">
                            {{ noConformidad.responsable
                                ? nombre(noConformidad.responsable)
                                : 'Sin asignar — la gestiona Garantía de Calidad' }}
                        </p>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Trazabilidad</p>
                        <dl class="mt-3 space-y-3 text-theme-sm">
                            <div>
                                <dt class="text-theme-xs text-gray-400">Creada por</dt>
                                <dd class="text-gray-800 dark:text-white/90">{{ nombre(noConformidad.creador) }}</dd>
                            </div>
                            <div v-if="noConformidad.aprobada_at">
                                <dt class="text-theme-xs text-gray-400">Aprobada por</dt>
                                <dd class="text-gray-800 dark:text-white/90">
                                    {{ nombre(noConformidad.aprobada_por_usuario) }}
                                    <span class="text-gray-400">· {{ formatFecha(noConformidad.aprobada_at) }}</span>
                                </dd>
                            </div>
                            <div v-if="noConformidad.cerrada_at">
                                <dt class="text-theme-xs text-gray-400">Cerrada por</dt>
                                <dd class="text-gray-800 dark:text-white/90">
                                    {{ nombre(noConformidad.cerrada_por_usuario) }}
                                    <span class="text-gray-400">· {{ formatFecha(noConformidad.cerrada_at) }}</span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Devolver / rechazar (§4.3) y reabrir / cancelar (§4.8 y §6): las
             cuatro exigen motivo y comparten formulario. -->
        <Modal
            :show="decision !== null"
            :title="decision ? TITULOS_DECISION[decision] : ''"
            @close="decision = null"
        >
            <form class="space-y-4" @submit.prevent="confirmarDecision">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                    <template v-if="decision === 'devolver'">
                        Vuelve a borrador para que su creador la corrija.
                    </template>
                    <template v-else-if="decision === 'rechazar'">
                        Queda registrada como antecedente: una No Conformidad rechazada no se borra.
                    </template>
                    <template v-else-if="decision === 'reabrir'">
                        Vuelve a <strong>Abierta / En investigación</strong> y se borra la fecha de cierre.
                        Conserva su número y su aprobación original.
                    </template>
                    <template v-else>
                        Queda cerrada sin haberse resuelto. No se borra: el caso y su bitácora siguen consultables.
                    </template>
                </p>

                <Textarea
                    v-model="motivoForm.motivo_decision"
                    label="Motivo"
                    required
                    :rows="4"
                    :error="motivoForm.errors.motivo_decision"
                />

                <p v-if="erroresCompartidos.estado" class="text-theme-sm text-error-500">
                    {{ erroresCompartidos.estado }}
                </p>

                <div class="flex justify-end gap-3">
                    <Button variant="outline" type="button" @click="decision = null">Volver</Button>
                    <Button
                        :variant="decision === 'rechazar' || decision === 'cancelar' ? 'danger' : 'primary'"
                        type="submit"
                        :disabled="motivoForm.processing"
                    >
                        Confirmar
                    </Button>
                </div>
            </form>
        </Modal>

        <!-- Cierre (§4.8): pide el resultado final, no un motivo. -->
        <Modal :show="mostrarCierre" title="Cerrar la No Conformidad" size="lg" @close="mostrarCierre = false">
            <form class="space-y-4" @submit.prevent="confirmarCierre">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                    Una vez cerrada queda bloqueada para edición. Solo un administrador puede reabrirla.
                </p>

                <!-- Avisos que NO traban: cerrar sin evidencias, o dejando
                     observaciones abiertas, está permitido. Se dice y se sigue. -->
                <div
                    v-if="advertenciasCierre.length"
                    class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/30 dark:bg-warning-500/10"
                >
                    <p class="text-theme-xs font-medium text-warning-700 dark:text-warning-400">
                        Para tener en cuenta
                    </p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        <li
                            v-for="aviso in advertenciasCierre"
                            :key="aviso"
                            class="text-theme-sm text-warning-700 dark:text-warning-400"
                        >
                            {{ aviso }}
                        </li>
                    </ul>
                    <p class="mt-1.5 text-theme-xs text-warning-600 dark:text-warning-400/80">
                        No impide cerrar.
                    </p>
                </div>

                <Textarea
                    v-model="cierreForm.resultado_final"
                    label="Resultado final"
                    required
                    :rows="4"
                    hint="Qué quedó resuelto y cómo se comprobó."
                    :error="cierreForm.errors.resultado_final"
                />

                <Textarea
                    v-model="cierreForm.observaciones_finales"
                    label="Observaciones finales"
                    :rows="3"
                    :error="cierreForm.errors.observaciones_finales"
                />

                <!-- El backend dice CUÁL condición falta, no un "no se puede" mudo. -->
                <p v-if="erroresCompartidos.cierre" class="text-theme-sm text-error-500">
                    {{ erroresCompartidos.cierre }}
                </p>

                <div class="flex justify-end gap-3">
                    <Button variant="outline" type="button" @click="mostrarCierre = false">Volver</Button>
                    <Button type="submit" :disabled="cierreForm.processing">Cerrar No Conformidad</Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
