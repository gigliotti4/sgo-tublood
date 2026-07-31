<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'

export interface ArticuloSugerido {
    codigo: string
    descripcion: string
}

/**
 * Autocompletado de artículos del catálogo de RP Sistemas.
 *
 * ⚠️ Es un combobox, no un select: el texto tipeado a mano vale igual aunque
 * no matchee ningún artículo. El catálogo puede no tener todo, y en el portal
 * público un reclamo nunca se puede perder porque el producto no esté cargado.
 * El desplegable es una ayuda para completar, no una restricción.
 *
 * Al elegir una sugerencia se emite el artículo entero, para que el formulario
 * complete también el nombre del producto sin que lo tipeen dos veces.
 */
const props = withDefaults(defineProps<{
    label?: string
    error?: string
    hint?: string
    required?: boolean
    id?: string
    /**
     * El portal público es siempre claro: el blade pone la clase `dark` en
     * <html> desde localStorage, así que un `dark:` acá se activaría también
     * ahí y quedaría un campo oscuro en medio de una página clara.
     */
    portal?: boolean
}>(), {
    portal: false,
})

const codigo = defineModel<string>({ required: true })

const emit = defineEmits<{ seleccionar: [ArticuloSugerido] }>()

const sugerencias = ref<ArticuloSugerido[]>([])
const abierto = ref(false)
const cargando = ref(false)
const resaltado = ref(-1)
// Se pisa en cada tecla para descartar respuestas que lleguen fuera de orden.
let peticion = 0
let debounce: ReturnType<typeof setTimeout>

const buscar = async (termino: string) => {
    const propia = ++peticion

    if (termino.trim().length < 2) {
        sugerencias.value = []
        abierto.value = false
        cargando.value = false

        return
    }

    cargando.value = true

    try {
        const r = await fetch(`${route('articulos.buscar')}?q=${encodeURIComponent(termino)}`, {
            headers: { Accept: 'application/json' },
        })

        // Llegó tarde: ya hay una búsqueda más nueva en curso.
        if (propia !== peticion) return

        sugerencias.value = r.ok ? await r.json() : []
        abierto.value = sugerencias.value.length > 0
        resaltado.value = -1
    } catch {
        // Sin catálogo el campo sigue siendo texto libre: no se muestra error.
        if (propia === peticion) {
            sugerencias.value = []
            abierto.value = false
        }
    } finally {
        if (propia === peticion) cargando.value = false
    }
}

watch(codigo, valor => {
    clearTimeout(debounce)
    debounce = setTimeout(() => buscar(valor ?? ''), 250)
})

onUnmounted(() => clearTimeout(debounce))

const elegir = (a: ArticuloSugerido) => {
    codigo.value = a.codigo
    // El watch de arriba dispararía otra búsqueda con el código ya elegido;
    // cerrar en el próximo tick evita que el desplegable reaparezca solo.
    clearTimeout(debounce)
    peticion++
    abierto.value = false
    sugerencias.value = []
    emit('seleccionar', a)
}

const alBajar = (e: KeyboardEvent) => {
    if (! abierto.value || sugerencias.value.length === 0) return

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        resaltado.value = (resaltado.value + 1) % sugerencias.value.length
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        resaltado.value = resaltado.value <= 0 ? sugerencias.value.length - 1 : resaltado.value - 1
    } else if (e.key === 'Enter' && resaltado.value >= 0) {
        e.preventDefault()
        elegir(sugerencias.value[resaltado.value])
    } else if (e.key === 'Escape') {
        abierto.value = false
    }
}

const claseInput = computed(() => {
    const base = 'h-11 w-full rounded-lg border bg-white px-4 py-2.5 text-[16px] shadow-theme-xs transition placeholder:text-gray-400 focus:outline-none focus:ring-3 sm:text-sm'
    const borde = props.error
        ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10'
        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10'

    if (props.portal) {
        return `${base} ${borde} text-gray-800`
    }

    return [
        base,
        'text-gray-800 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30',
        props.error
            ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10 dark:border-error-500/60'
            : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800',
    ].join(' ')
})
</script>

<template>
    <div class="relative">
        <label
            v-if="label"
            :for="id"
            class="mb-1.5 block text-sm font-medium"
            :class="portal ? 'text-gray-700' : 'text-gray-700 dark:text-gray-300'"
        >
            {{ label }}
            <span v-if="required" class="text-error-500">*</span>
        </label>

        <input
            :id="id"
            v-model="codigo"
            type="text"
            autocomplete="off"
            :class="claseInput"
            placeholder="Código o nombre del producto"
            @keydown="alBajar"
            @focus="sugerencias.length && (abierto = true)"
        />

        <span v-if="cargando" class="absolute right-3 top-[38px] text-theme-xs text-gray-400">buscando…</span>

        <!-- Cierre al tocar afuera; por debajo del desplegable. -->
        <div v-if="abierto" class="fixed inset-0 z-10" @click="abierto = false" />

        <ul
            v-if="abierto"
            class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border shadow-theme-lg"
            :class="portal ? 'border-gray-200 bg-white' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900'"
        >
            <li v-for="(a, i) in sugerencias" :key="a.codigo">
                <button
                    type="button"
                    class="block w-full cursor-pointer px-3 py-2 text-left text-theme-sm transition-colors"
                    :class="[
                        portal ? 'text-gray-700 hover:bg-gray-50' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]',
                        i === resaltado ? (portal ? 'bg-gray-100' : 'bg-gray-100 dark:bg-white/[0.08]') : '',
                    ]"
                    @click="elegir(a)"
                >
                    <span class="font-mono text-theme-xs text-gray-400">{{ a.codigo }}</span>
                    <span class="ml-2">{{ a.descripcion }}</span>
                </button>
            </li>
        </ul>

        <p v-if="error" class="mt-1.5 text-xs text-error-500" :class="portal ? '' : 'dark:text-error-400'">{{ error }}</p>
        <p
            v-else-if="hint"
            class="mt-1.5 text-xs"
            :class="portal ? 'text-gray-500' : 'text-gray-500 dark:text-gray-400'"
        >
            {{ hint }}
        </p>
    </div>
</template>
