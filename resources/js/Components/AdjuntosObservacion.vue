<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import Icon from '@/Components/Icon.vue'
import type { ObservationAttachment } from '@/types'

/**
 * Lista, descarga, subida y borrado de adjuntos de una observación.
 *
 * Compartido por la pantalla de detalle y el modal de edición del listado: son
 * los dos lugares donde se gestiona un caso, y duplicar el bloque significaba
 * que arreglar algo en uno dejaba el otro atrás.
 *
 * Todo va con `preserveState` para que el modal no se cierre al subir o borrar.
 */
const props = defineProps<{
    observacionId: number
    adjuntos: ObservationAttachment[]
    /** Sale de ObservacionPolicy: solo el responsable asignado (o super-admin). */
    puedeEditar: boolean
    /** Compacto para el modal, más aireado en la pantalla de detalle. */
    compacto?: boolean
}>()

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

const quitarDeLaCola = (index: number) => {
    uploadForm.archivos = uploadForm.archivos.filter((_, i) => i !== index)
}

const subirArchivos = () => {
    uploadForm.post(route('observaciones.archivos.store', props.observacionId), {
        forceFormData: true,
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    })
}

const borrarArchivo = (archivoId: number) => {
    router.delete(route('observaciones.archivos.destroy', [props.observacionId, archivoId]), {
        preserveState: true,
        preserveScroll: true,
    })
}

const formatSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
    <div>
        <ul v-if="adjuntos.length" class="space-y-1.5">
            <li
                v-for="a in adjuntos"
                :key="a.id"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
            >
                <a
                    :href="route('observaciones.archivos.download', [observacionId, a.id])"
                    class="flex min-w-0 flex-1 items-center gap-2 text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                    :class="compacto ? 'text-theme-xs' : 'text-theme-sm'"
                >
                    <Icon name="paperclip" class="h-4 w-4 shrink-0 text-gray-400" />
                    <span class="truncate">{{ a.original_name }}</span>
                    <span class="shrink-0 text-theme-xs text-gray-400">({{ formatSize(a.size) }})</span>
                </a>
                <button
                    v-if="puedeEditar"
                    type="button"
                    class="shrink-0 rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-error-50 hover:text-error-500 dark:hover:bg-error-500/15"
                    title="Eliminar archivo"
                    @click="borrarArchivo(a.id)"
                >
                    <Icon name="trash" class="h-4 w-4" />
                    <span class="sr-only">Eliminar {{ a.original_name }}</span>
                </button>
            </li>
        </ul>
        <p v-else class="text-theme-sm text-gray-400">Sin archivos adjuntos.</p>

        <div v-if="puedeEditar" :class="adjuntos.length ? 'mt-3 border-t border-gray-100 pt-3 dark:border-gray-800' : 'mt-3'">
            <div
                class="cursor-pointer rounded-xl border-2 border-dashed text-center transition-colors"
                :class="[
                    compacto ? 'px-3 py-4' : 'px-4 py-6',
                    isDragging
                        ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10'
                        : 'border-gray-300 hover:border-brand-300 dark:border-gray-700',
                ]"
                @click="fileInput?.click()"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="onDrop"
            >
                <p class="text-gray-600 dark:text-gray-300" :class="compacto ? 'text-theme-xs' : 'text-theme-sm'">
                    Arrastrá archivos acá o <span class="font-medium text-brand-500">buscalos en tu equipo</span>
                </p>
                <p class="mt-1 text-theme-xs text-gray-400">PDF, imágenes, Word o Excel · hasta 10 MB</p>
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="hidden"
                    accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                    @change="addFiles(($event.target as HTMLInputElement).files)"
                />
            </div>

            <p v-if="uploadForm.errors['archivos.0']" class="mt-2 text-xs text-error-500">
                {{ uploadForm.errors['archivos.0'] }}
            </p>

            <ul v-if="uploadForm.archivos.length" class="mt-3 space-y-1.5">
                <li
                    v-for="(file, index) in uploadForm.archivos"
                    :key="index"
                    class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-theme-xs dark:bg-white/[0.03]"
                >
                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ file.name }}</span>
                    <span class="shrink-0 text-gray-400">{{ formatSize(file.size) }}</span>
                    <button
                        type="button"
                        class="shrink-0 text-gray-400 transition-colors hover:text-error-500"
                        title="Quitar"
                        @click="quitarDeLaCola(index)"
                    >
                        <Icon name="trash" class="h-3.5 w-3.5" />
                        <span class="sr-only">Quitar {{ file.name }} de la lista</span>
                    </button>
                </li>
            </ul>

            <Button
                v-if="uploadForm.archivos.length"
                variant="primary"
                class="mt-3"
                :disabled="uploadForm.processing"
                @click="subirArchivos"
            >
                {{ uploadForm.processing ? 'Subiendo…' : `Subir ${uploadForm.archivos.length} archivo${uploadForm.archivos.length !== 1 ? 's' : ''}` }}
            </Button>
        </div>
    </div>
</template>
