<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import Input from '@/Components/Input.vue'
import Select from '@/Components/Select.vue'
import ThOrdenable from '@/Components/ThOrdenable.vue'
import { useDarkMode } from '@/composables/useDarkMode'
import { usePermissions } from '@/composables/usePermissions'
import { etiquetaMes, fechaHora, numero } from '@/lib/formato'
import {
    clientesDeVendedor,
    evolucion,
    pctTexto,
    porcentaje,
    porVendedor,
    totales,
    urgentesDelFiltro,
    type DatasetUrgencias,
    type FilaProforma,
    type FiltroUrgencias,
    type ProformaUrgente,
} from '@/lib/urgencias'

/**
 * Urgencias en facturas proforma (pedido de Tublood del 22/10/2026).
 *
 * "Breve y sencillo": cuántas proformas emitió cada vendedor, cuántas fueron
 * urgentes, el % de urgencias, la torta con el que más acumuló en el mes y,
 * al pararse sobre un vendedor, sus clientes. Todo se calcula acá sobre el
 * dataset entero (`lib/urgencias.ts`); qué es urgente lo decidió el backend.
 */
const props = defineProps<{
    meses: string[]
    vendedores: string[]
    clientes: string[]
    filas: FilaProforma[]
    urgentes: ProformaUrgente[]
    lastSync: string | null
}>()

const { hasPermission } = usePermissions()
const { isDark } = useDarkMode()

const dataset = computed<DatasetUrgencias>(() => ({
    meses: props.meses,
    vendedores: props.vendedores,
    clientes: props.clientes,
    filas: props.filas,
    urgentes: props.urgentes,
}))

// ── Filtros ─────────────────────────────────────────────────────────────────
// Abre en el último mes: la pregunta del documento es "cuántas urgencias hay
// por mes", y el mes en curso es el que se mira.

const mes = ref<number | null>(props.meses.length ? props.meses.length - 1 : null)
const vendedor = ref<number | null>(null)
const clienteTexto = ref('')

// El cliente se elige de la lista (datalist): solo filtra cuando el texto
// coincide exacto con uno, así un tipeo a medias no deja el tablero vacío.
const cliente = computed<number | null>(() => {
    const i = props.clientes.indexOf(clienteTexto.value.trim())
    return i === -1 ? null : i
})

const filtro = computed<FiltroUrgencias>(() => ({ mes: mes.value, vendedor: vendedor.value, cliente: cliente.value }))

const hayFiltros = computed(() => vendedor.value !== null || clienteTexto.value !== '')

const limpiar = () => {
    vendedor.value = null
    clienteTexto.value = ''
}

const nombreMes = computed(() => (mes.value === null ? 'todo el período' : etiquetaMes(props.meses[mes.value])))

// ── Números ─────────────────────────────────────────────────────────────────

const total = computed(() => totales(dataset.value, filtro.value))
const vendedoresDelFiltro = computed(() => porVendedor(dataset.value, filtro.value))
const conUrgencias = computed(() => vendedoresDelFiltro.value.filter(v => v.urgentes > 0))
const totalUrgenciasTorta = computed(() => conUrgencias.value.reduce((s, v) => s + v.urgentes, 0))
const lider = computed(() => conUrgencias.value[0] ?? null)
const serie = computed(() => evolucion(dataset.value, filtro.value))
const listado = computed(() => urgentesDelFiltro(dataset.value, filtro.value))

const MOSTRAR = 100
const verTodas = ref(false)

// ── Clientes del vendedor (pararse arriba o hacer clic) ─────────────────────
// Pararse lo muestra mientras el mouse está encima; el clic lo deja fijo, para
// poder leerlo tranquilo o en una pantalla táctil.

const vendedorHover = ref<number | null>(null)
const vendedorFijo = ref<number | null>(null)
const vendedorAbierto = computed(() => vendedorHover.value ?? vendedorFijo.value)

