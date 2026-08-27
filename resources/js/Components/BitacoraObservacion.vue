<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Icon from '@/Components/Icon.vue'
import Textarea from '@/Components/Textarea.vue'
import { accionLabels, accionVariant, comoCambioSimple, esBaja, esClasificacion, esNotificados, formatFechaHora, formatSize, nombreAutor } from '@/lib/bitacora'
import { erroresDeArchivos } from '@/lib/errores'
import type { ObservationHistoryEntry } from '@/types'

/**
 * Bitácora del caso: timeline de cambios (los deja ObservacionObserver) y
 * comentarios manuales con adjuntos.
 *
 * Compartido por la pantalla de detalle y el modal de edición del listado,
 * igual que AdjuntosObservacion — son los dos lugares donde se gestiona un
 * caso. Es de solo lectura salvo por el cuadro de comentario nuevo al final:
 * el historial es inmutable, no hay edición ni borrado de una entrada ya
 * creada.
 */
const props = defineProps<{
    observacionId: number
    entradas: ObservationHistoryEntry[]
    /** Sale de ObservacionPolicy: responsable asignado, o del sector de la observación. */
    puedeEditar: boolean
    compacto?: boolean
}>()

// ── Comentario nuevo ─────────────────────────────────────────────────────

const form = useForm({
    nota: '',
    archivos: [] as File[],
})

const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const addFiles = (files: FileList | null) => {
    if (!files) return
    form.archivos = [...form.archivos, ...Array.from(files)]
}

const onDrop = (e: DragEvent) => {
    isDragging.value = false
    addFiles(e.dataTransfer?.files ?? null)
}

const quitarDeLaCola = (index: number) => {
    form.archivos = form.archivos.filter((_, i) => i !== index)
}

// Un error por archivo: los errores vuelven en `archivos.0`, `archivos.1`… y
// acá no se mostraba ninguno, así que un adjunto rechazado dejaba el comentario
// sin enviarse y sin explicación.
const erroresArchivos = computed(() => erroresDeArchivos(form.errors as Record<string, string>, 'archivos'))

const puedeEnviar = () => form.nota.trim() !== '' || form.archivos.length > 0

const enviar = () => {
    if (!puedeEnviar()) return

    form.post(route('observaciones.bitacora.store', props.observacionId), {
        forceFormData: true,
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    })
}
</script>

