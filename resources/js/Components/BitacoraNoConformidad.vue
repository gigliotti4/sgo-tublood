<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Textarea from '@/Components/Textarea.vue'
import {
    accionLabels,
    accionVariant,
    comoAvance,
    comoCambioDeEstado,
    comoObservaciones,
    comoResponsable,
    comoVerificacion,
    nombreAutor,
    numeroAsignado,
} from '@/lib/nc'
import type { NonConformityHistoryEntry } from '@/types'

/**
 * Bitácora de una No Conformidad.
 *
 * Es de solo lectura sobre lo ya escrito: no hay editar ni borrar, porque §7
 * del instructivo exige que el historial no se pueda eliminar ni reemplazar
 * (y el modelo lo hace cumplir del lado del servidor). Lo único que se puede
 * hacer es agregar una entrada nueva.
 */
const props = defineProps<{
    noConformidadId: number
    entradas: NonConformityHistoryEntry[]
}>()

const form = useForm({ nota: '', archivos: [] as File[] })
const inputArchivos = ref<HTMLInputElement | null>(null)

const agregarArchivos = (lista: FileList | null) => {
    if (!lista) return
    form.archivos = [...form.archivos, ...Array.from(lista)]
}

const quitarArchivo = (i: number) => {
    form.archivos = form.archivos.filter((_, idx) => idx !== i)
}

const puedeEnviar = () => form.nota.trim() !== '' || form.archivos.length > 0

const enviar = () => {
    if (!puedeEnviar()) return

    form.post(route('no-conformidades.bitacora.store', props.noConformidadId), {
        forceFormData: true,
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    })
}

const formatFechaHora = (d: string) =>
    new Date(d).toLocaleString('es-AR', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    })

const formatSize = (bytes: number | null) => {
    if (!bytes) return ''
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Bitácora</p>

        <ol v-if="entradas.length" class="mt-4 space-y-4">
            <li
                v-for="entrada in entradas"
                :key="entrada.id"
                class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <Badge :variant="accionVariant[entrada.accion] ?? 'slate'">
                            {{ accionLabels[entrada.accion] ?? entrada.accion }}
                        </Badge>
                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ nombreAutor(entrada) }}</span>
                    </div>
                    <span class="text-theme-xs text-gray-400">{{ formatFechaHora(entrada.created_at) }}</span>
                </div>

                <!--
                    ⚠️ El cambio de responsable va PRIMERO: comparte la forma
                    `{de, a}` con el cambio de estado, y si no se leería como uno.
                -->
                <p
                    v-if="comoResponsable(entrada)"
                    class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300"
                >
                    <template v-if="comoResponsable(entrada)!.a">
                        Pasó a estar a cargo de <strong>{{ comoResponsable(entrada)!.a }}</strong>
                        <template v-if="comoResponsable(entrada)!.de">
                            (antes: {{ comoResponsable(entrada)!.de }})
                        </template>
                    </template>
                    <template v-else>
                        Quedó sin responsable asignado: la gestiona Garantía de Calidad.
                    </template>
                </p>

                <!-- Cambio de estado -->
                <p
                    v-else-if="comoCambioDeEstado(entrada.cambios)"
                    class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300"
                >
                    De <strong>{{ comoCambioDeEstado(entrada.cambios)!.de }}</strong>
                    a <strong>{{ comoCambioDeEstado(entrada.cambios)!.a }}</strong>
                </p>

                <!-- Aprobación: el número que quedó asignado -->
                <p
                    v-else-if="numeroAsignado(entrada)"
                    class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300"
                >
                    Quedó registrada como <strong class="font-mono">{{ numeroAsignado(entrada) }}</strong>
                </p>

                <!-- Vínculo con observaciones -->
                <div v-else-if="comoObservaciones(entrada)" class="mt-2 space-y-1 text-theme-sm text-gray-600 dark:text-gray-300">
                    <p v-if="comoObservaciones(entrada)!.sumadas.length">
                        Se vinculó: <strong>{{ comoObservaciones(entrada)!.sumadas.join(', ') }}</strong>
                    </p>
                    <p v-if="comoObservaciones(entrada)!.sacadas.length">
                        Se desvinculó: <strong>{{ comoObservaciones(entrada)!.sacadas.join(', ') }}</strong>
                    </p>
                </div>

                <!-- Avance de una acción del plan -->
                <p
                    v-else-if="comoAvance(entrada)"
                    class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300"
                >
                    <strong>{{ comoAvance(entrada)!.estado }}</strong> · {{ comoAvance(entrada)!.accion }}
                </p>

                <!-- Verificación de eficacia -->
                <p
                    v-else-if="comoVerificacion(entrada)"
                    class="mt-2 text-theme-sm text-gray-600 dark:text-gray-300"
                >
                    Resultado: <strong>{{ comoVerificacion(entrada) }}</strong>
                </p>

                <!-- Texto libre: comentario, o el motivo de una devolución/rechazo -->
                <p v-if="entrada.nota" class="mt-2 whitespace-pre-line text-theme-sm text-gray-700 dark:text-gray-200">
                    {{ entrada.nota }}
                </p>

                <ul v-if="entrada.adjuntos.length" class="mt-2 space-y-1">
                    <li
                        v-for="a in entrada.adjuntos"
                        :key="a.id"
                        class="truncate text-theme-xs text-gray-500 dark:text-gray-400"
                    >
                        📎 {{ a.original_name }}
                        <span class="text-gray-400">{{ formatSize(a.size) }}</span>
                    </li>
                </ul>
            </li>
        </ol>
        <p v-else class="mt-2 text-theme-sm text-gray-400">Sin actividad todavía.</p>

        <!-- Comentario nuevo -->
        <form class="mt-5 space-y-3 border-t border-gray-100 pt-5 dark:border-gray-800" @submit.prevent="enviar">
            <Textarea
                v-model="form.nota"
                label="Agregar a la bitácora"
                :rows="3"
                placeholder="Comentario, avance, aclaración…"
                :error="form.errors.nota"
            />

            <input
                ref="inputArchivos"
                type="file"
                multiple
                class="hidden"
                accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                @change="agregarArchivos(($event.target as HTMLInputElement).files)"
            >

            <ul v-if="form.archivos.length" class="space-y-2">
                <li
                    v-for="(a, i) in form.archivos"
                    :key="i"
                    class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-theme-xs dark:border-gray-700"
                >
                    <span class="truncate text-gray-600 dark:text-gray-300">{{ a.name }}</span>
                    <button type="button" class="cursor-pointer text-error-500 hover:underline" @click="quitarArchivo(i)">
                        Quitar
                    </button>
                </li>
            </ul>

            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="cursor-pointer text-theme-xs text-brand-500 hover:underline dark:text-brand-300"
                    @click="inputArchivos?.click()"
                >
                    Adjuntar archivo
                </button>
                <Button type="submit" :disabled="form.processing || !puedeEnviar()">Agregar</Button>
            </div>
        </form>
    </div>
</template>
