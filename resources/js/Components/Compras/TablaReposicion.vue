<script setup lang="ts">
import { computed, ref } from 'vue'
import Sparkline from '@/Components/Sparkline.vue'
import GraficoMensual from '@/Components/Compras/GraficoMensual.vue'
import FilaDetalleArticulo from '@/Components/Compras/FilaDetalleArticulo.vue'
import type { ClaveOrden, FilaReposicion, FiltrosCompras, TotalesCompras } from '@/lib/compras'
import { decimal, etiquetaMes, moneda, numero } from '@/lib/formato'

const props = defineProps<{
    /** Solo las filas de la página visible. */
    filas: FilaReposicion[]
    /** Los totales se calculan sobre TODO el conjunto filtrado, no sobre la página. */
    totales: TotalesCompras
    /** Cuántas filas hay en el conjunto filtrado completo (la fila de TOTAL las cuenta todas). */
    totalFilas: number
    filtros: FiltrosCompras
    meses: string[]
    catName: (codigo: string) => string
    orden: { key: ClaveOrden | null; dir: 'asc' | 'desc' }
    mostrarMeses: boolean
    hayFiltros: boolean
}>()

const emit = defineEmits<{ ordenar: [ClaveOrden] }>()

/**
 * Qué grupos están desplegados. Son dos estados independientes a propósito: se
 * puede tener abierto el detalle de artículos y cerrado el gráfico, o al revés.
 * Se indexan por `grupo.id`, no por el nombre: hay 73 descripciones
 * repetidas entre artículos distintos sin GTIN.
 */
const detalleAbierto = ref(new Set<string>())
const graficoAbierto = ref(new Set<string>())

/** Se reemplaza el Set en vez de mutarlo: un `ref` no observa mutaciones internas. */
const alternado = (actual: Set<string>, clave: string): Set<string> => {
    const copia = new Set(actual)
    copia.has(clave) ? copia.delete(clave) : copia.add(clave)
    return copia
}

const alternarDetalle = (clave: string) => (detalleAbierto.value = alternado(detalleAbierto.value, clave))
const alternarGrafico = (clave: string) => (graficoAbierto.value = alternado(graficoAbierto.value, clave))

const etiquetasMes = computed(() => props.meses.map(etiquetaMes))

const rotuloPeriodo = computed(
    () => `(${etiquetasMes.value[props.filtros.desde] ?? ''}–${etiquetasMes.value[props.filtros.hasta] ?? ''}, u.)`,
)

/** 15 columnas fijas; al abrir los meses, la de "Ventas del período" se parte en N. */
const totalColumnas = computed(() => 15 + (props.mostrarMeses ? props.meses.length - 1 : 0))

const flecha = (key: ClaveOrden) => {
    if (props.orden.key !== key) return '⇅'
    return props.orden.dir === 'asc' ? '▲' : '▼'
}

const columnas: { key: ClaveOrden | null; label: string; ancho: string; izq?: boolean; sep?: boolean; destacada?: boolean }[] = [
    { key: 'gtin', label: 'Multimarca GTIN', ancho: 'w-[215px]', izq: true },
    { key: 'cat', label: 'Tipo', ancho: 'w-[104px]', izq: true },
    { key: 'filas', label: 'Filas', ancho: 'w-[44px]' },
    { key: 'envase', label: 'U. x Envase', ancho: 'w-[56px]' },
    { key: 'stockU', label: 'Stock (u.)', ancho: 'w-[74px]' },
    { key: 'stockEnv', label: 'Stock (env.)', ancho: 'w-[64px]' },
    { key: null, label: 'Tendencia venta', ancho: 'w-[92px]', sep: true },
]

