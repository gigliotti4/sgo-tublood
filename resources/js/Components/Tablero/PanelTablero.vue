<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import InputFecha from '@/Components/InputFecha.vue'
import BarrasH from '@/Components/Tablero/BarrasH.vue'
import BarrasVerticales from '@/Components/Tablero/BarrasVerticales.vue'
import GraficoLineas from '@/Components/Tablero/GraficoLineas.vue'
import GraficoTorta from '@/Components/Tablero/GraficoTorta.vue'
import {
    cCls, cColor, contar, corto, dias, esAbierta, esVencida, evaluarTipo, indiceMes, indiceSector,
    mesesCompletos, mesesDelPeriodo, okOrigen, tiemposDe, valorDelMes,
    type Caso, type IndicadorT, type ItemBarra, type Modo, type Origen, type Serie,
} from '@/lib/tablero'

/**
 * El tablero de gestión: el "Dashboard SGO TUBLOOD" del cliente, una sola
 * pantalla con 7 secciones numeradas y filtros arriba que afectan a todo.
 *
 * Los datos llegan enteros desde `TableroService` y todo se filtra acá, en el
 * navegador: tocar una barra, un cliente o un mes no es un request.
 */
const props = defineProps<{
    sectores: { id: number; nombre: string; slug: string }[]
    observaciones: Caso[]
    indicadores: IndicadorT[]
    /** Remitos de venta por mes: denominador de la tasa y medida de pedidos del armado. */
    remitosPorMes: Record<string, number>
    /** Remitos de cada cliente del tablero, por mes (clave: número de cliente). */
    remitosPorCliente: Record<string, Record<string, number>>
    puedeVerResponsableFallo: boolean
    /** Sospechas de carga mal hecha: se calculan, no se guardan. */
    calidadDeCarga: { regla: string; id: number; numero: string; titulo: string; detalle: string }[]
    reglasDeCarga: Record<string, string>
    hoy: string
}>()

// ── Catálogos ───────────────────────────────────────────────────────
const PARCIAL = props.hoy.slice(0, 7)

const COLOR_SECTOR: Record<string, string> = {
    logistica: '#26338F', comercial: '#0EA5E9', garantia_calidad: '#14B8A6', deposito: '#F59E0B',
    produccion: '#8B5CF6', comex: '#EC4899', externos: '#64748B',
}
const PAL = ['#26338F', '#0EA5E9', '#14B8A6', '#F59E0B', '#8B5CF6', '#EC4899', '#64748B', '#84CC16', '#EF4444', '#06B6D4']

// Los meses con datos, más el mes en curso (aunque todavía no tenga casos).
const MESES = computed(() => {
    const s = new Set<string>([PARCIAL])

    props.observaciones.forEach(c => s.add(c.m))
    props.indicadores.forEach(i => Object.keys(i.valores).forEach(m => s.add(m)))

    return [...s].sort()
})

const MN = (m: string) => new Date(Number(m.slice(0, 4)), Number(m.slice(5)) - 1, 1).toLocaleDateString('es-AR', { month: 'short' }).replace('.', '')
const MNL = (m: string) => new Date(Number(m.slice(0, 4)), Number(m.slice(5)) - 1, 1).toLocaleDateString('es-AR', { month: 'long' })
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

// Los sectores que aparecen: los que tienen objetivos o algún caso.
const SECTORES = computed(() => {
    const ids = new Set<number>()

    props.indicadores.forEach(i => i.sector_id && ids.add(i.sector_id))
    props.observaciones.forEach(c => c.s && ids.add(c.s))

    return props.sectores.filter(s => ids.has(s.id))
})

const secNombre = (id: number | null) => props.sectores.find(s => s.id === id)?.nombre ?? 'Sin sector'
const colorSector = (id: number | null) => {
    const s = props.sectores.find(x => x.id === id)

    return (s && COLOR_SECTOR[s.slug]) || PAL[(id ?? 0) % PAL.length]
}
const colorPorNombre = (nombre: string) => colorSector(props.sectores.find(s => s.nombre === nombre)?.id ?? null)

const indsDe = (sectorId: number | null) => props.indicadores.filter(i => i.sector_id === sectorId)

// Quién cargó el caso (los reclamos del portal entran sin usuario).
const FORM_EXTERNO = 'Formulario externo (cliente)'
const creadorSector = (o: Caso) => (o.cr ? (o.cs ? secNombre(o.cs) : 'Otro') : FORM_EXTERNO)
const creador = (o: Caso) => o.cr ?? 'Formulario externo'
// Un hallazgo interno no tiene cliente; TUBLOOD tampoco cuenta como uno.
const esCliente = (o: Caso) => !!o.cl && !/^tublood\b/i.test(o.cl)

// ── Estado ──────────────────────────────────────────────────────────
// Abre en el último mes completo: la pregunta es cómo cerró el último mes.
const completos = MESES.value.filter(m => m < PARCIAL)
const ultimo = completos[completos.length - 1] ?? PARCIAL
const MESES_INICIALES = completos.slice(-2).length ? completos.slice(-2) : [ultimo]

interface Estado {
    modo: Modo
    mes: string
    meses: string[]
    sector: number | null
    origen: Origen
    drill: number | null
    pie: number | null
    caso: string | null
    persona: string | null
    cli: string | null
    prov: string | null
    resp: string | null
    csec: string | null
    tsec: number | null
}

const inicial = (): Estado => ({
    modo: 'mes', mes: ultimo, meses: [...MESES_INICIALES], sector: null, origen: 'Todos', drill: null, pie: null,
    caso: null, persona: null, cli: null, prov: null, resp: null, csec: null, tsec: null,
})

const ST = reactive<Estado>(inicial())

const hayFiltros = computed(() => {
    const i = inicial()

    return ST.modo !== i.modo || ST.mes !== i.mes || ST.sector !== null || ST.origen !== 'Todos'
        || !!(ST.caso || ST.persona || ST.tsec || ST.cli || ST.prov || ST.resp || ST.csec || ST.drill || ST.pie)
})

const limpiar = () => Object.assign(ST, inicial())

const alternarMes = (m: string) => {
    ST.meses = ST.meses.includes(m) ? ST.meses.filter(x => x !== m) : [...ST.meses, m].sort()
}

const cambiarSector = (v: string) => {
    ST.sector = v === '' ? null : Number(v)
    ST.drill = ST.sector
    ST.pie = null
}

const abrirSector = (id: number) => {
    ST.drill = id
    ST.sector = id
}

// ── Datos filtrados ─────────────────────────────────────────────────
const perMeses = computed(() => mesesDelPeriodo(ST.modo, ST.mes, ST.meses, MESES.value))
const perTxt = computed(() => (ST.modo === 'anual' ? 'todo el año' : perMeses.value.map(MNL).join(' + ') || 'sin meses'))
const multi = computed(() => perMeses.value.length > 1)

const okMes = (o: Caso) => perMeses.value.includes(o.m)
const okO = (o: Caso) => okOrigen(o, ST.origen)

const base = computed(() => props.observaciones.filter(o => okMes(o) && okO(o)))

const matchResp = (o: Caso) => {
    const [sc, w] = (ST.resp ?? '').split('|')

    return String(o.s) === sc && (w === '*' || (w === 'sin cargar' ? !o.rf : o.rf === w))
}

/** Los filtros por clic se combinan; `skip` saca uno para que un ranking no se reduzca a sí mismo. */
const xf = (o: Caso, skip?: string) =>
    (skip === 'caso' || !ST.caso || o.tc === ST.caso)
    && (skip === 'persona' || !ST.persona || creador(o) === ST.persona)
    && (skip === 'cli' || !ST.cli || (o.cl && corto(o.cl) === ST.cli))
    && (skip === 'prov' || !ST.prov || o.pv === ST.prov)
    && (skip === 'resp' || !ST.resp || matchResp(o))
    && (skip === 'csec' || !ST.csec || creadorSector(o) === ST.csec)

const filt = (skip?: string) => base.value.filter(o => (ST.sector === null || o.s === ST.sector) && !!xf(o, skip))
const L = computed(() => filt())
const tot = computed(() => base.value.length)
const P = (x: number) => `${Math.round(x * 100)}%`
const pct = (a: number, b: number) => (b ? `${Math.round((a / b) * 100)}%` : '—')

