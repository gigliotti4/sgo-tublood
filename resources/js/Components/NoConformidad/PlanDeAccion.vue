<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'
import { usePermissions } from '@/composables/usePermissions'
import type { NoConformidad, NonConformityAction } from '@/types'

/**
 * Sección 5 — Plan de acción correctiva / preventiva (§4.5 y §4.6).
 *
 * Es lo que el instructivo llama CAPA: no hay una entidad aparte, el plan **es**
 * el CAPA. Hasta el 24/9/2026 cada acción se clasificaba en corrección /
 * correctiva / preventiva; se sacó porque el formulario en papel no tiene esa
 * columna y el cliente confirmó que no hacen la distinción.
 */
const props = defineProps<{
    noConformidad: NoConformidad
    estadosAccion: Record<string, string>
    usuarios: { id: number; name: string; apellido: string | null }[]
    puedeGestionar: boolean
}>()

const { user } = usePermissions()

const acciones = computed(() => props.noConformidad.acciones ?? [])

/**
 * Quién puede tocar ESTA acción. Espejo de `NonConformityActionPolicy`: el
 * responsable del renglón, el del caso, o Calidad. Se calcula acá comparando
 * contra `user.id`, mismo patrón que `puedeEditar(o)` en el listado de
 * Observaciones — la regla que manda es la del backend.
 */
const puedeGestionarAccion = (a: NonConformityAction) =>
    props.puedeGestionar || a.responsable_id === user.value?.id

const form = useForm({
    descripcion: '',
    responsable_id: null as number | null,
    fecha_prevista: null as string | null,
    evidencia_requerida: '',
    observaciones: '',
})

/**
 * La fecha en que se va a verificar la eficacia. Form propio y no parte del
 * alta de una acción: es un dato del plan entero, no de cada renglón.
 */
const verificacionForm = useForm({
    fecha_verificacion_prevista: props.noConformidad.fecha_verificacion_prevista ?? null,
})

const fechaVerificacionCambio = computed(() =>
    (verificacionForm.fecha_verificacion_prevista ?? null)
        !== (props.noConformidad.fecha_verificacion_prevista ?? null),
)

/** Para quien solo mira. "Sin definir" y no un guion: es algo que falta cargar. */
const fechaVerificacionTexto = computed(() => {
    const f = props.noConformidad.fecha_verificacion_prevista

    return f ? new Date(`${f}T00:00:00`).toLocaleDateString('es-AR') : 'Sin definir'
})

const guardarFechaVerificacion = () => {
    verificacionForm.put(route('no-conformidades.verificacion-prevista', props.noConformidad.id), {
        preserveScroll: true,
    })
}

const mostrarAlta = ref(false)
// `null` = estamos dando de alta; un id = estamos editando esa acción.
const editandoId = ref<number | null>(null)

const abrirAlta = () => {
    editandoId.value = null
    form.defaults({
        descripcion: '',
        responsable_id: null,
        fecha_prevista: null,
        evidencia_requerida: '',
        observaciones: '',
    })
    form.reset()
    form.clearErrors()
    mostrarAlta.value = true
}

const abrirEdicion = (a: NonConformityAction) => {
    editandoId.value = a.id
    form.defaults({
        descripcion: a.descripcion,
        responsable_id: a.responsable_id,
        fecha_prevista: a.fecha_prevista,
        evidencia_requerida: a.evidencia_requerida ?? '',
        observaciones: a.observaciones ?? '',
    })
    form.reset()
    form.clearErrors()
    mostrarAlta.value = true
}

const guardar = () => {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => { mostrarAlta.value = false },
    }

    if (editandoId.value !== null) {
        form.put(route('no-conformidades.acciones.update', [props.noConformidad.id, editandoId.value]), opciones)

        return
    }

    form.post(route('no-conformidades.acciones.store', props.noConformidad.id), opciones)
}