const fijar = (v: number) => {
    vendedorFijo.value = vendedorFijo.value === v ? null : v
}

const detalleVendedor = computed(() =>
    vendedorAbierto.value === null ? null : clientesDeVendedor(dataset.value, filtro.value, vendedorAbierto.value),
)

// ── Gráficos ────────────────────────────────────────────────────────────────

const COLORES = ['#C8323C', '#1F3864', '#3B6EA5', '#7FA3C8', '#B7C9DD', '#B7791F', '#2E7D5B', '#8E5BA8', '#5E6778', '#D97706', '#0E7490']
// Por índice de vendedor y no por posición en la torta: así cada vendedor
// conserva su color al cambiar de mes.
const colorDe = (v: number) => COLORES[v % COLORES.length]

const tortaOpciones = computed<ApexOptions>(() => ({
    chart: {
        type: 'donut',
        fontFamily: 'Poppins, sans-serif',
        foreColor: '#98a2b3',
        events: {
            dataPointMouseEnter: (_e, _c, cfg) => { vendedorHover.value = conUrgencias.value[cfg?.dataPointIndex ?? -1]?.vendedor ?? null },
            dataPointMouseLeave: () => { vendedorHover.value = null },
            dataPointSelection: (_e, _c, cfg) => {
                const v = conUrgencias.value[cfg?.dataPointIndex ?? -1]?.vendedor
                if (v !== undefined) fijar(v)
            },
        },
    },
    labels: conUrgencias.value.map(v => props.vendedores[v.vendedor]),
    colors: conUrgencias.value.map(v => colorDe(v.vendedor)),
    dataLabels: { enabled: false },
    legend: { show: false },
    stroke: { show: false },
    plotOptions: {
        pie: {
            donut: {
                size: '70%',
                labels: {
                    show: true,
                    total: { show: true, label: 'urgencias', fontSize: '13px', color: '#98a2b3', formatter: () => `${totalUrgenciasTorta.value}` },
                    value: { fontSize: '24px', fontWeight: 700, color: isDark.value ? '#ffffff' : '#101828' },
                },
            },
        },
    },
    tooltip: { theme: isDark.value ? 'dark' : 'light' },
}))

const tortaSerie = computed(() => conUrgencias.value.map(v => v.urgentes))

const lineaOpciones = computed<ApexOptions>(() => ({
    chart: { type: 'line', fontFamily: 'Poppins, sans-serif', foreColor: '#98a2b3', toolbar: { show: false }, zoom: { enabled: false } },
    colors: ['#C8323C'],
    stroke: { curve: 'smooth', width: 3 },
    markers: { size: 4 },
    dataLabels: { enabled: false },
    grid: { borderColor: isDark.value ? 'rgba(255,255,255,0.08)' : '#f2f4f7' },
    xaxis: { categories: serie.value.map(p => etiquetaMes(props.meses[p.mes])) },
    yaxis: { min: 0, labels: { formatter: (x: number) => `${Math.round(x)}%` } },
    tooltip: {
        theme: isDark.value ? 'dark' : 'light',
        y: { formatter: (x: number, opts) => {
            const p = serie.value[opts?.dataPointIndex ?? -1]
            return p ? `${pctTexto(p.pct)} · ${p.urgentes} de ${p.emitidas}` : `${x}%`
        } },
    },
}))

const lineaSerie = computed(() => [{ name: '% de urgencias', data: serie.value.map(p => Number(p.pct.toFixed(1))) }])

// ── Tabla por vendedor (ordena en el cliente, como Sectores) ────────────────

type ClaveOrden = 'vendedor' | 'emitidas' | 'urgentes' | 'pct'
const orden = ref<{ sort: ClaveOrden | null; dir: 'asc' | 'desc' }>({ sort: 'urgentes', dir: 'desc' })

