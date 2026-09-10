<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import Select from '@/Components/Select.vue'
import KpisCompras from '@/Components/Compras/KpisCompras.vue'
import FiltrosComprasPanel from '@/Components/Compras/FiltrosCompras.vue'
import TablaReposicion from '@/Components/Compras/TablaReposicion.vue'
import { usePermissions } from '@/composables/usePermissions'
import {
    calcularKpis,
    calcularTotales,
    filtrar,
    filtrosPorDefecto,
    ordenar,
    type ClaveOrden,
    type FiltrosCompras,
} from '@/lib/compras'
import { exportarDetalle, exportarResumen } from '@/lib/comprasCsv'
import { etiquetaMes, fechaHora, numero } from '@/lib/formato'
import type { GrupoReposicion } from '@/types'

/**
 * Tablero de reposición de stock.
 *
 * ⚠️ Recibe el dataset ENTERO (~4.800 productos, ~185 KB gzip) y filtra, ordena
 * y pagina en memoria, sin volver al servidor. No es un descuido del patrón de
 * los otros listados: los KPIs y la fila de TOTAL se calculan sobre todo el
 * conjunto filtrado y el Pareto se recalcula sobre ese mismo conjunto, así que
 * el servidor tendría que recorrer los 4.800 grupos en cada request igual —
 * pero con un roundtrip por cada click de ordenamiento.
 */
const props = defineProps<{
    meses: string[]
    groups: GrupoReposicion[]
    categorias: Record<string, string>
    mesesObjetivo: number[]
    mesesObjetivoDefault: number
    lastSync: { articulos: string | null; ordenes: string | null; pedidos: string | null; ventas: string | null }
    reservaSinConciliar: boolean
}>()

const { hasPermission } = usePermissions()

/** `shallowRef`: son 4.800 objetos con arrays adentro y nunca se mutan. Hacerlos reactivos en profundidad cuesta ~100 ms de arranque y no aporta nada. */
const groups = shallowRef(props.groups)

const catName = (codigo: string): string => props.categorias[codigo] ?? codigo

const filtros = ref<FiltrosCompras>(
    filtrosPorDefecto(props.mesesObjetivoDefault, Math.max(0, props.meses.length - 1)),
)

const orden = ref<{ key: ClaveOrden | null; dir: 'asc' | 'desc' }>({ key: null, dir: 'desc' })
const mostrarMeses = ref(false)
const pagina = ref(0)
const porPagina = ref(100)

const limpiar = () => {
    filtros.value = filtrosPorDefecto(props.mesesObjetivoDefault, Math.max(0, props.meses.length - 1))
}

const hayFiltros = computed(() => {
    const f = filtros.value
    return !!(
        f.q || f.cats.length || f.activo !== 'all' || f.stock !== 'all'
        || f.cubre !== 'all' || f.pareto !== 'all' || f.servicios
        || f.desde !== 0 || f.hasta !== props.meses.length - 1
    )
})

const resultado = computed(() => filtrar(groups.value, filtros.value, props.meses.length, catName))

const filasOrdenadas = computed(() =>
    ordenar(resultado.value.filas, orden.value.key, orden.value.dir, catName),
)

// KPIs y TOTAL sobre TODO el conjunto filtrado — el paginado no los mueve.
const kpis = computed(() => calcularKpis(resultado.value.filas))
const totales = computed(() => calcularTotales(resultado.value.filas, props.meses.length))

const totalPaginas = computed(() => Math.max(1, Math.ceil(filasOrdenadas.value.length / porPagina.value)))

const filasVisibles = computed(() =>
    filasOrdenadas.value.slice(pagina.value * porPagina.value, (pagina.value + 1) * porPagina.value),
)

// Cambiar un filtro puede dejar la página actual fuera de rango.
watch([filtros, porPagina], () => (pagina.value = 0), { deep: true })
watch(totalPaginas, total => {
    if (pagina.value > total - 1) pagina.value = Math.max(0, total - 1)
})