const quitar = useForm({})
const quitarAccion = (id: number) => {
    quitar.delete(route('no-conformidades.acciones.destroy', [props.noConformidad.id, id]), {
        preserveScroll: true,
    })
}

const avanzar = useForm({})
const avanzarAImplementacion = () => {
    avanzar.post(route('no-conformidades.implementacion', props.noConformidad.id), { preserveScroll: true })
}

const aVerificacion = useForm({})
const avanzarAVerificacion = () => {
    aVerificacion.post(route('no-conformidades.verificacion', props.noConformidad.id), { preserveScroll: true })
}

/**
 * Avance de una acción (§4.6).
 *
 * ⚠️ `vencida` **no** está entre las opciones aunque sí esté en
 * `estadosAccion`: es derivado de que pase la fecha prevista, no una elección.
 * Ofrecerlo lo convertiría en "atrasada pero la sigo", que es justo lo que el
 * estado tiene que delatar solo. El backend también lo rechaza.
 */
const estadosElegibles = computed(() =>
    Object.entries(props.estadosAccion).filter(([valor]) => valor !== 'vencida'),
)

const enImplementacion = computed(() => props.noConformidad.estado === 'en_implementacion')

const accionEnAvance = ref<NonConformityAction | null>(null)

const avanceForm = useForm({
    estado: 'en_curso',
    avance: '',
    fecha_real: null as string | null,
    motivo_cancelacion: '',
    archivos: [] as File[],
})

const abrirAvance = (a: NonConformityAction) => {
    accionEnAvance.value = a
    avanceForm.defaults({
        estado: a.estado === 'vencida' ? 'en_curso' : a.estado,
        avance: a.avance ?? '',
        fecha_real: a.fecha_real,
        motivo_cancelacion: a.motivo_cancelacion ?? '',
        archivos: [],
    })
    avanceForm.reset()
    avanceForm.clearErrors()
}

const guardarAvance = () => {
    if (!accionEnAvance.value) return

    avanceForm.post(
        route('no-conformidades.acciones.avance', [props.noConformidad.id, accionEnAvance.value.id]),
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => { accionEnAvance.value = null },
        },
    )
}

const elegirArchivos = (e: Event) => {
    avanceForm.archivos = Array.from((e.target as HTMLInputElement).files ?? [])
}

/** Las que todavía traban el paso a verificación (§4.6). Cancelada no cuenta. */
const pendientes = computed(() =>
    acciones.value.filter((a) => ['pendiente', 'en_curso', 'vencida'].includes(a.estado)),
)

const varianteEstado: Record<string, 'slate' | 'amber' | 'emerald' | 'red'> = {
    pendiente: 'slate',
    en_curso: 'amber',
    completada: 'emerald',
    vencida: 'red',
    cancelada: 'slate',
}

const nombre = (u: { name: string; apellido: string | null }) =>
    [u.name, u.apellido].filter(Boolean).join(' ')