const evalP = (i: IndicadorT) => evaluarTipo(i, props.observaciones.filter(okO), perMeses.value, PARCIAL, props.remitosPorMes)
const cumpl = (sectorId: number | null) =>
    indiceSector(indsDe(sectorId), perMeses.value, PARCIAL, props.observaciones.filter(okO), props.remitosPorMes)
const idxM = (sectorId: number | null, m: string) =>
    indiceMes(indsDe(sectorId), m, props.observaciones.filter(okO), props.remitosPorMes)

// Tipos de fallo que tienen dato para evaluar.
const evaluables = computed(() => props.indicadores.filter(i => evalP(i).conDato))

// ── Barra de filtros activos ────────────────────────────────────────
const activos = computed(() => {
    const a: [keyof Estado, string][] = []

    if (ST.caso) a.push(['caso', `Tipo de reclamo: ${ST.caso}`])
    if (ST.persona) a.push(['persona', `Cargó: ${ST.persona}`])
    if (ST.tsec) a.push(['tsec', `Tiempos: ${secNombre(ST.tsec)}`])
    if (ST.cli) a.push(['cli', `Cliente: ${ST.cli}`])
    if (ST.prov) a.push(['prov', `Proveedor: ${ST.prov}`])
    if (ST.resp) a.push(['resp', `Responsable: ${respTexto(ST.resp)}`])
    if (ST.csec) a.push(['csec', `Cargadas por: ${ST.csec}`])

    return a
})

const respTexto = (r: string) => {
    const [sc, w] = r.split('|')

    return `${secNombre(Number(sc))} · ${w === '*' ? 'todos' : w}`
}

// Tocar un elemento filtra todo el tablero y baja a las observaciones.
const pick = async (k: 'caso' | 'persona' | 'cli' | 'prov' | 'resp' | 'csec', v: string | null) => {
    ST[k] = v
    if (v === null) return

    await nextTick()
    ;(document.getElementById('sdet') ?? document.getElementById('lista'))?.scrollIntoView({ behavior: 'smooth' })
}

const irA = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' })

// ── KPIs ────────────────────────────────────────────────────────────
const excedidos = computed(() => evaluables.value.filter(i => evalP(i).worst[0] === 's-bad'))
const resolucion = computed(() => {
    const cerr = L.value.filter(o => o.z)
    const d = cerr.map(o => dias(o.f, o.z as string))

    return { n: cerr.length, prom: d.length ? Math.round((d.reduce((a, b) => a + b, 0) / d.length) * 10) / 10 : null }
})
const vencidosHoy = computed(() => props.observaciones.filter(o => esVencida(o, props.hoy)).length)
const masFallos = computed(() => contar(base.value, o => secNombre(o.s))[0])

// ── 1. Fallos por sector ────────────────────────────────────────────
const itemsSector = computed<ItemBarra[]>(() =>
    SECTORES.value.map(s => {
        const n = base.value.filter(o => o.s === s.id).length
        const c = cumpl(s.id)

        return {
            k: s.nombre, v: n, color: colorSector(s.id),
            title: `${s.nombre}: ${n} fallos (${pct(n, tot.value)} del total) — clic para abrir por tipo`,
            label2: c ? `índice ${P(c.v)}` : '',
            label2c: c ? cColor(c.v, c.g) : null,
        }
    }).filter(e => e.v || ST.origen === 'Todos'),
)

const abrirBarraSector = (i: number) => {
    const s = SECTORES.value.find(x => x.nombre === itemsSector.value[i].k)

    if (s) abrirSector(s.id)
}

const tortaSector = computed<[string, number][]>(() =>
    SECTORES.value.map(s => [s.nombre, base.value.filter(o => o.s === s.id).length] as [string, number]).filter(e => e[1]),
)
const tortaMotivos = computed(() => contar(base.value.filter(o => o.s === ST.pie), o => o.l))

const drill = computed(() => {
    if (ST.drill === null) return null

    const x = ST.drill
    const LS = base.value.filter(o => o.s === x)
    const inds = indsDe(x).slice().sort((a, b) => (b.importancia ?? 0) - (a.importancia ?? 0))
    const cuentaAlgo = (o: Caso) => inds.some(i => i.origen_dato === 'sgo' && i.tipos.includes(o.t))
    const sinInd = contar(LS.filter(o => !cuentaAlgo(o)), o => o.l)
    const filas = inds.map(i => ({ i, e: evalP(i) }))
    const mtxt = (e: ReturnType<typeof evalP>) => e.ms.map((m, k) => `${cap(MN(m))} ${e.vals[k] ?? '–'}`).join(' · ')

    const conLimite: ItemBarra[] = filas.filter(r => r.e.conDato).map(r => ({
        k: r.i.fallo, v: r.e.wv, color: r.e.worst[2], max: r.i.tolerancia,
        title: `${r.i.fallo} (${r.i.codigos}): ${mtxt(r.e)} · objetivo ${r.i.objetivo} / máximo ${r.i.tolerancia} por mes · ${r.e.worst[1]}`,
        label2: multi.value ? `peor mes: ${cap(MN(r.e.wm))}` : (r.i.importancia !== null ? `importancia ${Math.round(r.i.importancia)}%` : ''),
    }))
    const items: ItemBarra[] = [...conLimite, ...sinInd.map(([t, v]) => ({ k: t, v, color: '#9CA3AF', title: `${t}: sin límite`, label2: 'sin límite' }))]

    return { x, LS, filas, sinInd, C: cumpl(x), items, mtxt }
})

// ── 2. Objetivos ────────────────────────────────────────────────────
const tiposConLimite = computed(() => props.indicadores.filter(i => ST.sector === null || i.sector_id === ST.sector))
const valorMes = (i: IndicadorT, m: string) => valorDelMes(i, m, props.observaciones.filter(okO))
const clsMes = (i: IndicadorT, v: number | null) =>
    v === null ? 's-na' : v <= i.objetivo ? 's-ok' : v <= i.tolerancia ? 's-warn' : 's-bad'

const sectoresIndice = computed(() => SECTORES.value.filter(s => ST.sector === null || s.id === ST.sector))
const pesaMas = (sectorId: number) => {
    const w = indsDe(sectorId).map(i => {
        const e = evalP(i)

        return { i, v: e.tot, k: (i.importancia ?? 1) * e.uso }
    }).sort((a, b) => b.k - a.k)[0]

    return w && w.v ? `${w.i.fallo} (${w.v})` : '—'
}

// ── 3. Clientes, proveedores y responsables ─────────────────────────
const topClientes = computed(() => contar(filt('cli').filter(esCliente), o => corto(o.cl as string)).slice(0, 10))
const topProveedores = computed(() => contar(filt('prov').filter(o => o.pv), o => o.pv).slice(0, 10))
const rf = computed(() => filt('resp'))
const filasResp = computed(() => sectoresIndice.value.filter(s => rf.value.some(o => o.s === s.id)).map(s => {
    const L3 = rf.value.filter(o => o.s === s.id)

    return { s, n: L3.length, personas: contar(L3.filter(o => o.rf), o => o.rf), sinCargar: L3.filter(o => !o.rf).length }
}))

const remitosDelMes = (m: string) => props.remitosPorMes[m] ?? 0
const remitosDelPeriodo = computed(() => perMeses.value.reduce((a, m) => a + remitosDelMes(m), 0))
const fallosDelMes = (m: string) => props.observaciones.filter(o => o.m === m && okO(o) && !!xf(o) && (ST.sector === null || o.s === ST.sector)).length
/** Fallos cada 100 remitos. Sin remitos no hay tasa (no es 0). */
const tasa = (f: number, r: number) => (r ? (f / r * 100).toFixed(1).replace('.', ',') : '—')

// Número de cliente por nombre corto: con él se cruza contra los remitos del cliente.
const numeroDeCliente = computed(() => {
    const m = new Map<string, string>()

    props.observaciones.forEach(o => { if (o.cl && o.cn) m.set(corto(o.cl), o.cn) })

    return m
})
const remitosDeCliente = (nombre: string, meses: string[]) => {
    const n = numeroDeCliente.value.get(nombre)

    return n ? meses.reduce((a, m) => a + (props.remitosPorCliente[n]?.[m] ?? 0), 0) : 0
}

