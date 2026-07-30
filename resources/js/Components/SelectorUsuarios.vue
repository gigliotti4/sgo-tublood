<script setup lang="ts">
import { computed, ref } from 'vue'

export interface UsuarioSeleccionable {
    id: number
    name: string
    apellido: string | null
}

/**
 * Checkboxes para elegir varios usuarios (los "a notificar" de una
 * observación). Mismo patrón que SelectorPermisos.vue, más un buscador: la
 * lista de usuarios es larga y no está agrupada por nada útil para este caso.
 */
const props = defineProps<{
    usuarios: UsuarioSeleccionable[]
    label?: string
    hint?: string
    error?: string
    /** Se saca de la lista: ya está asignado como responsable, no hace falta avisarle aparte. */
    excluirId?: number | null
    /** Ocupa las dos columnas de un FormSection, igual que el resto de los campos. */
    full?: boolean
}>()

const seleccionados = defineModel<number[]>({ required: true })

const busqueda = ref('')

const nombreCompleto = (u: UsuarioSeleccionable) => [u.name, u.apellido].filter(Boolean).join(' ')

const disponibles = computed(() => {
    const termino = busqueda.value.trim().toLowerCase()

    return props.usuarios.filter(u => {
        if (props.excluirId && u.id === props.excluirId) return false
        if (!termino) return true

        return nombreCompleto(u).toLowerCase().includes(termino)
    })
})

// Los ya elegidos se muestran siempre, aunque el filtro de búsqueda los deje
// fuera: si no, tildar a alguien y después buscar otra cosa da la impresión de
// que se perdió la selección.
const elegidos = computed(() =>
    props.usuarios.filter(u => seleccionados.value.includes(u.id)),
)
</script>

<template>
    <div :class="full ? 'sm:col-span-2' : ''">
        <label v-if="label" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ label }}</label>

        <input
            v-model="busqueda"
            type="search"
            placeholder="Buscar persona…"
            class="mb-2 h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-[16px] text-gray-800 shadow-theme-xs transition placeholder:text-gray-400 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800 sm:text-sm"
        />

        <div class="max-h-48 space-y-0.5 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-800">
            <label
                v-for="u in disponibles"
                :key="u.id"
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]"
            >
                <input
                    v-model="seleccionados"
                    type="checkbox"
                    :value="u.id"
                    class="h-4 w-4 shrink-0 rounded accent-brand-500 dark:accent-brand-400"
                />
                {{ nombreCompleto(u) }}
            </label>

            <p v-if="disponibles.length === 0" class="px-2 py-3 text-center text-theme-xs text-gray-400">
                No hay resultados para "{{ busqueda }}".
            </p>
        </div>

        <p v-if="elegidos.length > 0" class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
            {{ elegidos.length }} seleccionada{{ elegidos.length !== 1 ? 's' : '' }}:
            {{ elegidos.map(nombreCompleto).join(', ') }}
        </p>

        <p v-if="error" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ hint }}</p>
    </div>
</template>
