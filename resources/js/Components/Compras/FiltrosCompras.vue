<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Input from '@/Components/Input.vue'
import Select from '@/Components/Select.vue'
import type { FiltrosCompras } from '@/lib/compras'
import { etiquetaMes } from '@/lib/formato'

const props = defineProps<{
    meses: string[]
    /** Categorías presentes en los datos, ya ordenadas por nombre. */
    categoriasPresentes: { codigo: string; nombre: string }[]
    opcionesObjetivo: number[]
}>()

const emit = defineEmits<{ limpiar: [] }>()

const filtros = defineModel<FiltrosCompras>({ required: true })

/** El buscador es local y con debounce: filtrar en cada tecla sobre 4.800 grupos se siente. */
const busqueda = ref(filtros.value.q)
let debounce: ReturnType<typeof setTimeout>

const buscar = (valor: string) => {
    busqueda.value = valor
    clearTimeout(debounce)
    debounce = setTimeout(() => (filtros.value = { ...filtros.value, q: valor }), 200)
}

onBeforeUnmount(() => clearTimeout(debounce))

const etiquetas = computed(() => props.meses.map(etiquetaMes))

const etiquetaObjetivo = (n: number) => (Number.isInteger(n) ? `${n} mes${n === 1 ? '' : 'es'}` : String(n).replace('.', ','))

/** Atajos de período. `0` = todo el historial. */
const preset = (n: number) => {
    const hasta = props.meses.length - 1
    filtros.value = { ...filtros.value, hasta, desde: n === 0 ? 0 : Math.max(0, props.meses.length - n) }
}

// Los dos extremos se empujan entre sí: elegir un "desde" posterior al "hasta"
// dejaría un período vacío y todos los productos en SIN VENTA.
const cambiarDesde = (valor: number) => {
    const desde = Number(valor)
    filtros.value = { ...filtros.value, desde, hasta: Math.max(desde, filtros.value.hasta) }
}

const cambiarHasta = (valor: number) => {
    const hasta = Number(valor)
    filtros.value = { ...filtros.value, hasta, desde: Math.min(hasta, filtros.value.desde) }
}

const menuAbierto = ref(false)
const menuRef = ref<HTMLElement | null>(null)

const cerrarSiEsAfuera = (e: MouseEvent) => {
    if (menuAbierto.value && menuRef.value && !menuRef.value.contains(e.target as Node)) {
        menuAbierto.value = false
    }
}

onMounted(() => document.addEventListener('click', cerrarSiEsAfuera))
onBeforeUnmount(() => document.removeEventListener('click', cerrarSiEsAfuera))

const etiquetaCategorias = computed(() => {
    const elegidas = filtros.value.cats
    if (elegidas.length === 0) return 'Todas'
    if (elegidas.length === 1) {
        return props.categoriasPresentes.find(c => c.codigo === elegidas[0])?.nombre ?? elegidas[0]!
    }
    return `${elegidas.length} seleccionadas`
})

const alternarCategoria = (codigo: string) => {
    const cats = filtros.value.cats.includes(codigo)
        ? filtros.value.cats.filter(c => c !== codigo)
        : [...filtros.value.cats, codigo]
    filtros.value = { ...filtros.value, cats }
}

const set = <K extends keyof FiltrosCompras>(clave: K, valor: FiltrosCompras[K]) => {
    filtros.value = { ...filtros.value, [clave]: valor }
}

const limpiar = () => {
    busqueda.value = ''
    clearTimeout(debounce)
    emit('limpiar')
}

const grupos: { clave: 'activo' | 'stock' | 'cubre' | 'pareto'; label: string; opciones: { v: string; t: string }[] }[] = [
    { clave: 'activo', label: 'Producto activo', opciones: [{ v: 'all', t: 'Todos' }, { v: 'si', t: 'Sí' }, { v: 'no', t: 'No' }] },
    { clave: 'stock', label: 'Con stock', opciones: [{ v: 'all', t: 'Todos' }, { v: 'si', t: 'Sí' }, { v: 'no', t: 'No' }] },
    { clave: 'cubre', label: 'Cubre el mes', opciones: [{ v: 'all', t: 'Todos' }, { v: 'si', t: 'Sí' }, { v: 'no', t: 'No' }, { v: 'sv', t: 'Sin venta' }] },
    { clave: 'pareto', label: 'Pareto (facturación)', opciones: [{ v: 'all', t: 'Total' }, { v: 'A', t: '80%' }, { v: 'B', t: '20%' }] },
]

const claseTog = (activo: boolean) =>
    activo
        ? 'border-brand-500 bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-300'
        : 'border-gray-300 bg-white text-gray-600 hover:border-brand-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'
</script>