const selK = computed<'cli' | 'prov' | 'resp' | null>(() => (ST.cli ? 'cli' : ST.prov ? 'prov' : ST.resp ? 'resp' : null))
const sel = computed(() => {
    const k = selK.value

    if (!k) return null

    const nombre = { cli: 'Cliente', prov: 'Proveedor', resp: 'Responsable' }[k]
    const val = k === 'resp' ? respTexto(ST.resp as string) : (ST[k] as string)
    const all = props.observaciones.filter(o => okO(o) && !!xf(o) && (ST.sector === null || o.s === ST.sector))

    return { k, nombre, val, all }
})

const selAll = computed(() => sel.value?.all ?? [])

// ── 4. Lista ────────────────────────────────────────────────────────
const fecha = (d: string | null) => (d ? d.split('-').reverse().slice(0, 2).join('/') : '—')
const estadoTexto = (e: string) => e.replace(/_/g, ' ')
const clienteTexto = (o: Caso) => (o.cl ? corto(o.cl) : '—')

// ── 5. Evolución ────────────────────────────────────────────────────
const lb = computed(() => props.observaciones.filter(o => okO(o) && !!xf(o)))
const series = computed<Serie[]>(() => {
    const ms = MESES.value

    if (ST.sector === null) {
        const porSector = SECTORES.value
            .map(s => ({ name: s.nombre, color: colorSector(s.id), vals: ms.map(m => lb.value.filter(o => o.m === m && o.s === s.id).length) }))
            .filter(s => s.vals.some(v => v))

        return [{ name: 'Total', color: '#1F2937', vals: ms.map(m => lb.value.filter(o => o.m === m).length) }, ...porSector]
    }

    return contar(lb.value.filter(o => o.s === ST.sector), o => o.l)
        .map(([t], i) => ({ name: t, color: PAL[i % PAL.length], vals: ms.map(m => lb.value.filter(o => o.m === m && o.l === t).length) }))
})
const internosExternos = computed<[string, number][]>(() =>
    ([['Internos', base.value.filter(o => !o.x).length], ['Externos', base.value.filter(o => o.x).length]] as [string, number][]).filter(e => e[1]),
)
const porTipoExterno = computed(() => {
    const m = new Map<string, number>()

    base.value.filter(o => o.x).forEach(o => m.set(o.l, (m.get(o.l) ?? 0) + 1))

    return [...m.entries()]
})

// ── Exportar con fecha de corte ────────────────────────────────────
const corte = ref(props.hoy)
const exportando = ref(false)
const errorExport = ref('')

/** Los filtros de la vista, en texto: van a la hoja "Corte" del Excel y al encabezado impreso. */
const filtrosTexto = computed(() => {
    const f: string[] = []

    if (ST.sector !== null) f.push(`Sector: ${secNombre(ST.sector)}`)
    if (ST.origen !== 'Todos') f.push(`Origen del fallo: ${ST.origen}`)

    return [...f, ...activos.value.map(a => a[1])]
})

const exportar = async () => {
    exportando.value = true
    errorExport.value = ''

    try {
        // Laravel acepta el token de la cookie XSRF en este header.
        const xsrf = decodeURIComponent((document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN=')) ?? '').slice(11))
        const r = await fetch(route('tablero.exportar'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ ids: L.value.map(o => o.i), corte: corte.value, periodo: perTxt.value, filtros: filtrosTexto.value }),
        })

        if (!r.ok) throw new Error(r.status === 422 ? 'No hay observaciones para exportar con estos filtros.' : 'No se pudo generar el Excel.')

        const url = URL.createObjectURL(await r.blob())
        const a = document.createElement('a')

        a.href = url
        a.download = `tablero-${corte.value}.xlsx`
        a.click()
        URL.revokeObjectURL(url)
    } catch (e) {
        errorExport.value = e instanceof Error ? e.message : 'No se pudo generar el Excel.'
    } finally {
        exportando.value = false
    }
}

const imprimir = () => window.print()

const fechaLarga = (d: string) => d.split('-').reverse().join('/')

// ── 8. Calidad de la carga ──────────────────────────────────────────
const verTodasLasAlertas = ref(false)
const alertasPorRegla = computed(() => Object.entries(props.reglasDeCarga).map(([regla, titulo]) => ({
    regla, titulo, items: props.calidadDeCarga.filter(a => a.regla === regla),
})))

// ── 7. Tiempos de resolución ────────────────────────────────────────
const TR = computed(() => SECTORES.value.map(s => {
    const Lx = L.value.filter(o => o.s === s.id)

    return { s, n: Lx.length, t: tiemposDe(Lx, props.hoy) }
}).filter(r => r.n))
const maxDias = computed(() => Math.max(1, ...TR.value.map(r => Math.max(r.t.promedio ?? 0, r.t.edad ?? 0))))
const detalleTiempos = computed(() => {
    if (!ST.tsec) return []

    return L.value.filter(o => o.s === ST.tsec)
        .map(o => ({ o, d: o.z ? dias(o.f, o.z) : dias(o.f, props.hoy) }))
        .sort((a, b) => b.d - a.d)
})
const abrirTiempos = async (id: number) => {
    ST.tsec = id
    await nextTick()
    irA('tdet')
}
</script>