const ordenarPor = (campo: string) => {
    const clave = campo as ClaveOrden
    orden.value = orden.value.sort === clave
        ? { sort: clave, dir: orden.value.dir === 'asc' ? 'desc' : 'asc' }
        : { sort: clave, dir: clave === 'vendedor' ? 'asc' : 'desc' }
}

const tablaVendedores = computed(() => {
    const clave = orden.value.sort ?? 'urgentes'
    const signo = orden.value.dir === 'asc' ? 1 : -1
    return [...vendedoresDelFiltro.value].sort((a, b) => {
        const va = clave === 'vendedor' ? props.vendedores[a.vendedor] : a[clave]
        const vb = clave === 'vendedor' ? props.vendedores[b.vendedor] : b[clave]
        return (typeof va === 'string' ? va.localeCompare(vb as string, 'es') : (va as number) - (vb as number)) * signo
    })
})

// ── Sincronizar ─────────────────────────────────────────────────────────────

const sincronizando = ref(false)
const sincronizar = () => {
    sincronizando.value = true
    router.post(route('urgencias.sync'), {}, { preserveScroll: true, onFinish: () => (sincronizando.value = false) })
}

const fecha = (iso: string) => `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`
</script>

<template>
    <Head title="Urgencias en proformas" />

    <AppLayout>
        <div class="space-y-4">
            <!-- Encabezado -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Urgencias en facturas proforma</h1>
                    <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                        Actualizado {{ fechaHora(lastSync) }} · {{ numero(filas.length) }} proformas
                        <template v-if="meses.length">de {{ etiquetaMes(meses[0]) }} a {{ etiquetaMes(meses[meses.length - 1]) }}</template>
                    </p>
                </div>
                <Button v-if="hasPermission('urgencias.sync')" variant="brand" :disabled="sincronizando" @click="sincronizar">
                    {{ sincronizando ? 'Sincronizando…' : 'Sincronizar' }}
                </Button>
            </div>

            <!-- Filtros -->
            <div class="grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 sm:grid-cols-3 dark:border-gray-800 dark:bg-white/[0.03]">
                <Select v-model="mes" label="Mes">
                    <option :value="null">Todos los meses</option>
                    <option v-for="(m, i) in [...meses].reverse()" :key="m" :value="meses.length - 1 - i">{{ etiquetaMes(m) }}</option>
                </Select>
                <Select v-model="vendedor" label="Vendedor">
                    <option :value="null">Todos</option>
                    <option v-for="(v, i) in vendedores" :key="v" :value="i">{{ v }}</option>
                </Select>
                <div>
                    <Input v-model="clienteTexto" label="Cliente" list="clientes-urgencias" placeholder="Buscar cliente…" />
                    <datalist id="clientes-urgencias">
                        <option v-for="c in clientes" :key="c" :value="c" />
                    </datalist>
                    <p v-if="clienteTexto && cliente === null" class="mt-1 text-theme-xs text-gray-400">Elegí un cliente de la lista para filtrar.</p>
                </div>
                <div v-if="hayFiltros" class="sm:col-span-3">
                    <button type="button" class="cursor-pointer text-theme-sm text-brand-500 hover:underline dark:text-brand-300" @click="limpiar">
                        Limpiar vendedor y cliente
                    </button>
                </div>
            </div>

            <!-- Tarjetas -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Proformas emitidas</p>
                    <p class="mt-1 text-3xl font-bold text-gray-800 dark:text-white/90">{{ numero(total.emitidas) }}</p>
                    <p class="text-theme-xs text-gray-400">{{ nombreMes }}</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">Con urgencia</p>
                    <p class="mt-1 text-3xl font-bold text-error-600 dark:text-error-400">{{ numero(total.urgentes) }}</p>
                    <p class="text-theme-xs text-gray-400">la observación dice "urgente" o parecido</p>
                </div>
                <div class="rounded-2xl border border-error-200 bg-error-50 p-5 dark:border-error-500/30 dark:bg-error-500/10">
                    <p class="text-theme-sm text-error-700 dark:text-error-400">Incidencia</p>
                    <p class="mt-1 text-3xl font-bold text-error-700 dark:text-error-400">{{ pctTexto(total.pct) }}</p>
                    <p class="text-theme-xs text-error-700/80 dark:text-error-400/80">de urgencias sobre lo emitido</p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- Torta por vendedor + clientes al pararse arriba -->
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Urgencias por vendedor</h2>

                    <p v-if="lider" class="mt-3 rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
                        Más urgencias en {{ nombreMes }}:
                        <strong>{{ vendedores[lider.vendedor] }}</strong>
                        con {{ lider.urgentes }} ({{ pctTexto(porcentaje(lider.urgentes, totalUrgenciasTorta)) }} del total)
                    </p>
                    <p v-else class="mt-3 text-theme-sm text-gray-400">Sin urgencias en {{ nombreMes }}.</p>

                    <div v-if="conUrgencias.length" class="mt-4 grid items-center gap-4 sm:grid-cols-2">
                        <VueApexCharts type="donut" height="230" :options="tortaOpciones" :series="tortaSerie" />

                        <ul class="space-y-1">
                            <li v-for="v in conUrgencias" :key="v.vendedor">
                                <button
                                    type="button"
                                    class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-left text-theme-sm transition-colors"
                                    :class="vendedorAbierto === v.vendedor ? 'bg-gray-100 dark:bg-white/[0.06]' : 'hover:bg-gray-50 dark:hover:bg-white/[0.03]'"
                                    :title="`Ver los clientes de ${vendedores[v.vendedor]}`"
                                    @mouseenter="vendedorHover = v.vendedor"
                                    @mouseleave="vendedorHover = null"
                                    @focus="vendedorHover = v.vendedor"
                                    @blur="vendedorHover = null"
                                    @click="fijar(v.vendedor)"
                                >
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: colorDe(v.vendedor) }" />
                                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ vendedores[v.vendedor] }}</span>
                                    <span class="font-mono text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ v.urgentes }} · {{ pctTexto(porcentaje(v.urgentes, totalUrgenciasTorta)) }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Los clientes del vendedor que está abierto -->
                    <div v-if="detalleVendedor && vendedorAbierto !== null" class="mt-4 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                        <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">
                            {{ vendedores[vendedorAbierto] }}
                            <button v-if="vendedorFijo !== null" type="button" class="ml-2 cursor-pointer text-theme-xs font-normal text-gray-400 hover:text-gray-600" @click="vendedorFijo = null">cerrar</button>
                        </p>
                        <p class="text-theme-xs text-gray-400">
                            {{ detalleVendedor.urgentes }} urgentes de {{ detalleVendedor.emitidas }} proformas en {{ nombreMes }}
                        </p>
                        <table v-if="detalleVendedor.conUrgencias.length" class="mt-2 w-full text-theme-sm">
                            <thead>
                                <tr class="text-left text-theme-xs text-gray-400">
                                    <th class="py-1 font-medium">Cliente con urgencias</th>
                                    <th class="py-1 text-right font-medium">Urg.</th>
                                    <th class="py-1 text-right font-medium">Emit.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in detalleVendedor.conUrgencias" :key="c.cliente" class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-1 pr-2 text-gray-700 dark:text-gray-300">{{ clientes[c.cliente] }}</td>
                                    <td class="py-1 text-right font-semibold text-error-600 dark:text-error-400">{{ c.urgentes }}</td>
                                    <td class="py-1 text-right text-gray-500 dark:text-gray-400">{{ c.emitidas }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="detalleVendedor.sinUrgencias" class="mt-2 text-theme-xs text-gray-400">
                            + {{ detalleVendedor.sinUrgencias }} clientes sin urgencias
                        </p>
                    </div>
                    <p v-else-if="conUrgencias.length" class="mt-3 text-theme-xs text-gray-400">
                        Pasá el mouse sobre un vendedor para ver sus clientes; con un clic queda abierto.
                    </p>
                </div>

                <!-- Evolución del % -->
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">% de urgencias, últimos 12 meses</h2>
                    <p class="text-theme-xs text-gray-400">
                        {{ vendedor === null ? 'Todos los vendedores' : vendedores[vendedor] }}{{ cliente === null ? '' : ` · ${clientes[cliente]}` }}
                    </p>
                    <VueApexCharts type="line" height="280" :options="lineaOpciones" :series="lineaSerie" />
                </div>
            </div>

            <!-- Tabla por vendedor -->
            <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <table class="w-full text-left">
                    <thead class="border-b border-gray-200 dark:border-gray-800">
                        <tr>
                            <ThOrdenable campo="vendedor" :orden="orden" pad="px-5" @ordenar="ordenarPor">Vendedor</ThOrdenable>
                            <ThOrdenable campo="emitidas" :orden="orden" pad="px-5" @ordenar="ordenarPor">Proformas</ThOrdenable>
                            <ThOrdenable campo="urgentes" :orden="orden" pad="px-5" @ordenar="ordenarPor">Urgentes</ThOrdenable>
                            <ThOrdenable campo="pct" :orden="orden" pad="px-5" @ordenar="ordenarPor">% urgencias</ThOrdenable>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="v in tablaVendedores" :key="v.vendedor" :class="{ 'bg-brand-50/50 dark:bg-white/[0.03]': vendedor === v.vendedor }">
                            <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">{{ vendedores[v.vendedor] }}</td>
                            <td class="px-5 py-3 text-theme-sm text-gray-600 dark:text-gray-300">{{ numero(v.emitidas) }}</td>
                            <td class="px-5 py-3 text-theme-sm font-semibold" :class="v.urgentes ? 'text-error-600 dark:text-error-400' : 'text-gray-400'">{{ v.urgentes }}</td>
                            <td class="px-5 py-3 text-theme-sm text-gray-600 dark:text-gray-300">{{ pctTexto(v.pct) }}</td>
                        </tr>
                        <tr v-if="!tablaVendedores.length">
                            <td colspan="4" class="px-5 py-6 text-center text-theme-sm text-gray-400">Sin proformas en {{ nombreMes }}.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Las urgentes, para controlar a ojo la detección -->
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Proformas urgentes ({{ listado.length }})</h2>
                <p class="text-theme-xs text-gray-400">
                    Se marca urgente si la observación general dice "urgente", "urgencia" o algo parecido, aunque esté mal escrito.
                </p>
                <div v-if="listado.length" class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-theme-sm">
                        <thead class="text-theme-xs text-gray-400">
                            <tr>
                                <th class="py-2 pr-4 font-medium">N°</th>
                                <th class="py-2 pr-4 font-medium">Fecha</th>
                                <th class="py-2 pr-4 font-medium">Cliente</th>
                                <th class="py-2 pr-4 font-medium">Vendedor</th>
                                <th class="py-2 font-medium">Observación</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="u in (verTodas ? listado : listado.slice(0, MOSTRAR))" :key="u.n" class="text-gray-600 dark:text-gray-300">
                                <td class="py-2 pr-4 font-mono">{{ u.n }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ fecha(u.f) }}</td>
                                <td class="py-2 pr-4">{{ clientes[u.c] }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ vendedores[u.v] }}</td>
                                <td class="py-2">{{ u.o }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <button
                        v-if="listado.length > MOSTRAR && !verTodas"
                        type="button"
                        class="mt-2 cursor-pointer text-theme-sm text-brand-500 hover:underline dark:text-brand-300"
                        @click="verTodas = true"
                    >
                        Ver las {{ listado.length }}
                    </button>
                </div>
                <p v-else class="mt-3 text-theme-sm text-gray-400">Ninguna en {{ nombreMes }}.</p>
            </div>
        </div>
    </AppLayout>
</template>
