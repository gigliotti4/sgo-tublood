<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'
import { erroresDeArchivos } from '@/lib/errores'
import type { Observacion, ProrrogaObservacion } from '@/types'

/**
 * Plazo de la observación y sus prórrogas.
 *
 * Muestra DOS plazos (antigüedad desde la apertura y vencimiento vigente) porque
 * reasignar ya no reinicia el reloj, y deja pedir una prórroga (el responsable)
 * o resolverla (quien tiene el permiso `observaciones.prorrogas.autorizar`).
 * La autorización real la hace el backend; los booleanos solo deciden qué se ofrece.
 *
 * "En prórroga" no es un estado: es derivado de `prorroga_hasta`.
 */
const props = defineProps<{
    observacion: Observacion
    motivos: Record<string, string>
    /** Viene de `update`: el responsable asignado (o super-admin). */
    puedePedir: boolean
    /** Viene de `autorizarProrroga`: el permiso `observaciones.prorrogas.autorizar`. */
    puedeAutorizar: boolean
}>()

const ESTADOS_FINALES = ['cerrada', 'cancelada']

const fecha = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'

const abierta = computed(() => !ESTADOS_FINALES.includes(props.observacion.estado))

/** Días corridos desde que entró: no depende de quién la tenga ni de cuántas veces se reasignó. */
const antiguedad = computed(() =>
    Math.max(0, Math.floor((Date.now() - new Date(props.observacion.created_at).getTime()) / 86400000)),
)

const vigente = computed(() => props.observacion.prorroga_hasta ?? props.observacion.vence_at)

const enProrroga = computed(() =>
    abierta.value && !!props.observacion.prorroga_hasta && new Date(props.observacion.prorroga_hasta).getTime() > Date.now(),
)

const prorrogas = computed(() => props.observacion.prorrogas ?? [])
const pendiente = computed(() => prorrogas.value.find(p => p.estado === 'pendiente') ?? null)

const nombre = (u: { name: string; apellido: string | null } | null) =>
    u ? [u.name, u.apellido].filter(Boolean).join(' ') : '—'

const variante = (p: ProrrogaObservacion) =>
    p.estado === 'aprobada' ? 'emerald' : p.estado === 'rechazada' ? 'red' : 'amber'

const etiqueta = (p: ProrrogaObservacion) =>
    ({ pendiente: 'Pendiente', aprobada: 'Aprobada', rechazada: 'Rechazada' })[p.estado]

// ── Pedir ────────────────────────────────────────────────────────────────────

const mostrarPedir = ref(false)

const pedir = useForm({
    fecha_pedida: '',
    motivo: '',
    que_se_hace: '',
    justificacion: '',
    archivos: [] as File[],
})

const erroresArchivos = computed(() => erroresDeArchivos(pedir.errors as Record<string, string>, 'archivos'))

const agregarArchivos = (e: Event) => {
    pedir.archivos = [...pedir.archivos, ...Array.from((e.target as HTMLInputElement).files ?? [])]
}

const enviarPedido = () => {
    pedir.post(route('observaciones.prorrogas.store', props.observacion.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            mostrarPedir.value = false
            pedir.reset()
        },
    })
}

// ── Resolver ─────────────────────────────────────────────────────────────────

const resolviendo = ref<ProrrogaObservacion | null>(null)

const decision = useForm({
    decision: 'aprobar',
    fecha_autorizada: '',
    comentario: '',
})

const abrirResolver = (p: ProrrogaObservacion) => {
    decision.reset()
    decision.clearErrors()
    // Arranca en la fecha pedida; "aprobar con otra fecha" es cambiarla.
    decision.fecha_autorizada = p.fecha_pedida.slice(0, 10)
    resolviendo.value = p
}

const enviarDecision = () => {
    if (!resolviendo.value) return

    decision.put(route('observaciones.prorrogas.decidir', { observacion: props.observacion.id, prorroga: resolviendo.value.id }), {
        preserveScroll: true,
        onSuccess: () => { resolviendo.value = null },
    })
}
</script>