<template>
    <div class="tb-root">
        <!-- Exportar la vista con fecha de corte (Excel desde el servidor; PDF = imprimir) -->
        <div class="tb-noprint tb-toolbar">
            <div class="tb-corte">
                <InputFecha v-model="corte" label="Fecha de corte" />
            </div>
            <button class="tb-btn" :disabled="exportando || !L.length" @click="exportar">{{ exportando ? 'Generando…' : `Excel (${L.length})` }}</button>
            <button class="tb-btn" @click="imprimir">PDF / Imprimir</button>
            <span v-if="errorExport" class="red" style="font-size: 12px">{{ errorExport }}</span>
        </div>

        <!-- Solo al imprimir: de cuándo es esta hoja y con qué filtros -->
        <div class="tb-printonly">
            <h2>Tablero de gestión · SGO</h2>
            <p>Período: {{ perTxt }} · Fecha de corte: {{ fechaLarga(corte) }} · Filtros: {{ filtrosTexto.join(' · ') || 'ninguno' }}</p>
        </div>

        <!-- Filtros: afectan a todo el tablero -->
        <div class="tb-filters tb-noprint">
            <div>
                <label>Período</label>
                <div class="tb-seg">
                    <button v-for="[k, l] in ([['mes', 'Un mes'], ['anual', 'Anual'], ['varios', 'Elegir meses']] as const)" :key="k" :class="{ on: ST.modo === k }" @click="ST.modo = k">{{ l }}</button>
                </div>
            </div>
            <div v-if="ST.modo === 'mes'">
                <label>Mes</label>
                <select v-model="ST.mes">
                    <option v-for="m in MESES" :key="m" :value="m">{{ MNL(m) }}{{ m === PARCIAL ? ' (parcial)' : '' }}</option>
                </select>
            </div>
            <div v-if="ST.modo === 'varios'">
                <label>Meses</label>
                <div class="tb-chips">
                    <button v-for="m in MESES" :key="m" class="tb-chip" :class="{ on: ST.meses.includes(m) }" @click="alternarMes(m)">{{ MNL(m) }}</button>
                </div>
            </div>
            <div>
                <label>Sector</label>
                <select :value="ST.sector ?? ''" @change="cambiarSector(($event.target as HTMLSelectElement).value)">
                    <option value="">Todos</option>
                    <option v-for="s in SECTORES" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                </select>
            </div>
            <div>
                <label>Origen del fallo</label>
                <div class="tb-seg">
                    <button v-for="k in (['Todos', 'Internos', 'Externos'] as const)" :key="k" :class="{ on: ST.origen === k }" @click="ST.origen = k; ST.drill = null; ST.pie = null">{{ k }}</button>
                </div>
            </div>
            <div style="margin-left: auto">
                <label>&nbsp;</label>
                <button class="tb-clear" :disabled="!hayFiltros" @click="limpiar">✕ Borrar filtros</button>
            </div>
        </div>

        <div v-if="activos.length" class="tb-active">
            <span v-for="[k, t] in activos" :key="k" class="tb-af" @click="ST[k] = null as never">{{ t }} ✕</span>
        </div>

        <!-- KPIs -->
        <div class="tb-grid tb-k6">
            <div class="tb-card tb-kpi">
                <div class="lbl">Fallos · {{ perTxt }}</div>
                <div class="num">{{ L.length }}</div>
                <div class="det">{{ L.filter(o => !o.x).length }} internos · {{ L.filter(o => o.x).length }} externos</div>
            </div>
            <div class="tb-card tb-kpi">
                <div class="lbl">Abiertos</div>
                <div class="num">{{ L.filter(esAbierta).length }}</div>
                <div class="det">{{ L.filter(o => !esAbierta(o)).length }} cerrados</div>
            </div>
            <div class="tb-card tb-kpi">
                <div class="lbl">Vencidos hoy</div>
                <div class="num red">{{ vencidosHoy }}</div>
                <div class="det">de todo el año</div>
            </div>
            <div class="tb-card tb-kpi tb-clickable" @click="irA('s2')">
                <div class="lbl">Fallos que exceden su máximo</div>
                <div class="num" :class="excedidos.length ? 'red' : 'grn'">{{ excedidos.length }}</div>
                <div class="det">de {{ evaluables.length }} tipos con límite</div>
            </div>
            <div class="tb-card tb-kpi tb-clickable" @click="irA('s7')">
                <div class="lbl">Tiempo promedio de resolución</div>
                <div class="num">{{ resolucion.prom ?? '—' }}<span style="font-size: 14px; font-weight: 500"> días</span></div>
                <div class="det">{{ resolucion.n }} cerrados del período</div>
            </div>
            <div class="tb-card tb-kpi">
                <div class="lbl">Sector con más fallos</div>
                <template v-if="masFallos">
                    <div class="num" style="font-size: 20px; margin-top: 10px">{{ masFallos[0] }}</div>
                    <div class="det">{{ masFallos[1] }} fallos · {{ pct(masFallos[1], tot) }} del total</div>
                </template>
                <div v-else class="num">—</div>
            </div>
        </div>

        <!-- 1. Fallos por sector -->
        <div id="s1" class="tb-sect"><span class="sn">1</span><div><h2>Fallos por sector</h2><p class="hint" style="margin: 0">Cuántos fallos tuvo cada sector y de qué tipo</p></div></div>

        <div v-if="!drill" class="tb-grid tb-g2">
            <div class="tb-card">
                <h2>Total por sector · {{ perTxt }}</h2>
                <p class="hint">Tocá una barra para abrirla por tipo de fallo · debajo, el índice de fallos del sector (ver punto 2)</p>
                <BarrasVerticales :items="itemsSector" clickable @pick="abrirBarraSector" />
            </div>
            <div class="tb-card">
                <div class="tb-head">
                    <div>
                        <h2>{{ ST.pie ? `${secNombre(ST.pie)} por motivo` : 'Reparto por sector' }}</h2>
                        <p class="hint">{{ ST.pie ? 'Cantidad por tipo de fallo' : 'Tocá un sector para ver sus motivos' }}</p>
                    </div>
                    <button v-if="ST.pie" class="tb-back" @click="ST.pie = null">← Volver</button>
                </div>
                <GraficoTorta
                    v-if="!ST.pie"
                    :entries="tortaSector"
                    :colors="k => colorPorNombre(k)"
                    center="Fallos"
                    clickable
                    @pick="k => (ST.pie = SECTORES.find(s => s.nombre === k)?.id ?? null)"
                />
                <GraficoTorta v-else :entries="tortaMotivos" :colors="(_k, i) => PAL[i % PAL.length]" :center="secNombre(ST.pie)" />
            </div>
        </div>

        <div v-else class="tb-card">
            <div class="tb-head">
                <div>
                    <h2>{{ secNombre(drill.x) }}: {{ drill.LS.length }} fallos por tipo · {{ perTxt }}</h2>
                    <p class="hint">Verde = hasta el objetivo · amarillo = entre objetivo y máximo · rojo = pasó el máximo (línea punteada){{ multi ? ' · con varios meses, cada barra muestra el peor mes de ese tipo' : '' }}</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                    <span v-if="drill.C" class="tb-sem" :class="cCls(drill.C.v, drill.C.g)" style="font-size: 14px; padding: 6px 12px">Índice de fallos: {{ P(drill.C.v) }} del máximo</span>
                    <button class="tb-back" @click="ST.drill = null; ST.sector = null">← Todos los sectores</button>
                </div>
            </div>
            <div class="tb-grid tb-g2">
                <div><BarrasVerticales :items="drill.items" /></div>
                <div class="tb-tw" style="max-height: none">
                    <table>
                        <thead>
                            <tr><th>Tipo de fallo</th><th>Importancia</th><th>Fallos</th><th>Obj. / máx. por mes</th><th>Uso del máximo</th><th>Estado{{ multi ? ' (peor mes)' : '' }}</th></tr>
                        </thead>
                        <tbody>
                            <template v-for="r in drill.filas" :key="r.i.id">
                                <tr v-if="r.e.conDato">
                                    <td>{{ r.i.fallo }} <span class="tb-tag">{{ r.i.codigos }}</span><span v-if="r.i.estado !== 'aprobado'" class="tb-tag p"> {{ r.i.estado === 'propuesta' ? 'propuesta' : 'a revisar' }}</span></td>
                                    <td><b>{{ r.i.importancia !== null ? `${Math.round(r.i.importancia)}%` : '—' }}</b></td>
                                    <td><b>{{ r.e.tot }}</b><template v-if="multi"><br><span class="tb-note">{{ drill.mtxt(r.e) }}</span></template></td>
                                    <td>{{ r.i.objetivo }} / {{ r.i.tolerancia }}</td>
                                    <td>
                                        <b :style="{ color: r.e.uso <= r.i.objetivo / (r.i.tolerancia || 1) ? '#16A34A' : r.e.uso <= 1 ? '#D97706' : '#DC2626' }">{{ P(r.e.uso) }}</b>
                                        <template v-if="multi"><br><span class="tb-note">promedio mensual</span></template>
                                    </td>
                                    <td>
                                        <span class="tb-sem" :class="r.e.worst[0]">{{ r.e.worst[1] }}</span>
                                        <template v-if="multi && r.e.nExc"><br><span class="tb-note">en {{ r.e.nExc }} de {{ r.e.ms.length }} meses</span></template>
                                    </td>
                                </tr>
                                <tr v-else style="color: #9CA3AF">
                                    <td>{{ r.i.fallo }} <span class="tb-tag">{{ r.i.codigos }}</span></td>
                                    <td>{{ r.i.importancia !== null ? `${Math.round(r.i.importancia)}%` : '—' }}</td>
                                    <td colspan="4">Se carga a mano por mes; hoy sin dato</td>
                                </tr>
                            </template>
                            <tr v-for="[t, v] in drill.sinInd" :key="t">
                                <td>{{ t }}</td><td>—</td><td><b>{{ v }}</b></td><td>—</td><td>—</td><td><span class="tb-sem s-na">Sin límite</span></td>
                            </tr>
                            <tr v-if="drill.C" style="background: var(--tb-head)">
                                <td colspan="4"><b>Índice de fallos del sector</b> (ponderado por importancia)</td>
                                <td colspan="2"><b :style="{ color: cColor(drill.C.v, drill.C.g) }">{{ P(drill.C.v) }} del máximo</b></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="tb-note"><b>Uso del máximo</b> = fallos ÷ máximo permitido (100 % = en el límite). <b>Índice de fallos del sector</b> = promedio del uso del máximo de cada tipo, ponderado por su importancia: cuanto más alto, peor. Verde si está dentro de los objetivos, amarillo hasta 100 %, rojo si se pasó.</p>
        </div>

        <!-- 2. Objetivos -->
        <div id="s2" class="tb-sect"><span class="sn">2</span><div><h2>Objetivos: fallos permitidos</h2><p class="hint" style="margin: 0">Los objetivos son cantidades máximas de fallos: menos es mejor</p></div></div>
        <div class="tb-grid tb-g2">
            <div class="tb-card">
                <h2>Tipos de fallo frente a su límite · {{ perTxt }}</h2>
                <p class="hint">Límites mensuales · con varios meses se evalúa cada mes y vale el peor · tocá una fila para abrir el sector</p>
                <div class="tb-tw">
                    <table>
                        <thead>
                            <tr>
                                <th>Sector</th><th>Tipo de fallo</th><th>Obj. / máx.</th>
                                <th v-for="m in MESES" :key="m">{{ cap(MN(m)) }}</th>
                                <th>{{ multi ? 'Peor mes' : 'Período' }}</th><th>Imp.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in tiposConLimite" :key="i.id" class="tb-rowlink" @click="i.sector_id && abrirSector(i.sector_id); irA('s1')">
                                <td>{{ i.sector }}</td>
                                <td>{{ i.fallo }} <span class="tb-tag">{{ i.codigos }}</span></td>
                                <td>{{ i.objetivo }} / {{ i.tolerancia }}</td>
                                <td v-for="m in MESES" :key="m">
                                    <span v-if="valorMes(i, m) !== null" class="tb-sem" :class="clsMes(i, valorMes(i, m))">{{ valorMes(i, m) }}</span><template v-else>—</template>
                                </td>
                                <td>
                                    <span v-if="evalP(i).conDato" class="tb-sem" :class="evalP(i).worst[0]">{{ multi ? `${cap(MN(evalP(i).wm))} ${evalP(i).wv}` : evalP(i).wv }} · {{ evalP(i).worst[1] }}</span><template v-else>—</template>
                                </td>
                                <td>{{ i.importancia !== null ? `${Math.round(i.importancia)}%` : '—' }}</td>
                            </tr>
                            <tr v-if="!tiposConLimite.length"><td :colspan="MESES.length + 5" class="tb-note">No hay objetivos cargados.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="tb-card">
                <h2>Índice de fallos por sector, mes a mes</h2>
                <p class="hint">% del máximo permitido que usó cada sector, ponderado por importancia · menos es mejor</p>
                <table>
                    <thead>
                        <tr><th>Sector</th><th v-for="m in MESES" :key="m">{{ cap(MN(m)) }}{{ m === PARCIAL ? '*' : '' }}</th><th>Período</th><th>Lo que más pesa</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in sectoresIndice" :key="s.id" class="tb-rowlink" @click="abrirSector(s.id); irA('s1')">
                            <td><b>{{ s.nombre }}</b></td>
                            <td v-for="m in MESES" :key="m">
                                <span v-if="idxM(s.id, m)" class="tb-sem" :class="cCls(idxM(s.id, m)!.v, idxM(s.id, m)!.g)">{{ P(idxM(s.id, m)!.v) }}</span><template v-else>—</template>
                            </td>
                            <td><b v-if="cumpl(s.id)" :style="{ color: cColor(cumpl(s.id)!.v, cumpl(s.id)!.g) }">{{ P(cumpl(s.id)!.v) }}</b><template v-else>—</template></td>
                            <td>{{ pesaMas(s.id) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="tb-note">* mes en curso: no entra en el promedio del período mientras haya meses completos. Período = promedio de los índices mensuales. Verde = dentro de los objetivos · amarillo = entre objetivo y máximo · rojo = se pasó del máximo.</p>
            </div>
        </div>

        <!-- 3. Clientes, proveedores y responsables -->
        <div id="s6" class="tb-sect"><span class="sn">3</span><div><h2>Clientes, proveedores y responsables</h2><p class="hint" style="margin: 0">Tocá cualquier cliente, proveedor o responsable: se filtra todo el tablero y se abren sus observaciones</p></div></div>
        <div class="tb-grid" :class="puedeVerResponsableFallo ? 'tb-g3' : 'tb-g2e'">
            <div class="tb-card">
                <h2>Clientes con más fallos</h2>
                <p class="hint">Sin hallazgos internos ni proveedores</p>
                <BarrasH :entries="topClientes" clickable :seleccionado="ST.cli" @pick="v => pick('cli', v)" />
            </div>
            <div class="tb-card">
                <h2>Proveedores y transportes</h2>
                <p class="hint">Del campo “Proveedor” de los formularios de fallos externos</p>
                <BarrasH :entries="topProveedores" color="#64748B" clickable :seleccionado="ST.prov" @pick="v => pick('prov', v)" />
            </div>
            <!-- Solo jefes y Calidad: el dato ni siquiera viaja a los demás -->
            <div v-if="puedeVerResponsableFallo" class="tb-card">
                <div class="tb-head"><h2>Responsable del fallo</h2><span class="tb-lock">Solo jefes y Calidad</span></div>
                <p class="hint">Tocá un número para ver esos casos</p>
                <table>
                    <thead><tr><th>Sector</th><th>Fallos</th><th>Por empleado</th><th>Sin cargar</th></tr></thead>
                    <tbody>
                        <tr v-for="f in filasResp" :key="f.s.id">
                            <td>{{ f.s.nombre }}</td>
                            <td><a href="#" :class="{ sel: ST.resp === `${f.s.id}|*` }" @click.prevent="pick('resp', `${f.s.id}|*`)">{{ f.n }}</a></td>
                            <td>
                                <template v-if="f.personas.length"><div v-for="[p, v] in f.personas" :key="p">{{ p }}: <a href="#" :class="{ sel: ST.resp === `${f.s.id}|${p}` }" @click.prevent="pick('resp', `${f.s.id}|${p}`)">{{ v }}</a></div></template>
                                <template v-else>—</template>
                            </td>
                            <td class="red"><a v-if="f.sinCargar" href="#" :class="{ sel: ST.resp === `${f.s.id}|sin cargar` }" @click.prevent="pick('resp', `${f.s.id}|sin cargar`)">{{ f.sinCargar }}</a><template v-else>0</template></td>
                        </tr>
                        <tr v-if="!filasResp.length"><td colspan="4" class="tb-note">Sin fallos en el período.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Fallos frente al total de remitos -->
        <div class="tb-card tb-mt tb-remito">
            <div class="tb-head"><div>
                <h2>Fallos frente al total de remitos · {{ perTxt }}</h2>
                <p class="hint">Tasa = fallos cada 100 remitos de venta (series VR8, VRM y VR6 del ERP). Un cliente o un sector con muchos fallos puede mover mucho volumen o tener un problema: la tasa los distingue</p>
            </div></div>
            <div class="tb-grid tb-g3">
                <div>
                    <h2 style="font-size: 14px">Total TUBLOOD</h2>
                    <table>
                        <thead><tr><th></th><th>Fallos</th><th>Remitos</th><th>Tasa %</th></tr></thead>
                        <tbody>
                            <tr v-for="m in perMeses" :key="m">
                                <td>{{ cap(MNL(m)) }}{{ m === PARCIAL ? ' (parcial)' : '' }}</td>
                                <td><b>{{ fallosDelMes(m) }}</b></td>
                                <td>{{ remitosDelMes(m) || '—' }}</td>
                                <td>{{ tasa(fallosDelMes(m), remitosDelMes(m)) }}</td>
                            </tr>
                            <tr v-if="multi">
                                <td><b>Período</b></td><td><b>{{ L.length }}</b></td><td><b>{{ remitosDelPeriodo || '—' }}</b></td><td><b>{{ tasa(L.length, remitosDelPeriodo) }}</b></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h2 style="font-size: 14px">Por sector</h2>
                    <table>
                        <thead><tr><th>Sector</th><th>Fallos</th><th>Remitos</th><th>Tasa %</th></tr></thead>
                        <tbody>
                            <tr v-for="s in sectoresIndice" :key="s.id">
                                <td>{{ s.nombre }}</td>
                                <td><b>{{ L.filter(o => o.s === s.id).length }}</b></td>
                                <td>{{ remitosDelPeriodo || '—' }}</td>
                                <td>{{ tasa(L.filter(o => o.s === s.id).length, remitosDelPeriodo) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="tb-note">Un remito no es de un sector: cada sector se compara con el total de remitos.</p>
                </div>
                <div>
                    <h2 style="font-size: 14px">Por cliente (los que más fallos tienen)</h2>
                    <table>
                        <thead><tr><th>Cliente</th><th>Fallos</th><th>Remitos</th><th>Tasa %</th></tr></thead>
                        <tbody>
                            <tr v-for="[k, v] in topClientes" :key="k" class="tb-rowlink" @click="pick('cli', k)">
                                <td>{{ k }}</td><td><b>{{ v }}</b></td>
                                <td>{{ remitosDeCliente(k, perMeses) || '—' }}</td>
                                <td>{{ tasa(v, remitosDeCliente(k, perMeses)) }}</td>
                            </tr>
                            <tr v-if="!topClientes.length"><td colspan="4" class="tb-note">Sin datos</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="tb-note">4 fallos sobre 200 remitos (2 %) no es lo mismo que 4 sobre 10 (40 %). Los remitos se sincronizan una vez por día.</p>
        </div>

        <!-- Detalle de lo que se tocó -->
        <div v-if="sel" id="sdet" class="tb-card tb-mt">
            <div class="tb-head">
                <div>
                    <h2>{{ sel.nombre }}: {{ sel.val }} · {{ L.length }} fallos en {{ perTxt }}</h2>
                    <p class="hint">{{ sel.all.length }} en todo el año · qué tipo de fallo tuvo y cuántos de cada uno</p>
                </div>
                <div style="display: flex; gap: 8px">
                    <button class="tb-back" @click="irA('lista')">Ver las {{ L.length }} observaciones ↓</button>
                    <button class="tb-back" @click="ST[sel.k] = null">Cerrar ✕</button>
                </div>
            </div>
            <div class="tb-grid tb-g3">
                <div><GraficoTorta :entries="contar(L, o => o.l)" :colors="(_k, i) => PAL[i % PAL.length]" center="Fallos" /></div>
                <div>
                    <h2 style="font-size: 14px">Por sector responsable</h2>
                    <BarrasH :entries="contar(L, o => secNombre(o.s))" :color="colorPorNombre" />
                    <h2 style="font-size: 14px; margin-top: 14px">Por mes</h2>
                    <BarrasH :entries="MESES.map(m => [cap(MNL(m)) + (m === PARCIAL ? ' (parcial)' : ''), selAll.filter(o => o.m === m).length] as [string, number])" />
                </div>
                <div v-if="sel.k === 'cli'" class="tb-remito">
                    <h2 style="font-size: 14px">Fallos cada 100 remitos</h2>
                    <p class="hint">Remitos de venta del cliente, por mes</p>
                    <table>
                        <thead><tr><th>Período</th><th>Fallos</th><th>Remitos</th><th>Tasa %</th></tr></thead>
                        <tbody>
                            <tr v-for="m in MESES" :key="m">
                                <td>{{ cap(MN(m)) }}</td>
                                <td>{{ selAll.filter(o => o.m === m).length }}</td>
                                <td>{{ remitosDeCliente(ST.cli ?? '', [m]) || '—' }}</td>
                                <td>{{ tasa(selAll.filter(o => o.m === m).length, remitosDeCliente(ST.cli ?? '', [m])) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="tb-note">4 fallos sobre 200 remitos (2 %) no es lo mismo que 4 sobre 10 (40 %).</p>
                </div>
                <div v-else>
                    <h2 style="font-size: 14px">Por cliente</h2>
                    <BarrasH :entries="contar(L.filter(esCliente), o => corto(o.cl as string)).slice(0, 8)" />
                </div>
            </div>
        </div>

        <!-- 4. Observaciones -->
        <div id="lista" class="tb-sect"><span class="sn">4</span><div><h2>Observaciones ({{ L.length }})</h2><p class="hint" style="margin: 0">Según los filtros de arriba · clic en una fila para abrir la ficha</p></div></div>
        <div class="tb-card">
            <p v-if="!L.length" class="tb-note">No hay observaciones con estos filtros.</p>
            <div v-else class="tb-tw">
                <table>
                    <thead><tr><th>N.º</th><th>Creada</th><th>Tipo</th><th>Título</th><th>Cliente</th><th>Tipo de reclamo</th><th>Cargó</th><th>Estado</th></tr></thead>
                    <tbody>
                        <tr v-for="o in L" :key="o.i" class="tb-rowlink">
                            <td style="white-space: nowrap"><Link :href="route('observaciones.show', o.i)">{{ o.n }}</Link></td>
                            <td>{{ fecha(o.f) }}</td>
                            <td><span class="tb-tag" :class="o.x ? 'ext' : 'int'">{{ o.x ? 'Externo' : 'Interno' }}</span> {{ o.l }}</td>
                            <td>{{ o.ti }}</td>
                            <td>{{ clienteTexto(o) }}</td>
                            <td>{{ o.tc ?? '—' }}</td>
                            <td>{{ creador(o) }}</td>
                            <td><span class="tb-est" :class="o.e">{{ estadoTexto(o.e) }}</span><span v-if="esVencida(o, hoy)" class="tb-sem s-bad"> vencida</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Evolución -->
        <div id="s3" class="tb-sect"><span class="sn">5</span><div><h2>Evolución</h2><p class="hint" style="margin: 0">¿Los fallos suben o bajan?</p></div></div>
        <div class="tb-grid tb-g2">
            <div class="tb-card">
                <h2>Fallos mes a mes{{ ST.sector !== null ? ` · ${secNombre(ST.sector)} por tipo` : '' }}</h2>
                <p class="hint">El mes en curso va punteado porque está incompleto · elegí un sector arriba para ver sus tipos</p>
                <GraficoLineas :series="series" :meses="MESES" :etiquetas="MESES.map(m => cap(MN(m)))" :parcial="PARCIAL" />
            </div>
            <div class="tb-card">
                <h2>Internos y externos · {{ perTxt }}</h2>
                <p class="hint">Tocá para filtrar todo el tablero</p>
                <GraficoTorta
                    :entries="internosExternos"
                    :colors="k => (k === 'Internos' ? '#26338F' : '#64748B')"
                    center="Fallos"
                    clickable
                    @pick="k => (ST.origen = k as Origen)"
                />
                <div class="tb-note"><template v-for="[k, v] in porTipoExterno" :key="k">{{ k }}: <b>{{ v }}</b><br></template></div>
            </div>
        </div>

        <!-- 6. De dónde vienen y quién los carga -->
        <div id="s5" class="tb-sect"><span class="sn">6</span><div><h2>De dónde vienen y quién los carga</h2><p class="hint" style="margin: 0">Se actualiza con los filtros</p></div></div>
        <div class="tb-grid tb-g3">
            <div class="tb-card">
                <h2>Por tipo de reclamo</h2><p class="hint">Tocá para filtrar la lista</p>
                <BarrasH :entries="contar(filt('caso'), o => o.tc)" clickable :seleccionado="ST.caso" @pick="v => pick('caso', v)" />
            </div>
            <div class="tb-card">
                <h2>Cargadas por sector</h2><p class="hint">Sector de quien creó la observación</p>
                <BarrasH :entries="contar(filt('csec'), creadorSector)" :color="k => (k === FORM_EXTERNO ? '#94A3B8' : colorPorNombre(k))" clickable :seleccionado="ST.csec" @pick="v => pick('csec', v)" />
            </div>
            <div class="tb-card">
                <h2>Cargadas por persona</h2><p class="hint">Tocá un nombre para ver lo que cargó</p>
                <BarrasH :entries="contar(filt('persona'), creador)" clickable :seleccionado="ST.persona" @pick="v => pick('persona', v)" />
            </div>
        </div>

        <!-- 7. Tiempos de resolución -->
        <div id="s7" class="tb-sect"><span class="sn">7</span><div><h2>Tiempos de resolución por sector</h2><p class="hint" style="margin: 0">Días desde que se carga la observación hasta que se cierra</p></div></div>
        <div class="tb-grid tb-g2e">
            <div class="tb-card">
                <h2>Días promedio por sector · {{ perTxt }}</h2>
                <p class="hint">Barra llena = cerradas (días hasta el cierre) · barra clara = abiertas (días que llevan abiertas) · tocá un sector para ver el detalle</p>
                <p v-if="!TR.length" class="tb-note">Sin datos en el período.</p>
                <div v-for="r in TR" :key="r.s.id" class="tb-tbar" @click="abrirTiempos(r.s.id)">
                    <span><b>{{ r.s.nombre }}</b></span>
                    <div>
                        <div class="tb-tb"><i :style="{ width: `${((r.t.promedio ?? 0) / maxDias) * 100}%`, background: colorSector(r.s.id) }" /><em>{{ r.t.promedio ?? '—' }} d</em></div>
                        <div class="tb-tb light"><i :style="{ width: `${((r.t.edad ?? 0) / maxDias) * 100}%`, background: colorSector(r.s.id) }" /><em>{{ r.t.edad ?? '—' }} d</em></div>
                    </div>
                </div>
                <div class="tb-legend">
                    <span><i style="background: #26338F" />Cerradas: promedio hasta el cierre</span>
                    <span><i style="background: #26338F; opacity: 0.3" />Abiertas: antigüedad promedio</span>
                </div>
            </div>
            <div class="tb-card">
                <h2>Detalle por sector</h2>
                <p class="hint">“En plazo” compara el cierre con el vencimiento vigente (la prórroga si la hay)</p>
                <div class="tb-tw" style="max-height: none">
                    <table>
                        <thead><tr><th>Sector</th><th>Cerradas</th><th>Promedio</th><th>Mediana</th><th>Máximo</th><th>En plazo</th><th>Abiertas</th><th>Vencidas</th></tr></thead>
                        <tbody>
                            <tr v-for="r in TR" :key="r.s.id" class="tb-rowlink" @click="ST.tsec = r.s.id">
                                <td><b>{{ r.s.nombre }}</b></td>
                                <td>{{ r.t.cerradas }}</td>
                                <td>{{ r.t.promedio ?? '—' }} d</td>
                                <td>{{ r.t.mediana ?? '—' }} d</td>
                                <td>{{ r.t.maximo ?? '—' }} d</td>
                                <td>{{ r.t.cerradas ? pct(r.t.enPlazo, r.t.cerradas) : '—' }}</td>
                                <td>{{ r.t.abiertas }}<span v-if="r.t.edad !== null" class="tb-note"> ({{ r.t.edad }} d)</span></td>
                                <td :class="{ red: r.t.vencidas }">{{ r.t.vencidas }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div v-if="ST.tsec" id="tdet" class="tb-card tb-mt">
            <div class="tb-head">
                <div>
                    <h2>{{ secNombre(ST.tsec) }}: tiempo de cada observación</h2>
                    <p class="hint">Cerradas: días hasta el cierre · abiertas: días que llevan abiertas · de la más lenta a la más rápida</p>
                </div>
                <button class="tb-back" @click="ST.tsec = null">Cerrar ✕</button>
            </div>
            <div v-for="t in detalleTiempos" :key="t.o.i" class="tb-hbar tb-click">
                <Link :href="route('observaciones.show', t.o.i)">{{ t.o.n }} · {{ (t.o.ti ?? '').slice(0, 40) }}{{ t.o.z ? '' : ' (abierta)' }}</Link>
                <span class="tb-track"><i :style="{ width: `${(t.d / Math.max(1, ...detalleTiempos.map(x => x.d))) * 100}%` }" /></span>
                <b>{{ t.d }}</b>
            </div>
        </div>

        <!-- 8. Calidad de la carga -->
        <div id="s8" class="tb-sect"><span class="sn">8</span><div><h2>Calidad de la carga</h2><p class="hint" style="margin: 0">Sospechas que ensucian los números · se calculan solas y desaparecen al corregir el caso · son para revisar, no errores probados</p></div></div>
        <div class="tb-grid tb-g3">
            <div v-for="g in alertasPorRegla" :key="g.regla" class="tb-card">
                <div class="tb-head"><h2>{{ g.titulo }}</h2><span class="tb-sem" :class="g.items.length ? 's-warn' : 's-ok'">{{ g.items.length }}</span></div>
                <p v-if="!g.items.length" class="tb-note">Sin alertas.</p>
                <ul v-else class="tb-alertas">
                    <li v-for="a in (verTodasLasAlertas ? g.items : g.items.slice(0, 6))" :key="a.id">
                        <Link :href="route('observaciones.show', a.id)"><b>{{ a.numero }}</b></Link> {{ a.titulo.slice(0, 50) }}
                        <span class="tb-note">{{ a.detalle }}</span>
                    </li>
                </ul>
                <button v-if="g.items.length > 6" class="tb-back" style="margin-top: 8px" @click="verTodasLasAlertas = !verTodasLasAlertas">{{ verTodasLasAlertas ? 'Ver menos' : `Ver las ${g.items.length}` }}</button>
            </div>
        </div>
    </div>
</template>

<style>
/*
 * Estilo del tablero. Sin Tailwind a propósito: son las clases del prototipo del
 * cliente (azul marino, semáforo verde / ámbar / rojo), con prefijo `tb-` para no
 * chocar con las del resto del panel. El modo oscuro cambia solo las variables.
 */
.tb-root {
    --tb-card: #fff; --tb-ink: #1f2937; --tb-muted: #6b7280; --tb-line: #e5e7eb; --tb-navy: #26338f; --tb-soft: #eef0fb;
    --tb-head: #fafafc; --tb-hover: #f8f9ff; --tb-track: #eef0f6;
    --tb-green: #16a34a; --tb-greenbg: #dcfce7; --tb-amber: #d97706; --tb-amberbg: #fef3c7; --tb-red: #dc2626; --tb-redbg: #fee2e2;
    color: var(--tb-ink);
    font-size: 14px;
    margin-bottom: 40px;
}
.dark .tb-root {
    --tb-card: rgba(255, 255, 255, 0.03); --tb-ink: rgba(255, 255, 255, 0.9); --tb-muted: #9ca3af; --tb-line: #1f2937; --tb-navy: #8d9bf5; --tb-soft: rgba(141, 155, 245, 0.15);
    --tb-head: rgba(255, 255, 255, 0.04); --tb-hover: rgba(255, 255, 255, 0.05); --tb-track: rgba(255, 255, 255, 0.08);
    --tb-greenbg: rgba(22, 163, 74, 0.2); --tb-amberbg: rgba(217, 119, 6, 0.2); --tb-redbg: rgba(220, 38, 38, 0.2);
}
.tb-root *, .tb-root *::before, .tb-root *::after { box-sizing: border-box; }
.tb-root a { color: var(--tb-navy); }
.tb-root .red { color: var(--tb-red); }
.tb-root .grn { color: var(--tb-green); }
.tb-root .hint { color: var(--tb-muted); font-size: 12px; margin: 0 0 12px; }
.tb-root h2 { font-size: 15px; margin: 0 0 2px; font-weight: 600; color: var(--tb-ink); }
.tb-root .tb-muted { opacity: 0.6; }
.tb-root .tb-soft { opacity: 0.85; }
.tb-root .tb-grid-line, .tb-root .tb-grid { }
.tb-root line.tb-grid { opacity: 0.12; }
.tb-svg { display: block; width: 100%; height: auto; color: var(--tb-ink); }
.tb-grid { display: grid; gap: 16px; }
.tb-k6 { grid-template-columns: repeat(6, 1fr); }
.tb-g2 { grid-template-columns: 1.4fr 1fr; }
.tb-g2e { grid-template-columns: 1fr 1fr; }
.tb-g3 { grid-template-columns: 1fr 1fr 1fr; }
.tb-mt { margin-top: 16px; }
.tb-card { background: var(--tb-card); border: 1px solid var(--tb-line); border-radius: 14px; padding: 18px; min-width: 0; }
.tb-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.tb-kpi .lbl { color: var(--tb-muted); font-size: 12px; }
.tb-kpi .num { font-size: 26px; font-weight: 700; margin-top: 4px; }
.tb-kpi .det { font-size: 11px; color: var(--tb-muted); }
.tb-clickable { cursor: pointer; }
.tb-clickable:hover { border-color: var(--tb-navy); }
.tb-filters { display: flex; gap: 18px; flex-wrap: wrap; align-items: flex-end; background: var(--tb-card); border: 1px solid var(--tb-line); border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; position: sticky; top: -16px; z-index: 20; backdrop-filter: blur(6px); }
.dark .tb-filters { background: #111827; }
.tb-filters label { display: block; font-size: 11px; color: var(--tb-muted); margin-bottom: 4px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; }
.tb-seg { display: inline-flex; border: 1px solid var(--tb-line); border-radius: 10px; overflow: hidden; }
.tb-seg button { border: 0; background: transparent; color: var(--tb-ink); padding: 7px 12px; cursor: pointer; font: inherit; font-size: 12px; border-right: 1px solid var(--tb-line); }
.tb-seg button:last-child { border-right: 0; }
.tb-seg button.on { background: #26338f; color: #fff; }
.tb-root select { font: inherit; font-size: 13px; padding: 7px 10px; border: 1px solid var(--tb-line); border-radius: 10px; background: var(--tb-card); color: var(--tb-ink); }
.dark .tb-root select { background: #111827; }
.tb-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.tb-chip { border: 1px solid var(--tb-line); background: transparent; color: var(--tb-ink); border-radius: 999px; padding: 5px 12px; cursor: pointer; font: inherit; font-size: 12px; text-transform: capitalize; }
.tb-chip.on { background: #26338f; color: #fff; border-color: #26338f; }
.tb-active { display: flex; gap: 6px; flex-wrap: wrap; margin: -6px 0 14px; }
.tb-af { font-size: 12px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 999px; padding: 3px 10px; cursor: pointer; }
.tb-clear { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; border-radius: 10px; padding: 7px 12px; cursor: pointer; font: inherit; font-size: 12px; font-weight: 500; }
.tb-clear:disabled { opacity: 0.4; cursor: default; }
.tb-back { border: 1px solid var(--tb-line); background: transparent; color: var(--tb-ink); border-radius: 8px; padding: 5px 10px; cursor: pointer; font: inherit; font-size: 12px; white-space: nowrap; }
.tb-root table { width: 100%; border-collapse: collapse; font-size: 13px; }
.tb-root th { text-align: left; font-weight: 600; color: var(--tb-muted); font-size: 12px; padding: 8px; border-bottom: 1px solid var(--tb-line); background: var(--tb-head); position: sticky; top: 0; }
.tb-root td { padding: 8px; border-bottom: 1px solid var(--tb-line); vertical-align: top; }
.tb-rowlink { cursor: pointer; }
.tb-rowlink:hover td { background: var(--tb-hover); }
.tb-tw { overflow: auto; max-height: 480px; border: 1px solid var(--tb-line); border-radius: 10px; }
.tb-sem { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 500; white-space: nowrap; }
.tb-sem::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.tb-root .s-ok { background: var(--tb-greenbg); color: var(--tb-green); }
.tb-root .s-warn { background: var(--tb-amberbg); color: var(--tb-amber); }
.tb-root .s-bad { background: var(--tb-redbg); color: var(--tb-red); }
.tb-root .s-na { background: rgba(156, 163, 175, 0.18); color: #9ca3af; }
.tb-tag { font-size: 11px; padding: 2px 8px; border-radius: 6px; background: rgba(156, 163, 175, 0.2); color: var(--tb-ink); white-space: nowrap; }
.tb-tag.p { background: var(--tb-amberbg); color: var(--tb-amber); }
.tb-tag.ext { background: #e0f2fe; color: #0369a1; }
.tb-tag.int { background: var(--tb-soft); color: var(--tb-navy); }
.tb-est { font-size: 11px; padding: 2px 8px; border-radius: 6px; white-space: nowrap; text-transform: capitalize; background: rgba(156, 163, 175, 0.2); }
.tb-est.cerrada { background: var(--tb-greenbg); color: var(--tb-green); }
.tb-est.clasificada { background: #dbeafe; color: #1d4ed8; }
.tb-est.derivada, .tb-est.derivada_nc { background: #ede9fe; color: #7c3aed; }
.tb-est.en_proceso { background: #e0e7ff; color: #4338ca; }
.tb-est.pendiente_clasificacion { background: var(--tb-amberbg); color: var(--tb-amber); }
.tb-est.cancelada { background: var(--tb-redbg); color: var(--tb-red); }
.tb-slice, .tb-barg { cursor: pointer; }
.tb-slice:hover { opacity: 0.85; }
.tb-barg:hover rect { opacity: 0.85; }
.tb-dot { fill: var(--tb-card); }
.dark .tb-dot { fill: #111827; }
.tb-legend { display: flex; gap: 10px 14px; flex-wrap: wrap; font-size: 12px; margin-top: 8px; }
.tb-legend span { display: inline-flex; align-items: center; gap: 6px; }
.tb-legend i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.tb-hbar { display: grid; grid-template-columns: minmax(110px, 220px) 1fr 30px; gap: 8px; align-items: center; margin: 5px 0; font-size: 12px; }
.tb-track { height: 11px; background: var(--tb-track); border-radius: 6px; overflow: hidden; }
.tb-track i { display: block; height: 100%; background: #26338f; border-radius: 6px; }
.dark .tb-track i { background: #8d9bf5; }
.tb-hbar.tb-click { cursor: pointer; }
.tb-hbar.tb-click:hover span:first-child { color: var(--tb-navy); text-decoration: underline; }
.tb-hbar.tb-on span:first-child { font-weight: 600; color: var(--tb-navy); }
.tb-hbar.tb-on { background: var(--tb-soft); border-radius: 6px; }
.tb-note { font-size: 12px; color: var(--tb-muted); margin-top: 10px; }
.tb-root td .tb-note { margin-top: 0; }
.tb-lock { font-size: 11px; background: #fef2f2; color: #991b1b; border-radius: 6px; padding: 2px 8px; white-space: nowrap; }
.tb-sect { display: flex; gap: 12px; align-items: center; margin: 28px 0 12px; }
.tb-sect h2 { font-size: 17px; margin: 0; }
.tb-sect .sn { width: 28px; height: 28px; border-radius: 50%; background: #26338f; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; flex: none; }
.tb-tbar { display: grid; grid-template-columns: 110px 1fr; gap: 10px; align-items: center; padding: 6px 0; cursor: pointer; border-bottom: 1px solid var(--tb-line); }
.tb-tbar:hover b { color: var(--tb-navy); text-decoration: underline; }
.tb-tb { position: relative; height: 14px; background: var(--tb-track); border-radius: 6px; margin: 3px 0; display: flex; align-items: center; }
.tb-tb i { display: block; height: 100%; border-radius: 6px; }
.tb-tb.light i { opacity: 0.3; }
.tb-tb em { font-style: normal; font-size: 11px; margin-left: 6px; white-space: nowrap; }
.tb-root a.sel { font-weight: 700; background: var(--tb-soft); padding: 0 4px; border-radius: 4px; }
.tb-toolbar { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; justify-content: flex-end; margin-bottom: 12px; }
.tb-corte { width: 170px; }
.tb-btn { border: 1px solid var(--tb-line); background: var(--tb-card); color: var(--tb-ink); border-radius: 10px; padding: 10px 14px; cursor: pointer; font: inherit; font-size: 13px; font-weight: 500; }
.tb-btn:hover:not(:disabled) { border-color: var(--tb-navy); color: var(--tb-navy); }
.tb-btn:disabled { opacity: 0.5; cursor: default; }
.tb-alertas { list-style: none; margin: 8px 0 0; padding: 0; font-size: 13px; }
.tb-alertas li { padding: 6px 0; border-bottom: 1px solid var(--tb-line); }
.tb-alertas .tb-note { display: block; margin-top: 2px; }
.tb-printonly { display: none; }
.tb-remito { border: 1px dashed #93c5fd; border-radius: 12px; padding: 12px; background: rgba(147, 197, 253, 0.08); }
@media print {
    .tb-noprint { display: none !important; }
    .tb-printonly { display: block; margin-bottom: 12px; }
    .tb-printonly h2 { font-size: 18px; }
    .tb-printonly p { font-size: 12px; margin: 2px 0 0; }
    .tb-root { --tb-card: #fff; --tb-ink: #1f2937; --tb-muted: #6b7280; --tb-line: #e5e7eb; --tb-track: #eef0f6; --tb-head: #fafafc; }
    .tb-card, .tb-sect { break-inside: avoid; }
    .tb-tw { max-height: none !important; overflow: visible !important; }
    .tb-grid { gap: 10px; }
}
@media (max-width: 1100px) {
    .tb-k6 { grid-template-columns: repeat(3, 1fr); }
    .tb-g2, .tb-g2e, .tb-g3 { grid-template-columns: 1fr; }
    .tb-filters { position: static; }
}
@media (max-width: 560px) {
    .tb-k6 { grid-template-columns: repeat(2, 1fr); }
}
</style>
