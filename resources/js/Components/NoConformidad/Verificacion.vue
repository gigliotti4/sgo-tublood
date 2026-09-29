<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Button from '@/Components/Button.vue'
import InputFecha from '@/Components/InputFecha.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Textarea from '@/Components/Textarea.vue'
import type { NoConformidad } from '@/types'

/**
 * Secciones 6 y 7 — Seguimiento del plan y verificación de la eficacia.
 *
 * ⚠️ **Ineficaz no reabre esta NC**: abre un desvío nuevo que la reemplaza (la
 * celda "Nuevo desvío N°" del formulario). §4.7 del instructivo decía que
 * "deberá regresar" a investigación; el cliente confirmó el formulario el
 * 24/9/2026. Abrir el desvío es un paso explícito y no algo que pase de callado
 * al guardar: crea un caso nuevo.
 */
const props = defineProps<{
    noConformidad: NoConformidad
    resultadosEficacia: Record<string, string>
    puedeGestionar: boolean
    /** `false` mientras la NC no llegó a la etapa de verificación. */
    habilitada: boolean
}>()

const nc = props.noConformidad

const form = useForm({
    metodo_seguimiento: nc.metodo_seguimiento ?? '',
    fecha_seguimiento: nc.fecha_seguimiento,
    evidencia_revisada: nc.evidencia_revisada ?? '',
    resultado_eficacia: nc.resultado_eficacia ?? '',
    observaciones_verificacion: nc.observaciones_verificacion ?? '',
})

const opciones = computed(() =>
    Object.entries(props.resultadosEficacia).map(([value, label]) => ({ value, label })),
)

const guardar = () => {
    form.put(route('no-conformidades.verificacion.guardar', nc.id), { preserveScroll: true })
}

const derivarForm = useForm({})
const derivar = () => {
    derivarForm.post(route('no-conformidades.derivar', nc.id))
}

const ineficaz = computed(() => nc.resultado_eficacia === 'ineficaz')

/** El botón aparece solo si ya se guardó "ineficaz" y todavía no hay reemplazo. */
const puedeDerivar = computed(
    () => props.puedeGestionar && ineficaz.value && !nc.reemplazada_por,
)
</script>

<template>
    <form @submit.prevent="guardar">
        <fieldset :disabled="!puedeGestionar || !habilitada" class="space-y-4">
            <Textarea
                v-model="form.metodo_seguimiento"
                label="Método de seguimiento"
                :rows="3"
                :error="form.errors.metodo_seguimiento"
            />
            <InputFecha
                v-model="form.fecha_seguimiento"
                label="Fecha de seguimiento"
                :error="form.errors.fecha_seguimiento"
            />
            <Textarea
                v-model="form.evidencia_revisada"
                label="Evidencia revisada"
                :rows="2"
                :error="form.errors.evidencia_revisada"
            />

            <hr class="border-gray-100 dark:border-gray-800">

            <RadioGroup
                v-model="form.resultado_eficacia"
                label="Resultado"
                required
                :opciones="opciones"
                :error="form.errors.resultado_eficacia"
            />

            <p
                v-if="form.resultado_eficacia === 'ineficaz'"
                class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-theme-sm text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400"
            >
                Una acción ineficaz no reabre esta No Conformidad: hay que
                <strong>abrir un desvío nuevo</strong> que la reemplace, y recién ahí se puede cerrar ésta.
            </p>

            <p
                v-else-if="form.resultado_eficacia === 'pendiente_evaluacion'"
                class="text-theme-xs text-gray-500 dark:text-gray-400"
            >
                Queda en esta etapa: todavía no pasó el tiempo necesario para medirla.
            </p>

            <Textarea
                v-model="form.observaciones_verificacion"
                label="Observaciones"
                :rows="3"
                :error="form.errors.observaciones_verificacion"
            />
        </fieldset>

        <!-- "Nuevo desvío N°" del formulario -->
        <div
            v-if="ineficaz"
            class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
        >
            <p class="text-theme-xs text-gray-400">Nuevo desvío N°</p>

            <Link
                v-if="nc.reemplazada_por"
                :href="route('no-conformidades.show', nc.reemplazada_por.id)"
                class="mt-1 block font-mono text-theme-sm text-brand-500 hover:underline dark:text-brand-300"
            >
                {{ nc.reemplazada_por.numero ?? 'Sin número (en borrador)' }}
            </Link>

            <div v-else class="mt-2 flex flex-wrap items-center justify-between gap-3">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                    Todavía no se abrió.
                </p>
                <Button
                    v-if="puedeDerivar"
                    variant="outline"
                    type="button"
                    :disabled="derivarForm.processing"
                    @click="derivar"
                >
                    Abrir desvío nuevo
                </Button>
            </div>
        </div>

        <div
            v-if="puedeGestionar && habilitada"
            class="mt-4 flex justify-end border-t border-gray-100 pt-4 dark:border-gray-800"
        >
            <Button type="submit" :disabled="form.processing || !form.resultado_eficacia">
                Guardar verificación
            </Button>
        </div>
    </form>
</template>
