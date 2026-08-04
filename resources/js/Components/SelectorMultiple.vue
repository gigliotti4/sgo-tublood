<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'

export interface OpcionSeleccionable {
    id: number
    label: string
}

/**
 * Combobox múltiple compacto: cerrado ocupa el alto de un `Select`, abierto
 * muestra un buscador + lista de checkboxes en un popover.
 *
 * Combina el popover/teclado de `SelectorArticulo.vue` con la selección
 * múltiple de `SelectorUsuarios.vue`. No hace falta un tercer componente
 * "grande" para formularios (`SelectorUsuarios` sigue siendo el que corresponde
 * ahí, con la lista siempre visible) — este es específico para barras de
 * filtros, donde el alto de fila importa.
 */
const props = defineProps<{
    opciones: OpcionSeleccionable[]
    label?: string
    placeholder?: string
}>()

const seleccionados = defineModel<number[]>({ required: true })

const abierto = ref(false)
const busqueda = ref('')
const resaltado = ref(-1)
const contenedor = ref<HTMLElement | null>(null)
const inputBusqueda = ref<HTMLInputElement | null>(null)

const disponibles = computed(() => {
    const termino = busqueda.value.trim().toLowerCase()

    if (!termino) return props.opciones

    return props.opciones.filter(o => o.label.toLowerCase().includes(termino))
})

const etiquetaCerrado = computed(() => {
    if (seleccionados.value.length === 0) return props.placeholder ?? 'Todos'
    if (seleccionados.value.length === 1) {
        return props.opciones.find(o => o.id === seleccionados.value[0])?.label ?? '1 seleccionado'
    }

    return `${seleccionados.value.length} seleccionados`
})

const toggle = (id: number) => {
    seleccionados.value = seleccionados.value.includes(id)
        ? seleccionados.value.filter(v => v !== id)
        : [...seleccionados.value, id]
}

const abrir = () => {
    abierto.value = true
    resaltado.value = -1
    // El input recién existe en el DOM después de que el v-if de abajo
    // renderice: sin el nextTick, el focus() se pierde contra un elemento que
    // todavía no está montado.
    nextTick(() => inputBusqueda.value?.focus())
}

const cerrar = () => {
    abierto.value = false
    busqueda.value = ''
}

watch(disponibles, () => { resaltado.value = -1 })

const alTeclado = (e: KeyboardEvent) => {
    if (!abierto.value) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            e.preventDefault()
            abrir()
        }

        return
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        resaltado.value = (resaltado.value + 1) % Math.max(disponibles.value.length, 1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        resaltado.value = resaltado.value <= 0 ? disponibles.value.length - 1 : resaltado.value - 1
    } else if (e.key === 'Enter' && resaltado.value >= 0 && disponibles.value[resaltado.value]) {
        e.preventDefault()
        toggle(disponibles.value[resaltado.value].id)
    } else if (e.key === 'Escape') {
        e.preventDefault()
        cerrar()
    }
}

// Cierre al tocar afuera del componente entero (botón + popover), no solo del
// popover: un click en el botón cerrado no tiene que contar como "afuera".
const alClickFuera = (e: MouseEvent) => {
    if (contenedor.value && !contenedor.value.contains(e.target as Node)) cerrar()
}

watch(abierto, val => {
    if (val) document.addEventListener('click', alClickFuera)
    else document.removeEventListener('click', alClickFuera)
})

onUnmounted(() => document.removeEventListener('click', alClickFuera))
</script>

<template>
    <div ref="contenedor" class="relative">
        <label v-if="label" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ label }}</label>

        <button
            type="button"
            class="flex h-11 w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-left text-[16px] text-gray-800 shadow-theme-xs transition focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800 sm:text-sm"
            @click="abierto ? cerrar() : abrir()"
            @keydown="alTeclado"
        >
            <span class="truncate" :class="seleccionados.length === 0 ? 'text-gray-400 dark:text-white/30' : ''">
                {{ etiquetaCerrado }}
            </span>
            <svg class="h-4 w-4 shrink-0 text-gray-500 dark:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div
            v-if="abierto"
            class="absolute z-20 mt-1 w-full rounded-lg border border-gray-200 bg-white p-2 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900"
        >
            <input
                ref="inputBusqueda"
                v-model="busqueda"
                type="search"
                placeholder="Buscar…"
                class="mb-2 h-9 w-full rounded-md border border-gray-300 bg-white px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white/90"
                @keydown="alTeclado"
            />

            <ul class="max-h-56 overflow-y-auto">
                <li v-for="(o, i) in disponibles" :key="o.id">
                    <label
                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                        :class="i === resaltado ? 'bg-gray-100 dark:bg-white/[0.08]' : ''"
                    >
                        <input
                            type="checkbox"
                            :checked="seleccionados.includes(o.id)"
                            class="h-4 w-4 shrink-0 rounded accent-brand-500 dark:accent-brand-400"
                            @change="toggle(o.id)"
                        />
                        {{ o.label }}
                    </label>
                </li>
                <li v-if="disponibles.length === 0" class="px-2 py-3 text-center text-theme-xs text-gray-400">
                    No hay resultados para "{{ busqueda }}".
                </li>
            </ul>
        </div>
    </div>
</template>
