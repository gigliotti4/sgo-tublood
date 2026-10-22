<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Button from '@/Components/Button.vue'
import ProductosAfectados, { type ProductoForm } from '@/Components/ProductosAfectados.vue'
import Textarea from '@/Components/Textarea.vue'
import type { NoConformidad } from '@/types'

/**
 * Sección 2 — Investigación, afectados, riesgo y determinaciones.
 *
 * Se puede guardar incompleta a propósito: la investigación lleva días y nadie
 * la carga de una sentada. Lo que exige completitud es **avanzar** al plan de
 * acción, y eso lo valida el backend (`investigacionCompleta()`), no este
 * formulario.
 *
 * Cambios del 21/10/2026 (reunión con Tublood):
 * - se carga **antes de la aprobación**, con la misma llave que la contención
 *   (`permisos.investigacion`): quien aprueba la lee para decidir;
 * - se sacó "Alcance del problema";
 * - los afectados son una tabla de productos (la de las observaciones) más un
 *   texto para los pedidos o procesos, que no son un producto;
 * - "Riesgo involucrado / Ilustración" acepta imágenes y documentos, que se
 *   suman (un adjunto es evidencia y no se reemplaza);
 * - guarda en su propia ruta: la causa raíz (sección 4) sigue siendo posterior
 *   a la aprobación y ya no comparte endpoint con esta sección.
 */
const props = defineProps<{
    noConformidad: NoConformidad
    presentaciones: Record<string, string>
    puedeGestionar: boolean
}>()

const nc = props.noConformidad

const comoFormulario = (p: NonNullable<NoConformidad['productos']>[number]): ProductoForm => ({
    codigo: p.codigo ?? '',
    producto: p.producto ?? '',
    cantidad_afectada: p.cantidad_afectada,
    tipo_presentacion: p.tipo_presentacion ?? '',
    lote: p.lote ?? '',
    // La fecha viaja como ISO completo; el campo enmascarado trabaja con YYYY-MM-DD.
    fecha_vencimiento: p.fecha_vencimiento?.slice(0, 10) ?? '',
    numero_remito: '',
    tipo_comprobante: '',
})

const form = useForm({
    investigacion: nc.investigacion ?? '',
    afectados: nc.afectados ?? '',
    evaluacion_riesgo: nc.evaluacion_riesgo ?? '',
    es_grave: nc.es_grave,
    es_repetitivo: nc.es_repetitivo,
    requiere_capa: nc.requiere_capa,
    productos: (nc.productos ?? []).map(comoFormulario),
    archivos_riesgo: [] as File[],
})

const inputArchivos = ref<HTMLInputElement | null>(null)

const elegirArchivos = (e: Event) => {
    form.archivos_riesgo = Array.from((e.target as HTMLInputElement).files ?? [])
}

const adjuntosRiesgo = computed(() => (nc.attachments ?? []).filter(a => a.seccion === 'riesgo'))

const guardar = () => {
    // POST con FormData: sube la ilustración. Al volver se limpia el input, así
    // el próximo guardado no vuelve a subir los mismos archivos.
    form.post(route('no-conformidades.investigacion', nc.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.archivos_riesgo = []
            if (inputArchivos.value) inputArchivos.value.value = ''
        },
    })
}

const errores = computed(() => form.errors as Record<string, string>)
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

            <div class="space-y-2">
                <p class="text-theme-sm font-medium text-gray-700 dark:text-gray-400">Productos afectados</p>
                <ProductosAfectados
                    v-model="form.productos"
                    :obligatorios="false"
                    :con-comprobante="false"
                    :disabled="!puedeGestionar"
                    :presentaciones="presentaciones"
                    :errors="errores"
                />
            </div>

            <Textarea
                v-model="form.afectados"
                label="Pedidos o procesos afectados"
                :rows="2"
                hint="Lo que no es un producto: un pedido, un proceso, un circuito."
                :error="form.errors.afectados"
            />

            <div class="space-y-2">
                <Textarea
                    v-model="form.evaluacion_riesgo"
                    label="Riesgo involucrado / Ilustración"
                    :rows="3"
                    :error="form.errors.evaluacion_riesgo"
                />

                <ul v-if="adjuntosRiesgo.length" class="space-y-1">
                    <li v-for="a in adjuntosRiesgo" :key="a.id">
                        <a
                            :href="route('no-conformidades.archivos.download', [nc.id, a.id])"
                            class="text-theme-sm text-brand-500 hover:underline dark:text-brand-300"
                        >
                            {{ a.original_name }}
                        </a>
                    </li>
                </ul>

                <input
                    v-if="puedeGestionar"
                    ref="inputArchivos"
                    type="file"
                    multiple
                    accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                    class="w-full cursor-pointer rounded-lg border border-gray-300 p-2 text-theme-sm text-gray-700 dark:border-gray-700 dark:text-gray-300"
                    @change="elegirArchivos"
                />
                <p v-if="puedeGestionar" class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Imágenes o documentos (hasta 10 MB cada uno). Se suman a los que ya están.
                </p>
                <p v-if="errores.archivos_riesgo" class="text-theme-xs text-error-500">{{ errores.archivos_riesgo }}</p>
                <p
                    v-for="(mensaje, clave) in Object.fromEntries(Object.entries(errores).filter(([k]) => k.startsWith('archivos_riesgo.')))"
                    :key="clave"
                    class="text-theme-xs text-error-500"
                >
                    {{ mensaje }}
                </p>
            </div>

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
