<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Button from '@/Components/Button.vue'
import Textarea from '@/Components/Textarea.vue'
import type { NoConformidad } from '@/types'

/**
 * Sección 2 — Investigación, riesgo y determinaciones.
 *
 * Se puede guardar incompleta a propósito: la investigación lleva días y nadie
 * la carga de una sentada. Lo que exige completitud es **avanzar** al plan de
 * acción, y eso lo valida el backend (`investigacionCompleta()`), no este
 * formulario.
 *
 * ⚠️ Comparte endpoint con `CausaRaiz.vue` (sección 4), que guarda por su
 * cuenta. Por eso este form **no manda `causa_raiz_factores`** y el controller
 * solo lo normaliza cuando viene — si no, guardar acá borraría las 6M.
 */
const props = defineProps<{
    noConformidad: NoConformidad
    /** Solo Gestión de Calidad edita; el resto lo ve en modo lectura. */
    puedeGestionar: boolean
}>()

const nc = props.noConformidad

const form = useForm({
    investigacion: nc.investigacion ?? '',
    alcance: nc.alcance ?? '',
    afectados: nc.afectados ?? '',
    evaluacion_riesgo: nc.evaluacion_riesgo ?? '',
    es_grave: nc.es_grave,
    es_repetitivo: nc.es_repetitivo,
    requiere_capa: nc.requiere_capa,
})

const guardar = () => {
    form.put(route('no-conformidades.investigacion', nc.id), { preserveScroll: true })
}
</script>

<template>
    <form @submit.prevent="guardar">
        <fieldset :disabled="!puedeGestionar" class="space-y-4">
            <Textarea
                v-model="form.investigacion"
                label="Investigación realizada"
                required
                :rows="6"
                hint="Obligatoria para avanzar al plan de acción."
                :error="form.errors.investigacion"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <Textarea v-model="form.alcance" label="Alcance del problema" :rows="2" />
                <Textarea
                    v-model="form.afectados"
                    label="Productos, lotes, pedidos o procesos afectados"
                    :rows="2"
                />
            </div>

            <Textarea
                v-model="form.evaluacion_riesgo"
                label="Riesgo involucrado / Ilustración"
                :rows="3"
                hint="Las imágenes van como evidencias adjuntas de la No Conformidad."
                :error="form.errors.evaluacion_riesgo"
            />

            <div class="flex flex-wrap gap-x-8 gap-y-2 rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                <label class="flex cursor-pointer items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-200">
                    <input v-model="form.es_grave" type="checkbox" class="h-4 w-4 accent-brand-500">
                    <strong>Grave</strong>
                </label>
                <label class="flex cursor-pointer items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-200">
                    <input v-model="form.es_repetitivo" type="checkbox" class="h-4 w-4 accent-brand-500">
                    <strong>Repetitivo</strong>
                </label>
                <label class="flex cursor-pointer items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-200">
                    <input v-model="form.requiere_capa" type="checkbox" class="h-4 w-4 accent-brand-500">
                    ¿Requiere <strong>CAPA</strong>?
                </label>
            </div>
            <p class="text-theme-xs text-gray-400">
                El CAPA se carga como acciones en el plan de acción (sección 5).
            </p>
        </fieldset>

        <div
            v-if="puedeGestionar"
            class="mt-4 flex justify-end border-t border-gray-100 pt-4 dark:border-gray-800"
        >
            <Button type="submit" :disabled="form.processing">Guardar investigación</Button>
        </div>
    </form>
</template>