<template>
    <div>
        <ol v-if="entradas.length" class="space-y-4">
            <li
                v-for="entrada in entradas"
                :key="entrada.id"
                class="rounded-lg border border-gray-100 p-3 dark:border-gray-800"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <Badge :variant="accionVariant[entrada.accion] ?? 'slate'">
                            {{ accionLabels[entrada.accion] ?? entrada.accion }}
                        </Badge>
                        <span class="text-theme-xs font-medium text-gray-700 dark:text-gray-300">{{ nombreAutor(entrada) }}</span>
                    </div>
                    <span class="text-theme-xs text-gray-400">{{ formatFechaHora(entrada.created_at) }}</span>
                </div>

                <!-- Cambio simple: estado / responsable / sector -->
                <p
                    v-if="comoCambioSimple(entrada.cambios)"
                    class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300"
                >
                    De <span class="font-medium text-gray-800 dark:text-white/90">{{ comoCambioSimple(entrada.cambios)!.de }}</span>
                    a <span class="font-medium text-gray-800 dark:text-white/90">{{ comoCambioSimple(entrada.cambios)!.a }}</span>
                </p>

                <!-- Clasificación: prioridad + tipo de caso juntos -->
                <dl v-else-if="esClasificacion(entrada)" class="mt-2 space-y-1 text-theme-sm text-gray-600 dark:text-gray-300">
                    <div>
                        Prioridad: de <span class="font-medium text-gray-800 dark:text-white/90">{{ entrada.cambios.prioridad.de }}</span>
                        a <span class="font-medium text-gray-800 dark:text-white/90">{{ entrada.cambios.prioridad.a }}</span>
                    </div>
                    <div>
                        Tipo de caso: de <span class="font-medium text-gray-800 dark:text-white/90">{{ entrada.cambios.tipo_caso.de }}</span>
                        a <span class="font-medium text-gray-800 dark:text-white/90">{{ entrada.cambios.tipo_caso.a }}</span>
                    </div>
                </dl>

                <!-- Usuarios a notificar: quiénes entraron y quiénes salieron -->
                <dl v-else-if="esNotificados(entrada)" class="mt-2 space-y-1 text-theme-sm text-gray-600 dark:text-gray-300">
                    <div v-if="entrada.cambios.sumados.length">
                        Se sumó a <span class="font-medium text-gray-800 dark:text-white/90">{{ entrada.cambios.sumados.join(', ') }}</span>
                    </div>
                    <div v-if="entrada.cambios.sacados.length">
                        Se sacó a <span class="font-medium text-gray-800 dark:text-white/90">{{ entrada.cambios.sacados.join(', ') }}</span>
                    </div>
                </dl>

                <!-- Baja: cancelación o borrado, el motivo va abajo en `nota` -->
                <p v-if="esBaja(entrada)" class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
                    {{ entrada.cambios.tipo === 'borrado' ? 'Observación borrada' : 'Observación cancelada' }}
                </p>

                <!-- Comentario / nota libre / motivo de una baja -->
                <p v-if="entrada.nota" class="mt-2 whitespace-pre-line text-theme-sm text-gray-600 dark:text-gray-300">
                    {{ entrada.nota }}
                </p>

                <!-- Adjuntos de esta entrada -->
                <ul v-if="entrada.adjuntos.length" class="mt-2 space-y-1">
                    <li v-for="a in entrada.adjuntos" :key="a.id">
                        <a
                            :href="route('observaciones.archivos.download', [observacionId, a.id])"
                            class="flex min-w-0 items-center gap-1.5 text-theme-xs text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                        >
                            <Icon name="paperclip" class="h-3.5 w-3.5 shrink-0 text-gray-400" />
                            <span class="truncate">{{ a.original_name }}</span>
                            <span class="shrink-0 text-gray-400">({{ formatSize(a.size) }})</span>
                        </a>
                    </li>
                </ul>
            </li>
        </ol>
        <p v-else class="text-theme-sm text-gray-400">Sin actividad todavía.</p>

        <div v-if="puedeEditar" :class="entradas.length ? 'mt-4 border-t border-gray-100 pt-4 dark:border-gray-800' : 'mt-4'">
            <Textarea
                v-model="form.nota"
                :rows="compacto ? 2 : 3"
                placeholder="Agregar un comentario…"
                :error="form.errors.nota"
            />

            <div
                class="mt-2 cursor-pointer rounded-xl border-2 border-dashed text-center transition-colors"
                :class="[
                    compacto ? 'px-3 py-3' : 'px-4 py-5',
                    isDragging
                        ? 'border-brand-400 bg-brand-50 dark:bg-brand-500/10'
                        : 'border-gray-300 hover:border-brand-300 dark:border-gray-700',
                ]"
                @click="fileInput?.click()"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="onDrop"
            >
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Arrastrá archivos o <span class="font-medium text-brand-500">buscalos en tu equipo</span>
                </p>
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="hidden"
                    accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                    @change="addFiles(($event.target as HTMLInputElement).files)"
                />
            </div>

            <ul v-if="form.archivos.length" class="mt-2 space-y-1.5">
                <li
                    v-for="(file, index) in form.archivos"
                    :key="index"
                    class="rounded-lg px-3 py-2 text-theme-xs"
                    :class="erroresArchivos[index]
                        ? 'bg-error-50 ring-1 ring-error-200 dark:bg-error-500/10 dark:ring-error-500/30'
                        : 'bg-gray-50 dark:bg-white/[0.03]'"
                >
                    <div class="flex items-center gap-2">
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
                    </div>
                    <p v-if="erroresArchivos[index]" class="mt-1 text-error-600 dark:text-error-400">
                        {{ erroresArchivos[index] }}
                    </p>
                </li>
            </ul>

            <Button
                variant="primary"
                class="mt-3"
                :disabled="form.processing || !puedeEnviar()"
                @click="enviar"
            >
                {{ form.processing ? 'Guardando…' : 'Agregar a la bitácora' }}
            </Button>
        </div>
    </div>
</template>
