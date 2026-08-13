<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { route } from 'ziggy-js'

interface ProveedorSugerido {
    id: number
    /** `null` en los que creó el import de artículos, que no trae el NUM_PROV. */
    numero: string | null
    razon_social: string
}

/**
 * Combobox single con búsqueda remota debounced para elegir el proveedor de un
 * artículo. Comparte la mecánica con `SelectorMultipleAsync.vue` (debounce,
 * descarte de respuestas fuera de orden, teclado, click afuera).
 *
 * A diferencia de `SelectorArticulo.vue`, que es un combobox de texto libre
 * donde lo tipeado vale aunque no matchee, acá el valor es una FK: o es el id
 * de un proveedor del padrón, o es `null`.
 */
const props = defineProps<{
    label?: string
    error?: string
    hint?: string
    /** Proveedor ya guardado, para mostrar su razón social sin tener que buscarlo. */
    inicial?: { id: number; label: string } | null
}>()

const seleccionado = defineModel<number | null>({ required: true })

// Guarda el label de cada id visto, aunque salga de los resultados vigentes:
// hace falta para seguir mostrando el elegido con el buscador vacío.
const etiquetas = ref(new Map<number, string>(
    props.inicial ? [[props.inicial.id, props.inicial.label]] : []
))

const abierto = ref(false)
const busqueda = ref('')
const cargando = ref(false)
const resultados = ref<ProveedorSugerido[]>([])
const resaltado = ref(-1)
const contenedor = ref<HTMLElement | null>(null)
const inputBusqueda = ref<HTMLInputElement | null>(null)

let peticion = 0
let debounce: ReturnType<typeof setTimeout>

const etiquetaDe = (p: ProveedorSugerido) =>
    p.numero ? `${p.razon_social} (N° ${p.numero})` : `${p.razon_social} (sin número)`

const buscar = async (termino: string) => {
    const propia = ++peticion

    if (termino.trim().length < 2) {
        resultados.value = []
        cargando.value = false

        return
    }

    cargando.value = true

    try {
        const r = await fetch(`${route('proveedores.buscar')}?q=${encodeURIComponent(termino)}`, {
            headers: { Accept: 'application/json' },
        })

        if (propia !== peticion) return

        const datos = r.ok ? await r.json() : []
        resultados.value = datos as ProveedorSugerido[]
        resultados.value.forEach(p => etiquetas.value.set(p.id, etiquetaDe(p)))
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
    if (seleccionado.value === null) return 'Sin proveedor'

    return etiquetas.value.get(seleccionado.value) ?? `Proveedor #${seleccionado.value}`
})

const elegir = (p: ProveedorSugerido) => {
    etiquetas.value.set(p.id, etiquetaDe(p))
    seleccionado.value = p.id
    cerrar()
}

const quitar = () => {
    seleccionado.value = null
    cerrar()
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
        elegir(resultados.value[resaltado.value])
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
            <span class="truncate" :class="seleccionado === null ? 'text-gray-400 dark:text-white/30' : ''">
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
                placeholder="Buscar por razón social o número…"
                class="mb-2 h-9 w-full rounded-md border border-gray-300 bg-white px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white/90"
                @keydown="alTeclado"
            />

            <button
                v-if="seleccionado !== null"
                type="button"
                class="mb-2 w-full cursor-pointer rounded-md border-b border-gray-100 px-2 pb-2 text-left text-theme-xs text-gray-500 transition-colors hover:text-error-500 dark:border-gray-800 dark:text-gray-400"
                @click="quitar"
            >
                ✕ Quitar proveedor
            </button>

            <p v-if="cargando" class="px-2 py-1 text-theme-xs text-gray-400">Buscando…</p>

            <ul v-else class="max-h-56 overflow-y-auto">
                <li v-for="(p, i) in resultados" :key="p.id">
                    <button
                        type="button"
                        class="w-full cursor-pointer rounded-md px-2 py-1.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                        :class="[
                            i === resaltado ? 'bg-gray-100 dark:bg-white/[0.08]' : '',
                            p.id === seleccionado ? 'font-medium text-brand-500 dark:text-brand-300' : '',
                        ]"
                        @click="elegir(p)"
                    >
                        {{ p.razon_social }}
                        <span class="block font-mono text-theme-xs text-gray-400">{{ p.numero ? `N° ${p.numero}` : 'sin número' }}</span>
                    </button>
                </li>
                <li v-if="resultados.length === 0" class="px-2 py-3 text-center text-theme-xs text-gray-400">
                    {{ busqueda.trim().length < 2 ? 'Escribí al menos 2 caracteres para buscar.' : `Sin resultados para "${busqueda}".` }}
                </li>
            </ul>
        </div>

        <p v-if="error" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ hint }}</p>
    </div>
</template>
