<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Button from '@/Components/Button.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'
import { usePermissions } from '@/composables/usePermissions'
import type { NoConformidad, NonConformityContainment } from '@/types'

/**
 * Sección 3 — Acción inmediata de contención / corrección.
 *
 * Lo que se hizo apenas se detectó el problema para frenarlo, **antes** de
 * conocer la causa. No confundir con el plan de acción (sección 5), que ataca la
 * causa una vez analizada.
 *
 * ⚠️ **Fila por fila, no la lista entera.** Hasta el 25/9/2026 este componente
 * mandaba todo el array de una. Dejó de servir cuando el responsable de UNA fila
 * pasó a poder editarla: estaría reescribiendo las filas de los demás.
 */
const props = defineProps<{
    noConformidad: NoConformidad
    usuarios: { id: number; name: string; apellido: string | null }[]
    /** Dueño del caso o Calidad: puede agregar, editar y quitar cualquier fila. */
    puedeGestionar: boolean
}>()

const { user } = usePermissions()

const contenciones = computed(() => props.noConformidad.contenciones ?? [])

/**
 * Quién puede tocar ESTA fila. Espejo de `NonConformityContainmentPolicy`: el
 * responsable del renglón, el del caso, Calidad, o —mientras el desvío no esté
 * aprobado— quien lo cargó. Ese último caso llega en `puedeGestionar`, que acá
 * es `permisos.contencion` y **no** `permisos.gestionar`. Se calcula
 * comparando contra `user.id`, mismo patrón que `puedeEditar(o)` en el listado
 * de Observaciones — la regla que manda es la del backend.
 */
const puedeEditar = (c: NonConformityContainment) =>
    props.puedeGestionar || c.responsable_id === user.value?.id

/** Quitar una fila NO es del responsable del renglón: su salida es editarla. */
const puedeQuitar = () => props.puedeGestionar

const form = useForm({
    fecha: null as string | null,
    accion: '',
    responsable_id: null as number | null,
})

const mostrarAlta = ref(false)

const abrirAlta = () => {
    form.reset()
    form.clearErrors()
    mostrarAlta.value = true
}

const agregar = () => {
    form.post(route('no-conformidades.contencion.store', props.noConformidad.id), {
        preserveScroll: true,
        onSuccess: () => { mostrarAlta.value = false },
    })
}

// Una sola fila en edición por vez: `editando` guarda su id.
const editando = ref<number | null>(null)
const edicion = useForm({
    fecha: null as string | null,
    accion: '',
    responsable_id: null as number | null,
})

const abrirEdicion = (c: NonConformityContainment) => {
    editando.value = c.id
    edicion.defaults({ fecha: c.fecha, accion: c.accion, responsable_id: c.responsable_id })
    edicion.reset()
    edicion.clearErrors()
}

const guardarEdicion = () => {
    if (editando.value === null) return

    edicion.put(route('no-conformidades.contencion.update', [props.noConformidad.id, editando.value]), {
        preserveScroll: true,
        onSuccess: () => { editando.value = null },
    })
}

const quitar = useForm({})
const quitarFila = (id: number) => {
    quitar.delete(route('no-conformidades.contencion.destroy', [props.noConformidad.id, id]), {
        preserveScroll: true,
    })
}

const nombre = (u: { name: string; apellido: string | null }) =>
    [u.name, u.apellido].filter(Boolean).join(' ')

const formatFecha = (d: string | null) =>
    d ? new Date(d).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—'
</script>

<template>
    <div>
        <p v-if="!contenciones.length" class="text-theme-sm text-gray-400">
            Sin acciones de contención cargadas.
        </p>

        <ul v-else class="space-y-3">
            <li
                v-for="c in contenciones"
                :key="c.id"
                class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
            >
                <!-- Edición en línea de una fila -->
                <form v-if="editando === c.id" class="space-y-3" @submit.prevent="guardarEdicion">
                    <Textarea
                        v-model="edicion.accion"
                        label="Acción"
                        required
                        :rows="2"
                        :error="edicion.errors.accion"
                    />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <InputFecha v-model="edicion.fecha" label="Fecha" :error="edicion.errors.fecha" />
                        <Select
                            v-model="edicion.responsable_id"
                            label="Responsable"
                            :error="edicion.errors.responsable_id"
                        >
                            <option :value="null">Sin asignar</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ nombre(u) }}</option>
                        </Select>
                    </div>
                    <div class="flex justify-end gap-3">
                        <Button variant="outline" type="button" @click="editando = null">Cancelar</Button>
                        <Button type="submit" :disabled="edicion.processing">Guardar</Button>
                    </div>
                </form>

                <template v-else>
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="whitespace-pre-line text-theme-sm text-gray-800 dark:text-white/90">
                            {{ c.accion }}
                        </p>
                        <div class="flex shrink-0 items-center gap-3">
                            <button
                                v-if="puedeEditar(c)"
                                type="button"
                                class="cursor-pointer text-theme-xs text-brand-500 hover:underline"
                                @click="abrirEdicion(c)"
                            >
                                Editar
                            </button>
                            <button
                                v-if="puedeQuitar()"
                                type="button"
                                class="cursor-pointer text-theme-xs text-error-500 hover:underline"
                                @click="quitarFila(c.id)"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>

                    <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        <div class="flex gap-1">
                            <dt>Fecha:</dt>
                            <dd class="text-gray-700 dark:text-gray-200">{{ formatFecha(c.fecha) }}</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt>Responsable:</dt>
                            <dd class="text-gray-700 dark:text-gray-200">
                                {{ c.responsable ? nombre(c.responsable) : 'Sin asignar' }}
                            </dd>
                        </div>
                    </dl>
                </template>
            </li>
        </ul>

        <div
            v-if="puedeGestionar"
            class="mt-4 flex justify-end border-t border-gray-100 pt-4 dark:border-gray-800"
        >
            <Button variant="outline" type="button" @click="abrirAlta">+ Agregar acción</Button>
        </div>

        <!-- Alta -->
        <form
            v-if="mostrarAlta"
            class="mt-4 space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
            @submit.prevent="agregar"
        >
            <Textarea
                v-model="form.accion"
                label="Acción"
                required
                :rows="2"
                :error="form.errors.accion"
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <InputFecha v-model="form.fecha" label="Fecha" :error="form.errors.fecha" />
                <Select
                    v-model="form.responsable_id"
                    label="Responsable"
                    hint="Quien la tenga asignada puede editarla sin depender de Calidad."
                    :error="form.errors.responsable_id"
                >
                    <option :value="null">Sin asignar</option>
                    <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ nombre(u) }}</option>
                </Select>
            </div>
            <div class="flex justify-end gap-3">
                <Button variant="outline" type="button" @click="mostrarAlta = false">Cancelar</Button>
                <Button type="submit" :disabled="form.processing">Agregar</Button>
            </div>
        </form>
    </div>
</template>