<template>
    <div class="space-y-4">
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-theme-xs text-gray-400">Antigüedad</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">
                    {{ antiguedad }} día{{ antiguedad === 1 ? '' : 's' }}
                    <span class="block text-theme-xs text-gray-400">Desde la apertura, aunque se reasigne.</span>
                </dd>
            </div>
            <div>
                <dt class="text-theme-xs text-gray-400">Vence</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">
                    {{ fecha(vigente) }}
                    <Badge v-if="enProrroga" variant="blue" class="ml-1">En prórroga</Badge>
                    <span v-if="prorrogas.some(p => p.estado === 'aprobada') && observacion.vence_at" class="block text-theme-xs text-gray-400">
                        Plazo original: {{ fecha(observacion.vence_at) }}
                    </span>
                    <span v-if="!vigente" class="block text-theme-xs text-gray-400">
                        Todavía no tiene plazo: se fija al asignar un responsable con plazo cargado.
                    </span>
                </dd>
            </div>
        </dl>

        <div v-if="puedePedir && abierta && !pendiente">
            <Button variant="outline" type="button" @click="mostrarPedir = true">Pedir prórroga</Button>
        </div>

        <ul v-if="prorrogas.length" class="space-y-3">
            <li
                v-for="p in prorrogas"
                :key="p.id"
                class="rounded-lg border border-gray-100 p-3 text-theme-sm dark:border-gray-800"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <Badge :variant="variante(p)">{{ etiqueta(p) }}</Badge>
                        <span class="font-medium text-gray-800 dark:text-white/90">
                            Hasta el {{ fecha(p.estado === 'aprobada' ? p.fecha_autorizada : p.fecha_pedida) }}
                        </span>
                    </div>
                    <span class="text-theme-xs text-gray-400">{{ nombre(p.solicitante) }} · {{ fecha(p.created_at) }}</span>
                </div>
                <p class="mt-2 text-gray-600 dark:text-gray-300">
                    <span class="font-medium">{{ motivos[p.motivo] ?? p.motivo }}</span> · {{ p.que_se_hace }}
                </p>
                <p class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ p.justificacion }}</p>
                <p v-if="p.estado === 'aprobada' && p.fecha_autorizada && p.fecha_autorizada.slice(0, 10) !== p.fecha_pedida.slice(0, 10)" class="mt-1 text-theme-xs text-gray-400">
                    Se pidió hasta el {{ fecha(p.fecha_pedida) }}.
                </p>
                <p v-if="p.estado !== 'pendiente'" class="mt-2 text-theme-xs text-gray-400">
                    {{ nombre(p.decisor) }} · {{ fecha(p.decidida_at) }}
                    <template v-if="p.comentario"> — {{ p.comentario }}</template>
                </p>
                <div v-if="p.estado === 'pendiente' && puedeAutorizar" class="mt-3">
                    <Button type="button" @click="abrirResolver(p)">Resolver</Button>
                </div>
            </li>
        </ul>

        <Modal :show="mostrarPedir" title="Pedir prórroga" @close="mostrarPedir = false">
            <form class="space-y-4" @submit.prevent="enviarPedido">
                <Input label="Vencimiento actual" :model-value="fecha(vigente)" disabled />
                <InputFecha v-model="pedir.fecha_pedida" label="Nueva fecha pedida" required :error="pedir.errors.fecha_pedida" />
                <Select v-model="pedir.motivo" label="Motivo" required :error="pedir.errors.motivo">
                    <option value="" disabled>— Seleccionar —</option>
                    <option v-for="(label, clave) in motivos" :key="clave" :value="clave">{{ label }}</option>
                </Select>
                <Input
                    v-model="pedir.que_se_hace"
                    label="Qué se está haciendo"
                    hint="Ej.: ensayo de llenado sobre 20 tubos del lote"
                    required
                    :error="pedir.errors.que_se_hace"
                />
                <Textarea v-model="pedir.justificacion" label="Justificación" :rows="4" required :error="pedir.errors.justificacion" />
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Adjuntos</label>
                    <input type="file" multiple class="block w-full text-theme-sm text-gray-600 dark:text-gray-300" @change="agregarArchivos" />
                    <ul v-if="pedir.archivos.length" class="mt-2 space-y-1 text-theme-xs">
                        <li v-for="(f, i) in pedir.archivos" :key="i" :class="erroresArchivos[i] ? 'text-error-600' : 'text-gray-500'">
                            {{ f.name }}<template v-if="erroresArchivos[i]"> — {{ erroresArchivos[i] }}</template>
                        </li>
                    </ul>
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" type="button" @click="mostrarPedir = false">Cancelar</Button>
                    <Button type="submit" :disabled="pedir.processing">{{ pedir.processing ? 'Enviando...' : 'Pedir prórroga' }}</Button>
                </div>
            </form>
        </Modal>

        <Modal :show="resolviendo !== null" title="Resolver prórroga" @close="resolviendo = null">
            <form v-if="resolviendo" class="space-y-4" @submit.prevent="enviarDecision">
                <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                    {{ nombre(resolviendo.solicitante) }} pide hasta el <b>{{ fecha(resolviendo.fecha_pedida) }}</b>
                    ({{ motivos[resolviendo.motivo] ?? resolviendo.motivo }}).
                </p>
                <Select v-model="decision.decision" label="Decisión" required :error="decision.errors.decision">
                    <option value="aprobar">Aprobar</option>
                    <option value="rechazar">Rechazar</option>
                </Select>
                <InputFecha
                    v-if="decision.decision === 'aprobar'"
                    v-model="decision.fecha_autorizada"
                    label="Fecha autorizada"
                    hint="Puede ser distinta de la pedida."
                    required
                    :error="decision.errors.fecha_autorizada"
                />
                <Textarea v-model="decision.comentario" label="Comentario" :rows="3" hint="Queda en la bitácora." :error="decision.errors.comentario" />
                <div class="flex justify-end gap-2">
                    <Button variant="outline" type="button" @click="resolviendo = null">Cancelar</Button>
                    <Button type="submit" :disabled="decision.processing">{{ decision.processing ? 'Guardando...' : 'Guardar decisión' }}</Button>
                </div>
            </form>
        </Modal>
    </div>
</template>
