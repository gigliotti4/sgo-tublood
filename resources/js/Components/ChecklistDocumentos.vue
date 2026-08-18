<script setup lang="ts">
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import InputFecha from '@/Components/InputFecha.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import type { DocumentoChecklist } from '@/types'

/**
 * Checklist de documentación de un registro: una fila por documento del
 * catálogo de su tipo, con Sí/No y (solo donde corresponde) la fecha de
 * vencimiento.
 *
 * Lo comparten la ficha de cliente y la de proveedor. El componente arma y
 * envía su propio formulario: la página solo le pasa el checklist y la URL,
 * porque el bloque se guarda aparte de los datos generales.
 */
const props = defineProps<{
    documentos: DocumentoChecklist[]
    /** Ruta a la que se hace el PUT del checklist completo. */
    url: string
    /** "cliente" / "proveedor", para el texto de cuando no hay tipo elegido. */
    entidad?: string
}>()

const opcionesSiNo = [
    { value: '1', label: 'Sí' },
    { value: '0', label: 'No' },
]

type EstadoChecklist = Record<string, { presentado: string; fecha_vencimiento: string }>

/** El checklist como lo espera el formulario: strings, que es con lo que trabaja RadioGroup. */
const estadoInicial = (): EstadoChecklist => Object.fromEntries(
    props.documentos.map(d => [d.documento, {
        presentado: d.presentado ? '1' : '0',
        fecha_vencimiento: d.fecha_vencimiento ?? '',
    }]),
)

const form = useForm({ documentos: estadoInicial() })

/**
 * Al cambiar el tipo del registro cambian los documentos que se exigen, y la
 * página se re-renderiza con props nuevas **sin remontar este componente**: sin
 * esto el formulario se quedaría con las claves del tipo anterior y los
 * `v-model` de abajo apuntarían a entradas inexistentes.
 *
 * Se compara la lista de claves y no el objeto entero a propósito: un
 * re-render que no cambia qué documentos hay no tiene que pisar lo que la
 * persona esté tildando en ese momento.
 */
watch(() => props.documentos.map(d => d.documento).join(','), () => {
    form.defaults({ documentos: estadoInicial() })
    form.reset()
})

const submit = () => form.put(props.url)
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="mb-4 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Documentación</p>

        <p v-if="!documentos.length" class="text-sm text-gray-400">
            Elegí un tipo de {{ entidad ?? 'cliente' }} para ver los documentos requeridos.
        </p>

        <form v-else @submit.prevent="submit" class="space-y-4">
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
                        v-model="form.documentos[doc.documento].presentado"
                        label="Presentado"
                        :opciones="opcionesSiNo"
                        :error="form.errors[`documentos.${doc.documento}.presentado`]"
                    />
                    <!-- Solo si el documento vence y está presentado: la fecha
                         de un papel que no entregaron no se guarda. -->
                    <InputFecha
                        v-if="doc.vence && form.documentos[doc.documento].presentado === '1'"
                        v-model="form.documentos[doc.documento].fecha_vencimiento"
                        label="Fecha de vencimiento"
                        :error="form.errors[`documentos.${doc.documento}.fecha_vencimiento`]"
                    />
                </div>
            </div>

            <p v-if="form.errors.documentos" class="text-xs text-error-500">{{ form.errors.documentos }}</p>

            <div class="flex gap-3 pt-1">
                <Button type="submit" variant="primary" :disabled="form.processing">Guardar documentación</Button>
            </div>
        </form>
    </div>
</template>
