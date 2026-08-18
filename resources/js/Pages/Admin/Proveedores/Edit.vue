<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Textarea from '@/Components/Textarea.vue'
import Select from '@/Components/Select.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Button from '@/Components/Button.vue'
import ControlDocumental from '@/Components/ControlDocumental.vue'
import ChecklistDocumentos from '@/Components/ChecklistDocumentos.vue'
import type { DocumentoChecklist, EstadoDocumentacion, Proveedor } from '@/types'

const props = defineProps<{
    proveedor: Proveedor
    tipos: Record<string, string>
    documentos: DocumentoChecklist[]
    estado: EstadoDocumentacion
    /** Clave del documento del que sale el vencimiento del proveedor, si el tipo tiene uno. */
    documentoDeterminante: string | null
}>()

const form = useForm({
    razon_social: props.proveedor.razon_social ?? '',
    domicilio: props.proveedor.domicilio ?? '',
    cuit: props.proveedor.cuit ?? '',
    telefono: props.proveedor.telefono ?? '',
    mail: props.proveedor.mail ?? '',
    localidad: props.proveedor.localidad ?? '',
    observaciones: props.proveedor.observaciones ?? '',
    tipo_proveedor: props.proveedor.tipo_proveedor ?? '',
    // El RadioGroup trabaja con strings; el backend los valida como boolean.
    tiene_legajo: props.proveedor.tiene_legajo ? '1' : '0',
    habilitado: props.proveedor.habilitado ? '1' : '0',
})

const submit = () => form.put(route('proveedores.update', props.proveedor.id))

const opcionesSiNo = [
    { value: '1', label: 'Sí' },
    { value: '0', label: 'No' },
]

const etiquetaDeterminante = computed(() =>
    props.documentos.find(d => d.documento === props.documentoDeterminante)?.label ?? null,
)
</script>

<template>
    <Head title="Editar proveedor" />

    <AppLayout>
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('proveedores.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Editar proveedor</h1>
        </div>

        <div class="max-w-2xl space-y-6">
            <!-- El número es la clave del import: no se edita. -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                    Número de proveedor (solo lectura — es la clave con la que se identifica en el Excel)
                </p>
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                    <dt class="text-gray-400">N°</dt>
                    <dd v-if="proveedor.numero" class="font-mono text-gray-800 dark:text-white/90">{{ proveedor.numero }}</dd>
                    <dd v-else class="italic text-gray-400">
                        Sin número — lo creó el import de artículos. Se completa solo al importar el padrón.
                    </dd>
                </dl>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Datos del proveedor</p>
                <p class="mb-4 text-theme-xs text-warning-600 dark:text-warning-400">
                    Razón social, domicilio, localidad, CUIT, teléfono y mail se pisan en cada sincronización
                    con RP Sistemas: si hay que corregir algo permanente, corregilo en el ERP. La clasificación
                    de más abajo y Observaciones son propias del panel y no las toca la sincronización.
                </p>
                <form @submit.prevent="submit" class="space-y-4">
                    <Input
                        v-model="form.razon_social"
                        label="Razón social"
                        required
                        :error="form.errors.razon_social"
                    />
                    <Input
                        v-model="form.domicilio"
                        label="Domicilio"
                        :error="form.errors.domicilio"
                    />
                    <Input
                        v-model="form.localidad"
                        label="Localidad"
                        :error="form.errors.localidad"
                    />
                    <Input
                        v-model="form.cuit"
                        label="CUIT"
                        :error="form.errors.cuit"
                    />
                    <Input
                        v-model="form.telefono"
                        label="Teléfono"
                        :error="form.errors.telefono"
                    />
                    <Input
                        v-model="form.mail"
                        type="email"
                        label="Mail"
                        :error="form.errors.mail"
                    />
                    <Textarea
                        v-model="form.observaciones"
                        label="Observaciones"
                        hint="Campo propio del panel: la sincronización no lo toca."
                        :error="form.errors.observaciones"
                    />

                    <div class="border-t border-gray-100 pt-4 dark:border-gray-800">
                        <p class="mb-4 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Clasificación</p>
                        <div class="space-y-4">
                            <Select
                                v-model="form.tipo_proveedor"
                                label="Tipo de proveedor"
                                hint="Define qué documentación se le exige."
                                :error="form.errors.tipo_proveedor"
                            >
                                <option value="">— Sin clasificar —</option>
                                <option v-for="(label, slug) in tipos" :key="slug" :value="slug">{{ label }}</option>
                            </Select>
                            <RadioGroup
                                v-model="form.tiene_legajo"
                                label="Tiene legajo"
                                :opciones="opcionesSiNo"
                                :error="form.errors.tiene_legajo"
                            />
                            <RadioGroup
                                v-model="form.habilitado"
                                label="Habilitado"
                                :opciones="opcionesSiNo"
                                hint="Habilitación documental del panel. No es el estado (A/S/I) que trae el ERP."
                                :error="form.errors.habilitado"
                            />
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <Button type="submit" variant="primary" :disabled="form.processing">Guardar cambios</Button>
                    </div>
                </form>
            </div>

            <ControlDocumental
                :estado="estado"
                :tipo="proveedor.tipo_proveedor"
                :fecha-vencimiento="proveedor.fecha_vencimiento"
                :etiqueta-determinante="etiquetaDeterminante"
                entidad="proveedor"
            />

            <ChecklistDocumentos
                :documentos="documentos"
                :url="route('proveedores.documentacion.update', proveedor.id)"
                entidad="proveedor"
            />
        </div>
    </AppLayout>
</template>