const columnasFinales: { key: ClaveOrden; label: string; ancho: string; sep?: boolean; destacada?: boolean }[] = [
    { key: 'prom', label: 'Prom. mensual', ancho: 'w-[70px]' },
    { key: 'reservado', label: 'Reservado', ancho: 'w-[64px]', sep: true },
    { key: 'oc', label: 'OC pend.', ancho: 'w-[62px]' },
    { key: 'total', label: 'Stock total disp.', ancho: 'w-[74px]' },
    { key: 'cubre', label: '¿Cubre?', ancho: 'w-[58px]', sep: true, destacada: true },
    { key: 'meses', label: 'Meses cubro', ancho: 'w-[64px]', destacada: true },
    { key: 'comprar', label: 'Cant. a comprar', ancho: 'w-[76px]', destacada: true },
]

const claseEstado = (fila: FilaReposicion) =>
    fila.estado === 'sv'
        ? 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400'
        : fila.estado === 'si'
          ? 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400'
          : 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400'

const textoEstado = (fila: FilaReposicion) => (fila.estado === 'sv' ? 'SIN VENTA' : fila.estado === 'si' ? 'SÍ' : 'NO')

const tituloPareto = (fila: FilaReposicion) =>
    `Pareto ${fila.pareto} — ${fila.pareto === 'A' ? 'dentro del 80%' : 'el 20% restante'} de la facturación`
    + ` · ${moneda(fila.importe)} (${decimal(fila.pctFact)}% del total, acumulado ${decimal(fila.cumPct)}%)`

const th = 'px-1.5 py-2 text-right align-bottom text-[10.5px] font-bold uppercase leading-tight tracking-wide text-gray-500 dark:text-gray-400'
const td = 'whitespace-nowrap px-1.5 py-2 text-right text-xs tabular-nums'
const sep = 'border-l border-gray-200 dark:border-gray-700'
</script>

