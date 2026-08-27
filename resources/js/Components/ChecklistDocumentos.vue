<script setup lang="ts">
import Badge from '@/Components/Badge.vue'
import InputFecha from '@/Components/InputFecha.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import type { DocumentoChecklist } from '@/types'

/**
 * Checklist de documentación de un registro: una fila por documento del
 * catálogo de su tipo, con Sí/No y (solo donde corresponde) la fecha de
 * vencimiento.
 *
 * Lo comparten la ficha de cliente y la de proveedor.
 *
 * ⚠️ **Es un campo del formulario de la ficha, no un formulario propio.** Antes
 * armaba su propio `useForm` y tenía su botón de guardar contra un endpoint
 * aparte, y como los documentos venían del tipo **ya guardado**, cargar la
 * documentación de un cliente eran dos guardados: uno para el tipo (después del
 * cual recién aparecía este bloque) y otro para los vencimientos. Ahora el
 * padre le pasa los documentos del tipo elegido en el select y el estado por
 * `v-model`, y todo se guarda junto.
 */
defineProps<{
    /** Los documentos que exige el tipo **elegido**, no el guardado. */
    documentos: DocumentoChecklist[]
    /** Errores del formulario del padre, para mostrarlos en cada campo. */
    errors?: Record<string, string>
    /** "cliente" / "proveedor", para el texto de cuando no hay tipo elegido. */
    entidad?: string
}>()

/** `[documento => { presentado, fecha_vencimiento }]`, como lo manda el backend. */
const modelo = defineModel<Record<string, { presentado: string; fecha_vencimiento: string }>>({ required: true })

const opcionesSiNo = [
    { value: '1', label: 'Sí' },
    { value: '0', label: 'No' },
]
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="mb-4 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Documentación</p>

        <p v-if="!documentos.length" class="text-sm text-gray-400">
            Elegí un tipo de {{ entidad ?? 'cliente' }} arriba para ver los documentos requeridos.
        </p>

        <div v-else class="space-y-4">
            <div
                v-for="doc in documentos"
                :key="doc.documento"
                class="rounded-xl border border-gray-100 p-4 dark:border-gray-800"
            >
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ doc.label }}</span>
                    <Badge :variant="doc.obligatorio ? 'indigo' : 'slate'">
                        {{ doc.obligatorio ? 'Obligatorio' : 'Opcional' }}
                    </Badge>
                    <Badge v-if="doc.determina_vencimiento" variant="amber">Determina el vencimiento</Badge>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <RadioGroup
                        v-if="modelo[doc.documento]"
                        v-model="modelo[doc.documento].presentado"
                        label="Presentado"
                        :opciones="opcionesSiNo"
                        :error="errors?.[`documentos.${doc.documento}.presentado`]"
                    />
                    <!-- Solo si el documento vence y está presentado: la fecha
                         de un papel que no entregaron no se guarda. -->
                    <InputFecha
                        v-if="modelo[doc.documento] && doc.vence && modelo[doc.documento].presentado === '1'"
                        v-model="modelo[doc.documento].fecha_vencimiento"
                        label="Fecha de vencimiento"
                        :error="errors?.[`documentos.${doc.documento}.fecha_vencimiento`]"
                    />
                </div>
            </div>

            <p v-if="errors?.documentos" class="text-xs text-error-500">{{ errors.documentos }}</p>
        </div>
    </div>
</template>