<template>
    <div
        class="flex flex-wrap items-end gap-x-5 gap-y-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"
    >
        <!-- Meses objetivo -->
        <div class="flex flex-col gap-1.5">
            <label class="text-theme-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Meses de stock objetivo
            </label>
            <div class="inline-flex gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.06]">
                <button
                    v-for="n in opcionesObjetivo"
                    :key="n"
                    type="button"
                    class="cursor-pointer rounded-md px-3 py-1.5 text-theme-sm font-semibold transition"
                    :class="
                        filtros.mesesObjetivo === n
                            ? 'bg-brand-500 text-white shadow-theme-xs'
                            : 'text-gray-600 hover:text-brand-500 dark:text-gray-300'
                    "
                    @click="set('mesesObjetivo', n)"
                >
                    {{ etiquetaObjetivo(n) }}
                </button>
            </div>
        </div>

        <!-- Período -->
        <div class="flex flex-col gap-1.5">
            <label class="text-theme-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Período de venta a analizar
            </label>
            <div class="flex flex-wrap items-center gap-2">
                <Select
                    :model-value="filtros.desde"
                    class="w-28"
                    @update:model-value="cambiarDesde(Number($event))"
                >
                    <option v-for="(m, i) in etiquetas" :key="m" :value="i">{{ m }}</option>
                </Select>
                <span class="text-gray-400">→</span>
                <Select
                    :model-value="filtros.hasta"
                    class="w-28"
                    @update:model-value="cambiarHasta(Number($event))"
                >
                    <option v-for="(m, i) in etiquetas" :key="m" :value="i">{{ m }}</option>
                </Select>
                <button
                    v-for="p in [{ n: 3, t: 'Últ. 3' }, { n: 6, t: 'Últ. 6' }, { n: 12, t: 'Últ. 12' }, { n: 0, t: 'Todo' }]"
                    :key="p.t"
                    type="button"
                    class="cursor-pointer rounded-md border border-gray-300 px-2 py-1 text-theme-xs font-semibold text-gray-600 transition hover:border-brand-500 hover:text-brand-500 dark:border-gray-700 dark:text-gray-300"
                    @click="preset(p.n)"
                >
                    {{ p.t }}
                </button>
            </div>
        </div>

        <!-- Categorías -->
        <div ref="menuRef" class="relative flex flex-col gap-1.5">
            <label class="text-theme-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Categorías
            </label>
            <button
                type="button"
                class="flex min-w-[200px] cursor-pointer items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-theme-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                @click="menuAbierto = !menuAbierto"
            >
                <span class="truncate">{{ etiquetaCategorias }}</span>
                <span class="text-gray-400">▾</span>
            </button>
            <div
                v-if="menuAbierto"
                class="absolute top-full z-20 mt-1 max-h-80 w-72 overflow-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-theme-lg dark:border-gray-700 dark:bg-gray-800"
            >
                <p class="px-2 pb-1 pt-1.5 text-theme-xs text-gray-400">
                    Podés marcar <strong>varias categorías</strong> a la vez
                </p>
                <div class="flex justify-between gap-2 px-1 pb-1">
                    <button
                        type="button"
                        class="cursor-pointer px-1 text-theme-xs font-semibold text-brand-500 hover:underline"
                        @click="set('cats', categoriasPresentes.map(c => c.codigo))"
                    >
                        Seleccionar todas
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer px-1 text-theme-xs font-semibold text-brand-500 hover:underline"
                        @click="set('cats', [])"
                    >
                        Limpiar
                    </button>
                </div>
                <div class="my-1 h-px bg-gray-100 dark:bg-gray-700" />
                <label
                    v-for="c in categoriasPresentes"
                    :key="c.codigo"
                    class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-theme-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4 cursor-pointer accent-brand-500"
                        :checked="filtros.cats.includes(c.codigo)"
                        @change="alternarCategoria(c.codigo)"
                    >
                    {{ c.nombre }}
                </label>
            </div>
        </div>

        <!-- Botoneras de estado -->
        <div v-for="g in grupos" :key="g.clave" class="flex flex-col gap-1.5">
            <label class="text-theme-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ g.label }}
            </label>
            <div class="inline-flex gap-1.5">
                <button
                    v-for="o in g.opciones"
                    :key="o.v"
                    type="button"
                    class="cursor-pointer rounded-lg border px-2.5 py-1.5 text-theme-sm font-semibold transition"
                    :class="claseTog(filtros[g.clave] === o.v)"
                    @click="set(g.clave, o.v as never)"
                >
                    {{ o.t }}
                </button>
            </div>
        </div>

        <!-- Buscador -->
        <div class="w-56">
            <Input
                :model-value="busqueda"
                type="search"
                label="Buscar producto / código"
                placeholder="Ej: aguja 25/6 o RE-1574"
                autocomplete="off"
                @update:model-value="buscar(String($event ?? ''))"
            />
        </div>

        <!-- Servicios + limpiar -->
        <div class="flex flex-col gap-1.5">
            <label class="text-theme-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Servicios
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2 py-2 text-theme-sm font-semibold text-gray-600 dark:text-gray-300">
                <input
                    type="checkbox"
                    class="h-4 w-4 cursor-pointer accent-brand-500"
                    :checked="filtros.servicios"
                    @change="set('servicios', ($event.target as HTMLInputElement).checked)"
                >
                Incluir “no mueven stock”
            </label>
        </div>

        <button
            type="button"
            class="cursor-pointer rounded-lg border border-gray-300 px-3 py-2 text-theme-sm font-semibold text-gray-600 transition hover:border-brand-500 hover:text-brand-500 dark:border-gray-700 dark:text-gray-300"
            @click="limpiar"
        >
            Limpiar filtros
        </button>
    </div>
</template>
