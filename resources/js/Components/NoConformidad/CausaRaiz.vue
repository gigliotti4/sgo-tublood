<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Button from '@/Components/Button.vue'
import Textarea from '@/Components/Textarea.vue'
import type { NoConformidad } from '@/types'

/**
 * Sección 4 — Análisis de causa raíz por las 6M (Ishikawa).
 *
 * Se completan **solo los factores que aportaron algo**: de un diagrama de
 * causa-efecto lo que sirve es *qué* aportó cada factor, no cuáles se tildaron.
 * Los vacíos no se guardan y el backend descarta cualquier clave fuera del
 * catálogo.
 *
 * ⚠️ El orden de los factores es el del formulario en papel y lo manda el
 * backend (`NoConformidad::FACTORES_CAUSA`): acá se itera tal cual viene.
 *
 * ⚠️ Comparte endpoint con `Investigacion.vue` (sección 2). Este form sí manda
 * `causa_raiz_factores`, y el otro no — ver el comentario del controller.
 */
const props = defineProps<{
    noConformidad: NoConformidad
    factoresCausa: Record<string, string>
    puedeGestionar: boolean
}>()

const nc = props.noConformidad

const form = useForm({
    causa_raiz_factores: { ...(nc.causa_raiz_factores ?? {}) } as Record<string, string>,
    causa_raiz: nc.causa_raiz ?? '',
    conclusion: nc.conclusion ?? '',
})

const guardar = () => {
    form.put(route('no-conformidades.investigacion', nc.id), { preserveScroll: true })
}

/**
 * Espejo de `NoConformidad::investigacionCompleta()`, solo para anticipar el
 * bloqueo. La regla que manda es la del backend.
 */
const completa = computed(
    () => form.causa_raiz.trim() !== '' && (nc.investigacion ?? '').trim() !== '',
)

const avanzar = useForm({})
const avanzarAPlan = () => {
    avanzar.post(route('no-conformidades.plan-accion', nc.id), { preserveScroll: true })
}
</script>

<template>
    <form @submit.prevent="guardar">
        <fieldset :disabled="!puedeGestionar" class="space-y-4">
            <p class="text-theme-xs text-gray-400">
                Completá solo los factores que hayan contribuido. Los vacíos no se guardan.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <Textarea
                    v-for="(label, clave) in factoresCausa"
                    :key="clave"
                    v-model="form.causa_raiz_factores[clave]"
                    :label="label"
                    :rows="2"
                />
            </div>

            <Textarea
                v-model="form.causa_raiz"
                label="Resumen del análisis / Causa raíz"
                required
                :rows="4"
                hint="Obligatoria para avanzar al plan de acción. Si no se llegó a una causa única, decilo acá."
                :error="form.errors.causa_raiz"
            />

            <Textarea v-model="form.conclusion" label="Conclusión de la investigación" :rows="3" />
        </fieldset>

        <div
            v-if="puedeGestionar"
            class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800"
        >
            <p v-if="!completa" class="text-theme-xs text-gray-400">
                Para avanzar hacen falta la investigación (sección 2) y la causa raíz.
            </p>
            <span v-else />

            <div class="flex gap-3">
                <Button variant="outline" type="submit" :disabled="form.processing">Guardar</Button>
                <Button
                    v-if="noConformidad.estado === 'abierta'"
                    type="button"
                    :disabled="form.processing || !completa"
                    @click="avanzarAPlan"
                >
                    Pasar a Plan de acción
                </Button>
            </div>
        </div>
    </form>
</template>
