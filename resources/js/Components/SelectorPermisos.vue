<script setup lang="ts">
import { computed } from 'vue'
import type { PermisoEtiquetado } from '@/types'

/**
 * Checkboxes de permisos agrupados por área, compartidos por el alta y la
 * edición de roles (antes era una lista plana de 14 duplicada en las dos
 * pantallas). Las etiquetas en español y el grupo salen de
 * config/permisos.php; el value sigue siendo el nombre técnico, que es lo que
 * espera `syncPermissions`.
 */
const props = defineProps<{
    permisos: PermisoEtiquetado[]
    error?: string
}>()

const seleccionados = defineModel<string[]>({ required: true })

const grupos = computed(() => {
    const mapa = new Map<string, PermisoEtiquetado[]>()

    for (const permiso of props.permisos) {
        const actuales = mapa.get(permiso.grupo) ?? []
        actuales.push(permiso)
        mapa.set(permiso.grupo, actuales)
    }

    return [...mapa.entries()].map(([nombre, items]) => ({ nombre, items }))
})

const todosMarcados = (items: PermisoEtiquetado[]) =>
    items.every(p => seleccionados.value.includes(p.name))

/** Marca o desmarca un grupo entero de una. */
const alternarGrupo = (items: PermisoEtiquetado[]) => {
    const nombres = items.map(p => p.name)

    seleccionados.value = todosMarcados(items)
        ? seleccionados.value.filter(n => !nombres.includes(n))
        : [...new Set([...seleccionados.value, ...nombres])]
}
</script>

<template>
    <div>
        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Permisos</label>

        <div class="space-y-4">
            <div v-for="grupo in grupos" :key="grupo.nombre">
                <div class="mb-1.5 flex items-center justify-between gap-3">
                    <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">{{ grupo.nombre }}</p>
                    <button
                        type="button"
                        class="text-theme-xs font-medium text-brand-500 transition hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                        @click="alternarGrupo(grupo.items)"
                    >
                        {{ todosMarcados(grupo.items) ? 'Ninguno' : 'Todos' }}
                    </button>
                </div>
                <div class="grid gap-1.5 sm:grid-cols-2">
                    <label
                        v-for="permiso in grupo.items"
                        :key="permiso.id"
                        class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300"
                        :title="permiso.name"
                    >
                        <input
                            v-model="seleccionados"
                            type="checkbox"
                            :value="permiso.name"
                            class="h-4 w-4 shrink-0 rounded accent-brand-500 dark:accent-brand-400"
                        />
                        {{ permiso.label }}
                    </label>
                </div>
            </div>
        </div>

        <p v-if="error" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ error }}</p>
    </div>
</template>