const irA = (p: number) => {
    pagina.value = Math.min(Math.max(0, p), totalPaginas.value - 1)
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

const ordenarPor = (key: ClaveOrden) => {
    orden.value = orden.value.key === key
        ? { key, dir: orden.value.dir === 'asc' ? 'desc' : 'asc' }
        // Texto arranca A→Z; los números, de mayor a menor (que es lo que se busca).
        : { key, dir: key === 'gtin' || key === 'cat' ? 'asc' : 'desc' }
}

/** Solo las categorías que existen en los datos: ofrecer las 23 del catálogo confundiría. */
const categoriasPresentes = computed(() =>
    [...new Set(groups.value.flatMap(g => g.c))]
        .map(codigo => ({ codigo, nombre: catName(codigo) }))
        .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es')),
)

const desdeFila = computed(() => (filasOrdenadas.value.length ? pagina.value * porPagina.value + 1 : 0))
const hastaFila = computed(() => Math.min(filasOrdenadas.value.length, (pagina.value + 1) * porPagina.value))

/**
 * El último mes casi siempre está incompleto (corre el mes en curso) y baja el
 * promedio mensual sin que se note. Se avisa arriba en vez de recortarlo solo:
 * a fin de mes ese dato sí sirve.
 */
const ultimoMes = computed(() => etiquetaMes(props.meses[props.meses.length - 1] ?? ''))

const sincronizando = ref(false)
const sincronizar = () => {
    sincronizando.value = true
    router.post(route('compras.sync'), {}, { onFinish: () => (sincronizando.value = false) })
}

// Los exports reciben el conjunto filtrado y ordenado COMPLETO, no la página.
const bajarResumen = () => exportarResumen(filasOrdenadas.value, filtros.value, props.meses, catName)
const bajarDetalle = () => exportarDetalle(filasOrdenadas.value, filtros.value, props.meses, catName)

const sincronizacionMasVieja = computed(() => {
    const sellos = Object.values(props.lastSync).filter((s): s is string => !!s)
    return sellos.length ? sellos.sort()[0]! : null
})
</script>

<template>
    <Head title="Compras" />

    <AppLayout>
        <div class="space-y-4">
            <!-- Encabezado -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">
                        Compras · Reposición de stock
                    </h1>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Actualizado {{ fechaHora(sincronizacionMasVieja) }} ·
                        {{ numero(groups.length) }} productos ·
                        ventas de {{ etiquetaMes(meses[0] ?? '') }} a {{ ultimoMes }} ({{ meses.length }} meses)
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Button variant="outline" @click="bajarResumen">⬇ Excel · resumen</Button>
                    <Button variant="outline" @click="bajarDetalle">⬇ Excel · detalle</Button>
                    <Button
                        v-if="hasPermission('compras.sync')"
                        variant="brand"
                        :disabled="sincronizando"
                        @click="sincronizar"
                    >
                        <svg
                            class="h-4 w-4"
                            :class="{ 'animate-spin': sincronizando }"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"
                            />
                        </svg>
                        {{ sincronizando ? 'Sincronizando...' : 'Sincronizar' }}
                    </Button>
                </div>
            </div>

            <!-- Avisos -->
            <div
                class="rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-theme-xs text-gray-600 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-gray-300"
            >
                El último mes (<strong>{{ ultimoMes }}</strong>) está <strong>incompleto</strong> y baja el promedio —
                si te molesta, usá el preset “Últ. 12” o movés el “hasta” un mes atrás.
                <template v-if="reservaSinConciliar">
                    · La columna <strong>Reservado</strong> todavía no está conciliada con el ERP: el campo que decide la
                    reserva no está en la vista que nos exponen.
                </template>
            </div>

            <KpisCompras :kpis="kpis" :meses-objetivo="filtros.mesesObjetivo" />

            <FiltrosComprasPanel
                v-model="filtros"
                :meses="meses"
                :categorias-presentes="categoriasPresentes"
                :opciones-objetivo="mesesObjetivo"
                @limpiar="limpiar"
            />

            <TablaReposicion
                :filas="filasVisibles"
                :totales="totales"
                :total-filas="filasOrdenadas.length"
                :filtros="filtros"
                :meses="meses"
                :cat-name="catName"
                :orden="orden"
                :mostrar-meses="mostrarMeses"
                :hay-filtros="hayFiltros"
                @ordenar="ordenarPor"
            />

            <!-- Paginado -->
            <div class="flex flex-wrap items-center justify-between gap-3 text-theme-sm text-gray-500 dark:text-gray-400">
                <span>
                    <template v-if="filasOrdenadas.length">
                        Mostrando {{ numero(desdeFila) }}–{{ numero(hastaFila) }} de
                        {{ numero(filasOrdenadas.length) }} productos
                    </template>
                    <template v-else>Sin resultados</template>
                </span>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="cursor-pointer rounded-lg border border-gray-300 px-2.5 py-1.5 text-theme-xs font-semibold text-gray-600 hover:border-brand-500 hover:text-brand-500 disabled:cursor-default disabled:opacity-40 dark:border-gray-700 dark:text-gray-300"
                        :disabled="pagina === 0"
                        @click="irA(0)"
                    >«</button>
                    <button
                        type="button"
                        class="cursor-pointer rounded-lg border border-gray-300 px-2.5 py-1.5 text-theme-xs font-semibold text-gray-600 hover:border-brand-500 hover:text-brand-500 disabled:cursor-default disabled:opacity-40 dark:border-gray-700 dark:text-gray-300"
                        :disabled="pagina === 0"
                        @click="irA(pagina - 1)"
                    >‹ Anterior</button>
                    <span class="px-1 tabular-nums">Pág. {{ pagina + 1 }} de {{ totalPaginas }}</span>
                    <button
                        type="button"
                        class="cursor-pointer rounded-lg border border-gray-300 px-2.5 py-1.5 text-theme-xs font-semibold text-gray-600 hover:border-brand-500 hover:text-brand-500 disabled:cursor-default disabled:opacity-40 dark:border-gray-700 dark:text-gray-300"
                        :disabled="pagina >= totalPaginas - 1"
                        @click="irA(pagina + 1)"
                    >Siguiente ›</button>
                    <button
                        type="button"
                        class="cursor-pointer rounded-lg border border-gray-300 px-2.5 py-1.5 text-theme-xs font-semibold text-gray-600 hover:border-brand-500 hover:text-brand-500 disabled:cursor-default disabled:opacity-40 dark:border-gray-700 dark:text-gray-300"
                        :disabled="pagina >= totalPaginas - 1"
                        @click="irA(totalPaginas - 1)"
                    >»</button>

                    <Select v-model="porPagina" class="w-28">
                        <option :value="50">50 / pág.</option>
                        <option :value="100">100 / pág.</option>
                        <option :value="250">250 / pág.</option>
                        <option :value="500">500 / pág.</option>
                    </Select>
                </div>
            </div>

            <!-- Reglas de cálculo -->
            <div class="text-theme-xs leading-relaxed text-gray-400">
                <button
                    type="button"
                    class="mb-2 cursor-pointer rounded-md border border-gray-300 px-2 py-1 font-semibold text-gray-600 hover:border-brand-500 hover:text-brand-500 dark:border-gray-700 dark:text-gray-300"
                    @click="mostrarMeses = !mostrarMeses"
                >
                    {{ mostrarMeses ? 'Ocultar' : 'Mostrar' }} columnas de meses individuales
                </button>
                <p>
                    Clic en la <strong>fila del producto</strong> → detalle de códigos y descripciones ·
                    clic en el <strong>mini-gráfico</strong> → ventas mes a mes ·
                    clic en un <strong>encabezado</strong> → ordena.
                </p>
                <p class="mt-2">
                    <strong>Reglas de cálculo:</strong>
                    U. x Envase = <code>CODIGO_REFERENCIA</code> del maestro de artículos; vacío = 1 y se muestra “–” ·
                    <strong>Stock (env.)</strong> es el número tal cual viene del ERP y
                    <strong>Stock (u.)</strong> = Stock (env.) × U. x Envase ·
                    ventas, promedio, reservado, OC pendiente, stock total y cantidad a comprar también se multiplican por
                    el envase: todo el tablero está en <strong>unidades</strong> ·
                    <strong>Stock total disp.</strong> = Stock (u.) − Reservado + OC pendiente ·
                    <strong>Prom. mensual</strong> = ventas del período ÷ meses del período ·
                    <strong>Meses cubro</strong> = Stock total disp. ÷ Prom. mensual ·
                    <strong>Cant. a comprar</strong> = (Prom. mensual × meses objetivo) − Stock total disp.
                </p>
                <p class="mt-2">
                    Los artículos con la misma <strong>clasificación GTIN</strong> se unifican en un renglón
                    (multimarca); “NO APLICA”, “N/A” y “0” se ignoran y esos artículos van individuales ·
                    las notas de crédito ya vienen en negativo del ERP y por eso restan solas ·
                    un stock negativo del ERP se cuenta como 0 y el producto queda marcado con ⚠ ·
                    los productos que <strong>no mueven stock</strong> quedan en la categoría SERVICIOS y fuera del
                    cálculo salvo que los incluyas.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