<template>
    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <table class="w-full table-fixed border-collapse">
            <thead>
                <tr class="border-b-2 border-gray-200 dark:border-gray-700">
                    <th
                        v-for="c in columnas"
                        :key="c.label"
                        class="sticky top-0 z-[5] bg-gray-50 dark:bg-gray-900"
                        :class="[th, c.ancho, c.izq ? '!text-left' : '', c.sep ? sep : '', c.key ? 'cursor-pointer select-none hover:text-brand-500' : '']"
                        @click="c.key && emit('ordenar', c.key)"
                    >
                        {{ c.label }}
                        <span v-if="c.key" class="ml-0.5 text-[9px]" :class="orden.key === c.key ? 'text-brand-500' : 'opacity-40'">
                            {{ flecha(c.key) }}
                        </span>
                    </th>

                    <!-- Ventas: una columna del período, o una por mes -->
                    <template v-if="mostrarMeses">
                        <th
                            v-for="(m, i) in etiquetasMes"
                            :key="m"
                            class="sticky top-0 z-[5] w-[52px] cursor-pointer select-none bg-gray-50 hover:text-brand-500 dark:bg-gray-900"
                            :class="th"
                            @click="emit('ordenar', `m${i}` as ClaveOrden)"
                        >
                            {{ m }}
                        </th>
                    </template>
                    <th
                        v-else
                        class="sticky top-0 z-[5] w-[74px] cursor-pointer select-none bg-gray-50 hover:text-brand-500 dark:bg-gray-900"
                        :class="th"
                        @click="emit('ordenar', 'ventas')"
                    >
                        Ventas {{ rotuloPeriodo }}
                        <span class="ml-0.5 text-[9px]" :class="orden.key === 'ventas' ? 'text-brand-500' : 'opacity-40'">
                            {{ flecha('ventas') }}
                        </span>
                    </th>

                    <th
                        v-for="c in columnasFinales"
                        :key="c.label"
                        class="sticky top-0 z-[5] cursor-pointer select-none bg-gray-50 hover:text-brand-500 dark:bg-gray-900"
                        :class="[th, c.ancho, c.sep ? sep : '', c.destacada ? 'text-brand-500 dark:text-brand-300' : '']"
                        @click="emit('ordenar', c.key)"
                    >
                        {{ c.label }}
                        <span class="ml-0.5 text-[9px]" :class="orden.key === c.key ? 'text-brand-500' : 'opacity-40'">
                            {{ flecha(c.key) }}
                        </span>
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr v-if="!filas.length">
                    <td :colspan="totalColumnas" class="px-4 py-12 text-center text-sm text-gray-400">
                        <template v-if="hayFiltros">No hay productos que cumplan los filtros seleccionados.</template>
                        <template v-else>
                            No hay datos. Usá el botón <strong>Sincronizar</strong> para traerlos desde RP Sistemas.
                        </template>
                    </td>
                </tr>

                <template v-for="fila in filas" :key="fila.grupo.id">
                    <!-- Producto unificado -->
                    <tr
                        class="cursor-pointer border-b border-gray-100 font-semibold transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]"
                        @click="alternarDetalle(fila.grupo.id)"
                    >
                        <td class="px-1.5 py-2 text-left text-xs">
                            <div class="flex items-start gap-1.5 leading-tight">
                                <span
                                    class="mt-0.5 w-2.5 shrink-0 text-[10px] text-gray-400 transition-transform"
                                    :class="{ 'rotate-90': detalleAbierto.has(fila.grupo.id) }"
                                >▶</span>
                                <span
                                    v-if="fila.pareto"
                                    class="mt-px shrink-0 cursor-help rounded px-1 text-[9.5px] font-extrabold leading-normal"
                                    :class="fila.pareto === 'A'
                                        ? 'bg-brand-50 text-brand-500 dark:bg-brand-500/20 dark:text-brand-300'
                                        : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400'"
                                    :title="tituloPareto(fila)"
                                >{{ fila.pareto }}</span>
                                <span class="line-clamp-2 text-gray-800 dark:text-white/90" :title="fila.grupo.n">
                                    {{ fila.grupo.n }}
                                    <span
                                        v-if="fila.anomalia"
                                        class="cursor-help text-warning-500"
                                        title="El ERP trae stock negativo en este producto; se contó como 0"
                                    >⚠</span>
                                </span>
                            </div>
                        </td>
                        <td class="px-1.5 py-2 text-left">
                            <span
                                v-for="c in fila.grupo.c"
                                :key="c"
                                class="mr-1 inline-block rounded-full bg-gray-100 px-1.5 py-px text-[10px] font-semibold leading-snug text-gray-600 dark:bg-white/[0.06] dark:text-gray-400"
                            >{{ catName(c) }}</span>
                        </td>
                        <td :class="td">
                            <span class="rounded-full bg-gray-100 px-1.5 py-px text-[10.5px] text-gray-500 dark:bg-white/[0.06] dark:text-gray-400">
                                {{ fila.items.length }}
                            </span>
                        </td>
                        <td :class="td">
                            <span
                                v-if="fila.envase === 0"
                                class="cursor-help text-[11px] font-bold text-warning-500"
                                title="Los artículos de este grupo tienen envases distintos; el cálculo usa el envase propio de cada uno"
                            >varios</span>
                            <span v-else-if="fila.envase > 1">{{ numero(fila.envase) }}</span>
                            <span v-else class="text-gray-400">–</span>
                        </td>
                        <td :class="td">{{ numero(fila.stockU) }}</td>
                        <td :class="td">{{ numero(fila.stockEnv) }}</td>
                        <td :class="[td, sep]" @click.stop="alternarGrafico(fila.grupo.id)">
                            <Sparkline
                                :valores="fila.ventas"
                                :desde="filtros.desde"
                                :hasta="filtros.hasta"
                                clickable
                                :activo="graficoAbierto.has(fila.grupo.id)"
                            />
                        </td>

                        <template v-if="mostrarMeses">
                            <td
                                v-for="(v, i) in fila.ventas"
                                :key="i"
                                :class="[td, 'text-gray-600 dark:text-gray-300']"
                                :style="i < filtros.desde || i > filtros.hasta ? 'opacity:.4' : ''"
                            >{{ numero(v) }}</td>
                        </template>
                        <td v-else :class="td">{{ numero(fila.ventaPeriodo) }}</td>

                        <td :class="td">{{ decimal(fila.promMensual) }}</td>
                        <td :class="[td, sep]">{{ numero(fila.reservado) }}</td>
                        <td :class="td">{{ numero(fila.ocPend) }}</td>
                        <td
                            :class="[td, fila.stockTotal < 0 ? 'font-bold text-error-500' : '']"
                            :title="fila.stockTotal < 0 ? 'Déficit: hay más reservado que disponible' : undefined"
                        >{{ numero(fila.stockTotal) }}</td>
                        <td :class="[td, sep]">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold" :class="claseEstado(fila)">
                                {{ textoEstado(fila) }}
                            </span>
                        </td>
                        <td
                            :class="[td, 'font-bold', fila.estado === 'sv' ? 'text-gray-400' : fila.cubre ? 'text-success-600 dark:text-success-400' : 'text-error-500']"
                        >
                            <!-- Un producto sin ventas no tiene cobertura medible: "–", nunca 0. -->
                            <span v-if="fila.estado === 'sv'" class="text-gray-400">–</span>
                            <span v-else-if="fila.meses === Infinity">∞</span>
                            <span v-else>{{ decimal(fila.meses) }}</span>
                        </td>
                        <td :class="[td, 'font-bold', fila.cantComprar > 0 ? 'text-error-500' : 'text-success-600 dark:text-success-400']">
                            {{ fila.cantComprar > 0 ? decimal(fila.cantComprar) : '0' }}
                        </td>
                    </tr>

                    <!-- Gráfico mes a mes -->
                    <tr v-if="graficoAbierto.has(fila.grupo.id)" class="border-b border-gray-100 dark:border-gray-800">
                        <td :colspan="totalColumnas" class="bg-gray-50 px-6 py-3 text-left dark:bg-white/[0.02]">
                            <GraficoMensual
                                :titulo="fila.grupo.n"
                                :meses="meses"
                                :valores="fila.ventas"
                                :desde="filtros.desde"
                                :hasta="filtros.hasta"
                            />
                        </td>
                    </tr>

                    <!-- Detalle: un renglón por artículo -->
                    <FilaDetalleArticulo
                        v-for="item in (detalleAbierto.has(fila.grupo.id) ? fila.items : [])"
                        :key="item.c"
                        :item="item"
                        :filtros="filtros"
                        :total-meses="meses.length"
                        :mostrar-meses="mostrarMeses"
                        :cat-name="catName"
                    />
                </template>
            </tbody>

            <tfoot v-if="filas.length">
                <tr class="border-t-2 border-gray-200 bg-gray-50 font-bold dark:border-gray-700 dark:bg-gray-900">
                    <td class="px-1.5 py-2.5 text-left text-xs text-gray-800 dark:text-white/90">
                        TOTAL ({{ numero(totalFilas) }})
                    </td>
                    <td /><td /><td />
                    <td :class="td">{{ numero(totales.stockU) }}</td>
                    <td :class="td">{{ numero(totales.stockEnv) }}</td>
                    <td :class="sep" />
                    <template v-if="mostrarMeses">
                        <td v-for="(v, i) in totales.ventasPorMes" :key="i" :class="td">{{ numero(v) }}</td>
                    </template>
                    <td v-else :class="td">{{ numero(totales.ventaPeriodo) }}</td>
                    <td :class="td">{{ decimal(totales.promMensual) }}</td>
                    <td :class="[td, sep]">{{ numero(totales.reservado) }}</td>
                    <td :class="td">{{ numero(totales.ocPend) }}</td>
                    <td :class="td">{{ numero(totales.stockTotal) }}</td>
                    <td :class="sep" />
                    <td />
                    <td :class="[td, 'text-error-500']">{{ decimal(totales.cantComprar) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
