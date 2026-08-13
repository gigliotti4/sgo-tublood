<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'

export interface OpcionAsync {
    id: string
    label: string
}

/**
 * Combobox múltiple con búsqueda remota debounced (a diferencia de
 * `SelectorMultiple.vue`, que filtra client-side sobre un catálogo ya
 * cargado como prop — no sirve para catálogos de miles de filas como
 * artículos). Mezcla el fetch de `SelectorArticulo.vue` con el popover de
 * chips/checkboxes de `SelectorMultiple.vue`.
 *
 * `mapear` traduce cada fila cruda de la respuesta del endpoint a `{id, label}`.
 */
const props = defineProps<{
    route: string
    mapear: (item: unknown) => OpcionAsync
    label?: string
    placeholder?: string
}>()

const seleccionados = defineModel<string[]>({ required: true })

// Guarda el label de cada id elegido, aunque salga de los resultados de
// búsqueda vigentes: hace falta para mostrar el resumen y los chips.
const etiquetas = ref(new Map<string, string>())

const abierto = ref(false)
const busqueda = ref('')
const cargando = ref(false)
const resultados = ref<OpcionAsync[]>([])
const resaltado = ref(-1)
const contenedor = ref<HTMLElement | null>(null)
const inputBusqueda = ref<HTMLInputElement | null>(null)

let peticion = 0
let debounce: ReturnType<typeof setTimeout>

const buscar = async (termino: string) => {
    const propia = ++peticion

    if (termino.trim().length < 2) {
        resultados.value = []
        cargando.value = false

        return
    }

    cargando.value = true

    try {
        const r = await fetch(`${route(props.route)}?q=${encodeURIComponent(termino)}`, {
            headers: { Accept: 'application/json' },
        })

        if (propia !== peticion) return

        const datos = r.ok ? await r.json() : []
        resultados.value = (datos as unknown[]).map(props.mapear)
        resultados.value.forEach(o => etiquetas.value.set(o.id, o.label))
        resaltado.value = -1
    } catch {
        if (propia === peticion) resultados.value = []
    } finally {
        if (propia === peticion) cargando.value = false
    }
}

watch(busqueda, val => {
    clearTimeout(debounce)
    debounce = setTimeout(() => buscar(val), 250)
})

onUnmounted(() => clearTimeout(debounce))

const etiquetaCerrado = computed(() => {
    if (seleccionados.value.length === 0) return props.placeholder ?? 'Todos'
    if (seleccionados.value.length === 1) {
        return etiquetas.value.get(seleccionados.value[0]) ?? '1 seleccionado'
    }

    return `${seleccionados.value.length} seleccionados`
})

const toggle = (o: OpcionAsync) => {
    etiquetas.value.set(o.id, o.label)
    seleccionados.value = seleccionados.value.includes(o.id)
        ? seleccionados.value.filter(v => v !== o.id)
        : [...seleccionados.value, o.id]
}

const quitar = (id: string) => {
    seleccionados.value = seleccionados.value.filter(v => v !== id)
}

const abrir = () => {
    abierto.value = true
    resaltado.value = -1
    nextTick(() => inputBusqueda.value?.focus())
}

const cerrar = () => {
    abierto.value = false
    busqueda.value = ''
    resultados.value = []
}

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
        resaltado.value = (resaltado.value + 1) % Math.max(resultados.value.length, 1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        resaltado.value = resaltado.value <= 0 ? resultados.value.length - 1 : resaltado.value - 1
    } else if (e.key === 'Enter' && resaltado.value >= 0 && resultados.value[resaltado.value]) {
        e.preventDefault()
        toggle(resultados.value[resaltado.value])
    } else if (e.key === 'Escape') {
        e.preventDefault()
        cerrar()
    }
}

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

            <ul v-if="seleccionados.length" class="mb-2 flex flex-wrap gap-1 border-b border-gray-100 pb-2 dark:border-gray-800">
                <li
                    v-for="id in seleccionados"
                    :key="id"
                    class="flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-theme-xs text-gray-700 dark:bg-white/[0.08] dark:text-gray-300"
                >
                    {{ etiquetas.get(id) ?? id }}
                    <button type="button" class="cursor-pointer text-gray-400 hover:text-error-500" @click="quitar(id)">✕</button>
                </li>
            </ul>

            <p v-if="cargando" class="px-2 py-1 text-theme-xs text-gray-400">Buscando…</p>

            <ul v-else class="max-h-56 overflow-y-auto">
                <li v-for="(o, i) in resultados" :key="o.id">
                    <label
                        class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                        :class="i === resaltado ? 'bg-gray-100 dark:bg-white/[0.08]' : ''"
                    >
                        <input
                            type="checkbox"
                            :checked="seleccionados.includes(o.id)"
                            class="h-4 w-4 shrink-0 rounded accent-brand-500 dark:accent-brand-400"
                            @change="toggle(o)"
                        />
                        {{ o.label }}
                    </label>
                </li>
                <li v-if="resultados.length === 0" class="px-2 py-3 text-center text-theme-xs text-gray-400">
                    {{ busqueda.trim().length < 2 ? 'Escribí al menos 2 caracteres para buscar.' : `Sin resultados para "${busqueda}".` }}
                </li>
            </ul>
        </div>
    </div>
</template>
