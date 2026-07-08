<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import type { Cliente } from '@/types'

const props = defineProps<{ cliente: Cliente }>()

const form = useForm({
    fecha_vencimiento: props.cliente.fecha_vencimiento?.slice(0, 10) ?? '',
})

const submit = () => form.put(route('clientes.update', props.cliente.id))

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
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('clientes.index')" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Editar cliente</h1>
        </div>

        <div class="space-y-6 max-w-2xl">
            <!-- Datos del ERP (solo lectura) -->
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-6">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-3">
                    Datos de RP Sistemas (solo lectura — se actualizan con la sincronización)
                </p>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-slate-400 dark:text-slate-500">N° cliente</dt>
                    <dd class="text-slate-800 dark:text-slate-100 font-mono">{{ cliente.numero }}</dd>
                    <dt class="text-slate-400 dark:text-slate-500">Razón social</dt>
                    <dd class="text-slate-800 dark:text-slate-100">{{ cliente.razon_social }}</dd>
                    <dt class="text-slate-400 dark:text-slate-500">CUIT</dt>
                    <dd class="text-slate-800 dark:text-slate-100">{{ cliente.cuit ?? '—' }}</dd>
                    <dt class="text-slate-400 dark:text-slate-500">IVA</dt>
                    <dd class="text-slate-800 dark:text-slate-100">{{ cliente.descripcion_iva ?? '—' }}</dd>
                    <dt class="text-slate-400 dark:text-slate-500">Teléfono</dt>
                    <dd class="text-slate-800 dark:text-slate-100">{{ cliente.telefono ?? '—' }}</dd>
                    <dt class="text-slate-400 dark:text-slate-500">Mail</dt>
                    <dd class="text-slate-800 dark:text-slate-100">{{ cliente.mail ?? '—' }}</dd>
                    <dt class="text-slate-400 dark:text-slate-500">Domicilio</dt>
                    <dd class="text-slate-800 dark:text-slate-100">{{ cliente.domicilio ?? '—' }}</dd>
                    <dt class="text-slate-400 dark:text-slate-500">Localidad</dt>
                    <dd class="text-slate-800 dark:text-slate-100">
                        {{ [cliente.localidad, cliente.descripcion_provincia].filter(Boolean).join(', ') || '—' }}
                    </dd>
                </dl>
            </div>

            <!-- Campo propio -->
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-6">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide mb-3">Datos propios</p>
                <form @submit.prevent="submit" class="space-y-4">
                    <Input
                        v-model="form.fecha_vencimiento"
                        type="date"
                        label="Fecha de vencimiento"
                        :error="form.errors.fecha_vencimiento"
                    />
                    <div class="flex gap-3 pt-2">
                        <Button type="submit" variant="primary" :disabled="form.processing">Guardar cambios</Button>
                    </div>
                </form>
            </div>

            <!-- Archivos -->
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-6 space-y-4">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-wide">Archivos adjuntos</p>

                <ul v-if="cliente.attachments?.length" class="space-y-1.5">
                    <li
                        v-for="archivo in cliente.attachments"
                        :key="archivo.id"
                        class="flex items-center justify-between text-sm bg-slate-50 dark:bg-slate-700/40 rounded-lg px-3 py-2"
                    >
                        <a
                            :href="route('clientes.archivos.download', [cliente.id, archivo.id])"
                            class="text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline truncate"
                        >
                            {{ archivo.original_name }}
                        </a>
                        <div class="flex items-center gap-3 shrink-0 ml-3">
                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ formatSize(archivo.size) }}</span>
                            <button type="button" class="text-slate-400 hover:text-red-500" @click="borrarArchivo(archivo.id)">✕</button>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm text-slate-400 dark:text-slate-500">No hay archivos adjuntos todavía.</p>

                <div
                    class="border-2 border-dashed rounded-xl p-6 text-center transition"
                    :class="isDragging ? 'border-indigo-400 bg-indigo-50/50 dark:bg-indigo-900/10' : 'border-slate-200 dark:border-slate-600'"
                    @dragover.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @drop.prevent="onDrop"
                >
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Adjuntar archivos</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">JPG, PNG, PDF, DOC, XLS. Máx 10 MB c/u</p>
                    <button
                        type="button"
                        class="mt-3 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-medium"
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
                        class="flex items-center justify-between text-sm bg-slate-50 dark:bg-slate-700/40 rounded-lg px-3 py-2"
                    >
                        <span class="text-slate-700 dark:text-slate-200 truncate">{{ file.name }}</span>
                        <button type="button" class="text-slate-400 hover:text-red-500" @click="removeFile(index)">✕</button>
                    </li>
                </ul>
                <p v-if="uploadForm.errors.archivos" class="text-red-500 text-xs">{{ uploadForm.errors.archivos }}</p>

                <div v-if="uploadForm.archivos.length" class="flex gap-3">
                    <Button variant="primary" :disabled="uploadForm.processing" @click="subirArchivos">
                        {{ uploadForm.processing ? 'Subiendo...' : 'Subir archivos' }}
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
