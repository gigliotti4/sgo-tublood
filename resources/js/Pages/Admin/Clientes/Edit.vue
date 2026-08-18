<script setup lang="ts">
import { computed, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Button from '@/Components/Button.vue'
import ControlDocumental from '@/Components/ControlDocumental.vue'
import ChecklistDocumentos from '@/Components/ChecklistDocumentos.vue'
import type { Cliente, DocumentoChecklist, EstadoDocumentacion } from '@/types'

const props = defineProps<{
    cliente: Cliente
    tipos: Record<string, string>
    documentos: DocumentoChecklist[]
    estado: EstadoDocumentacion
    /** Clave del documento del que sale el vencimiento del cliente, si el tipo tiene uno. */
    documentoDeterminante: string | null
}>()

const form = useForm({
    mail_nuevo: props.cliente.mail_nuevo ?? '',
    tipo_cliente: props.cliente.tipo_cliente ?? '',
    // El RadioGroup trabaja con strings; el backend los valida como boolean.
    tiene_legajo: props.cliente.tiene_legajo ? '1' : '0',
    habilitado: props.cliente.habilitado ? '1' : '0',
    notas: props.cliente.notas ?? '',
})

const submit = () => form.put(route('clientes.update', props.cliente.id))

const opcionesSiNo = [
    { value: '1', label: 'Sí' },
    { value: '0', label: 'No' },
]

const etiquetaDeterminante = computed(() =>
    props.documentos.find(d => d.documento === props.documentoDeterminante)?.label ?? null,
)

const uploadForm = useForm({
    archivos: [] as File[],
})

const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const addFiles = (files: FileList | null) => {
    if (!files) return
    uploadForm.archivos = [...uploadForm.archivos, ...Array.from(files)]
}

const onDrop = (e: DragEvent) => {
    isDragging.value = false
    addFiles(e.dataTransfer?.files ?? null)
}

const removeFile = (index: number) => {
    uploadForm.archivos = uploadForm.archivos.filter((_, i) => i !== index)
}

const subirArchivos = () => {
    uploadForm.post(route('clientes.archivos.store', props.cliente.id), {
        forceFormData: true,
        onSuccess: () => { uploadForm.reset() },
    })
}

const borrarArchivo = (archivoId: number) => {
    router.delete(route('clientes.archivos.destroy', [props.cliente.id, archivoId]))
}

const formatSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
    <Head title="Editar cliente" />

    <AppLayout>
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('clientes.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Editar cliente</h1>
        </div>

        <div class="max-w-2xl space-y-6">
            <!-- Datos del ERP (solo lectura) -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                    Datos de RP Sistemas (solo lectura — se actualizan con la sincronización)
                </p>
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                    <dt class="text-gray-400">N° cliente</dt>
                    <dd class="font-mono text-gray-800 dark:text-white/90">{{ cliente.numero }}</dd>
                    <dt class="text-gray-400">Razón social</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ cliente.razon_social }}</dd>
                    <dt class="text-gray-400">CUIT</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ cliente.cuit ?? '—' }}</dd>
                    <dt class="text-gray-400">IVA</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ cliente.descripcion_iva ?? '—' }}</dd>
                    <dt class="text-gray-400">Teléfono</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ cliente.telefono ?? '—' }}</dd>
                    <dt class="text-gray-400">Mail</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ cliente.mail ?? '—' }}</dd>
                    <dt class="text-gray-400">Domicilio</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ cliente.domicilio ?? '—' }}</dd>
                    <dt class="text-gray-400">Localidad</dt>
                    <dd class="text-gray-800 dark:text-white/90">
                        {{ [cliente.localidad, cliente.descripcion_provincia].filter(Boolean).join(', ') || '—' }}
                    </dd>
                </dl>
            </div>

            <!-- Campos propios -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Datos generales</p>
                <form @submit.prevent="submit" class="space-y-4">
                    <Select
                        v-model="form.tipo_cliente"
                        label="Tipo de cliente"
                        hint="Define qué documentación se le exige."
                        :error="form.errors.tipo_cliente"
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
                        :error="form.errors.habilitado"
                    />
                    <Input
                        v-model="form.mail_nuevo"
                        type="email"
                        label="Mail de contacto"
                        hint="Se completa solo cuando el cliente carga un reclamo por el portal. La sincronización con RP Sistemas no lo pisa."
                        :error="form.errors.mail_nuevo"
                    />
                    <Textarea
                        v-model="form.notas"
                        label="Observaciones"
                        :rows="3"
                        :error="form.errors.notas"
                    />
                    <div class="flex gap-3 pt-2">
                        <Button type="submit" variant="primary" :disabled="form.processing">Guardar cambios</Button>
                    </div>
                </form>
            </div>

            <ControlDocumental
                :estado="estado"
                :tipo="cliente.tipo_cliente"
                :fecha-vencimiento="cliente.fecha_vencimiento"
                :etiqueta-determinante="etiquetaDeterminante"
                entidad="cliente"
            />

            <ChecklistDocumentos
                :documentos="documentos"
                :url="route('clientes.documentacion.update', cliente.id)"
                entidad="cliente"
            />

            <!-- Archivos -->
            <div class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Archivos adjuntos</p>

                <ul v-if="cliente.attachments?.length" class="space-y-1.5">
                    <li
                        v-for="archivo in cliente.attachments"
                        :key="archivo.id"
                        class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.05]"
                    >
                        <a
                            :href="route('clientes.archivos.download', [cliente.id, archivo.id])"
                            class="truncate text-gray-700 hover:text-brand-500 hover:underline dark:text-gray-200 dark:hover:text-brand-300"
                        >
                            {{ archivo.original_name }}
                        </a>
                        <div class="ml-3 flex shrink-0 items-center gap-3">
                            <span class="text-theme-xs text-gray-400">{{ formatSize(archivo.size) }}</span>
                            <button type="button" class="cursor-pointer rounded-full p-2 -m-2 text-gray-400 hover:text-error-500" @click="borrarArchivo(archivo.id)">✕</button>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-400">No hay archivos adjuntos todavía.</p>

                <div
                    class="rounded-xl border-2 border-dashed p-6 text-center transition"
                    :class="isDragging ? 'border-brand-300 bg-brand-50/60 dark:border-brand-500/50 dark:bg-brand-500/10' : 'border-gray-200 dark:border-gray-700'"
                    @dragover.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @drop.prevent="onDrop"
                >
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Adjuntar archivos</p>
                    <p class="mt-1 text-xs text-gray-400">JPG, PNG, PDF, DOC, XLS. Máx 10 MB c/u</p>
                    <button
                        type="button"
                        class="mt-3 inline-flex min-h-11 -mx-2 items-center px-2 cursor-pointer text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                        @click="fileInput?.click()"
                    >
                        Seleccionar archivos
                    </button>
                    <input
                        ref="fileInput"
                        type="file"
                        multiple
                        accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                        class="hidden"
                        @change="addFiles(($event.target as HTMLInputElement).files)"
                    />
                </div>

                <ul v-if="uploadForm.archivos.length" class="space-y-1.5">
                    <li
                        v-for="(file, index) in uploadForm.archivos"
                        :key="index"
                        class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.05]"
                    >
                        <span class="truncate text-gray-700 dark:text-gray-200">{{ file.name }}</span>
                        <button type="button" class="cursor-pointer rounded-full p-2 -m-2 text-gray-400 hover:text-error-500" @click="removeFile(index)">✕</button>
                    </li>
                </ul>
                <p v-if="uploadForm.errors.archivos" class="text-xs text-error-500">{{ uploadForm.errors.archivos }}</p>

                <div v-if="uploadForm.archivos.length" class="flex gap-3">
                    <Button variant="primary" :disabled="uploadForm.processing" @click="subirArchivos">
                        {{ uploadForm.processing ? 'Subiendo...' : 'Subir archivos' }}
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