const formatFecha = (d: string | null) =>
    d ? new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-theme-xs text-gray-400">
                {{ acciones.length }} {{ acciones.length === 1 ? 'acción' : 'acciones' }}
            </p>
            <Button v-if="puedeGestionar" variant="outline" @click="abrirAlta">+ Agregar acción</Button>
        </div>

        <!--
            Cuándo se va a comprobar si el plan sirvió. Se carga acá, junto con
            las acciones, y no en la sección 6: esa sección recién se habilita
            al llegar a la etapa de verificación, y para entonces ya es tarde
            para que el sistema avise. ⚠️ No confundir con la fecha de
            seguimiento de la sección 6, que es cuándo se verificó.
        -->
        <div class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div v-if="puedeGestionar" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[200px] flex-1">
                    <InputFecha
                        v-model="verificacionForm.fecha_verificacion_prevista"
                        label="Fecha de verificación de eficacia"
                        hint="Cuándo se va a comprobar si estas acciones sirvieron. Llegada la fecha, el sistema avisa."
                        :error="verificacionForm.errors.fecha_verificacion_prevista"
                    />
                </div>
                <Button
                    size="sm"
                    :disabled="verificacionForm.processing || !fechaVerificacionCambio"
                    @click="guardarFechaVerificacion"
                >
                    Guardar
                </Button>
            </div>
            <div v-else>
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                    Fecha de verificación de eficacia
                </p>
                <p class="mt-1 text-theme-sm text-gray-700 dark:text-gray-300">
                    {{ fechaVerificacionTexto }}
                </p>
            </div>
        </div>

        <ul v-if="acciones.length" class="mt-4 space-y-3">
            <li
                v-for="a in acciones"
                :key="a.id"
                class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <Badge :variant="varianteEstado[a.estado] ?? 'slate'">{{ estadosAccion[a.estado] }}</Badge>
                    <div class="flex items-center gap-3">
                        <button
                            v-if="puedeGestionarAccion(a) && enImplementacion"
                            type="button"
                            class="cursor-pointer text-theme-xs text-brand-500 hover:underline"
                            @click="abrirAvance(a)"
                        >
                            Registrar avance
                        </button>
                        <!--
                            Editar la descripción y el plazo también es del
                            responsable del renglón: gestiona su acción de
                            principio a fin sin depender de Calidad.
                        -->
                        <button
                            v-if="puedeGestionarAccion(a)"
                            type="button"
                            class="cursor-pointer text-theme-xs text-brand-500 hover:underline"
                            @click="abrirEdicion(a)"
                        >
                            Editar
                        </button>
                        <!--
                            Quitar solo mientras se arma el plan: una vez en
                            implementación alguien ya se comprometió con la
                            acción, y la salida es cancelarla con justificación.
                        -->
                        <button
                            v-if="puedeGestionar && noConformidad.estado === 'plan_accion'"
                            type="button"
                            class="cursor-pointer text-theme-xs text-error-500 hover:underline"
                            @click="quitarAccion(a.id)"
                        >
                            Quitar
                        </button>
                    </div>
                </div>

                <p class="mt-2 whitespace-pre-line text-theme-sm text-gray-800 dark:text-white/90">{{ a.descripcion }}</p>

                <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    <div class="flex gap-1">
                        <dt>Responsable:</dt>
                        <dd class="text-gray-700 dark:text-gray-200">{{ a.responsable ? nombre(a.responsable) : '—' }}</dd>
                    </div>
                    <div class="flex gap-1">
                        <dt>Fecha prevista:</dt>
                        <dd class="text-gray-700 dark:text-gray-200">{{ formatFecha(a.fecha_prevista) }}</dd>
                    </div>
                    <div v-if="a.fecha_real" class="flex gap-1">
                        <dt>Cumplida el:</dt>
                        <dd class="text-gray-700 dark:text-gray-200">{{ formatFecha(a.fecha_real) }}</dd>
                    </div>
                </dl>

                <p v-if="a.evidencia_requerida" class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    Evidencia requerida: {{ a.evidencia_requerida }}
                </p>

                <p v-if="a.avance" class="mt-2 whitespace-pre-line rounded-lg bg-gray-50 p-2 text-theme-xs text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">
                    {{ a.avance }}
                </p>

                <p v-if="a.motivo_cancelacion" class="mt-2 text-theme-xs text-error-500">
                    Cancelada: {{ a.motivo_cancelacion }}
                </p>
            </li>
        </ul>
        <p v-else class="mt-2 text-theme-sm text-gray-400">
            Todavía no hay acciones. Cargá al menos una para poder pasar a implementación.
        </p>

        <div
            v-if="puedeGestionar && noConformidad.estado === 'plan_accion'"
            class="mt-5 flex justify-end border-t border-gray-100 pt-5 dark:border-gray-800"
        >
            <Button :disabled="avanzar.processing || acciones.length === 0" @click="avanzarAImplementacion">
                Pasar a En implementación
            </Button>
        </div>

        <div
            v-if="puedeGestionar && enImplementacion"
            class="mt-5 flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800"
        >
            <p v-if="pendientes.length" class="text-theme-xs text-gray-500 dark:text-gray-400">
                Quedan {{ pendientes.length }} {{ pendientes.length === 1 ? 'acción' : 'acciones' }} sin resolver.
            </p>
            <Button :disabled="aVerificacion.processing || pendientes.length > 0" @click="avanzarAVerificacion">
                Pasar a Verificación de eficacia
            </Button>
        </div>
    </div>

    <Modal
        :show="mostrarAlta"
        :title="editandoId === null ? 'Agregar acción al plan' : 'Editar la acción'"
        size="lg"
        @close="mostrarAlta = false"
    >
        <form class="space-y-4" @submit.prevent="guardar">
            <Textarea
                v-model="form.descripcion"
                label="Descripción de la acción"
                required
                :rows="3"
                :error="form.errors.descripcion"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="form.responsable_id" label="Responsable" required :error="form.errors.responsable_id">
                    <option :value="null">Elegí un responsable…</option>
                    <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ nombre(u) }}</option>
                </Select>

                <InputFecha
                    v-model="form.fecha_prevista"
                    label="Fecha prevista de cumplimiento"
                    required
                    :error="form.errors.fecha_prevista"
                />
            </div>

            <Textarea v-model="form.evidencia_requerida" label="Evidencia requerida" :rows="2" />
            <Textarea v-model="form.observaciones" label="Observaciones" :rows="2" />

            <div class="flex justify-end gap-3">
                <Button variant="outline" type="button" @click="mostrarAlta = false">Cancelar</Button>
                <Button type="submit" :disabled="form.processing">
                    {{ editandoId === null ? 'Agregar' : 'Guardar' }}
                </Button>
            </div>
        </form>
    </Modal>

    <Modal
        :show="accionEnAvance !== null"
        title="Registrar avance"
        size="lg"
        @close="accionEnAvance = null"
    >
        <form v-if="accionEnAvance" class="space-y-4" @submit.prevent="guardarAvance">
            <p class="rounded-lg bg-gray-50 p-3 text-theme-sm text-gray-600 dark:bg-white/[0.03] dark:text-gray-300">
                {{ accionEnAvance.descripcion }}
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <Select v-model="avanceForm.estado" label="Estado" required :error="avanceForm.errors.estado">
                    <option v-for="[valor, label] in estadosElegibles" :key="valor" :value="valor">
                        {{ label }}
                    </option>
                </Select>

                <InputFecha
                    v-model="avanceForm.fecha_real"
                    label="Fecha de cumplimiento"
                    hint="Si la completás sin cargarla, queda la de hoy."
                    :error="avanceForm.errors.fecha_real"
                />
            </div>

            <Textarea
                v-model="avanceForm.avance"
                label="Qué se hizo"
                :rows="3"
                :error="avanceForm.errors.avance"
            />

            <Textarea
                v-if="avanceForm.estado === 'cancelada'"
                v-model="avanceForm.motivo_cancelacion"
                label="Motivo de la cancelación"
                required
                :rows="2"
                hint="Una acción comprometida no se abandona sin dejar por qué."
                :error="avanceForm.errors.motivo_cancelacion"
            />

            <div>
                <label class="mb-1.5 block text-theme-sm font-medium text-gray-700 dark:text-gray-400">
                    Evidencias
                </label>
                <input
                    type="file"
                    multiple
                    class="w-full cursor-pointer rounded-lg border border-gray-300 p-2 text-theme-sm text-gray-700 dark:border-gray-700 dark:text-gray-300"
                    @change="elegirArchivos"
                />
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    Hasta 10 archivos de 10 MB. Quedan atados a este avance en la bitácora.
                </p>
            </div>

            <div class="flex justify-end gap-3">
                <Button variant="outline" type="button" @click="accionEnAvance = null">Cancelar</Button>
                <Button type="submit" :disabled="avanceForm.processing">Guardar avance</Button>
            </div>
        </form>
    </Modal>
</template>
