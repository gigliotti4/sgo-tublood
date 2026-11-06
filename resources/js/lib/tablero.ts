/**
 * Las cuentas del tablero de gestión (el "Dashboard SGO TUBLOOD" del cliente):
 * qué observación cuenta para qué indicador, el semáforo, el uso del máximo y el
 * índice de fallos por sector.
 *
 * Todo corre en el navegador sobre el dataset entero que arma `TableroService`
 * (mismo esquema que `lib/compras.ts` y `lib/urgencias.ts`), así cambiar el
 * período o pararse en un sector no es un request.
 *
 * Son funciones puras, sin Vue ni DOM, a propósito: se verifican corriéndolas
 * con node sobre los ejemplos del prototipo del cliente (ver CLAUDE.md).
 *
 * ⚠️ `cuenta()` es una COPIA de `Indicador::cuenta()` (PHP). Si se cambia una
 * regla, cambia en las dos.
 */

export type Cls = 's-ok' | 's-warn' | 's-bad' | 's-na'

/** Una observación, con las claves cortas del dataset. */
export interface Caso {
    i: number
    n: string
    /** Fecha de alta, `YYYY-MM-DD`. */
    f: string
    /** Mes de alta, `YYYY-MM`. */
    m: string
    /** Clave del tipo de incidencia. */
    t: string
    /** Tipo con su código ("4.4 Falla de proceso"). */
    l: string
    /** Sector al que se derivó. */
    s: number | null
    /** 1 si es un fallo externo (E.1, E.2, E.3). */
    x: 0 | 1
    e: string
    /** Plazo vigente (la prórroga si la hay), `YYYY-MM-DD`. */
    v: string | null
    /** Fecha de cierre, `YYYY-MM-DD`. */
    z: string | null
    ti: string | null
    mo: string | null
    cl: string | null
    /** Número del cliente: se cruza contra los remitos. */
    cn: string | null
    tc: string | null
    cr: string | null
    /** Sector de quien la cargó; null si entró por el formulario del cliente. */
    cs: number | null
    /** Proveedor o transporte del fallo externo. */
    pv: string | null
    /** Responsable del fallo: solo viaja para quien puede verlo. */
    rf: string | null
}

export interface Criterios {
    titulo_contiene?: string
    titulo_no_contiene?: string
    motivo?: string[]
    motivo_no?: string[]
}

export interface IndicadorT {
    id: number
    sector: string
    sector_id: number | null
    fallo: string
    origen_dato: 'sgo' | 'manual'
    /** Códigos de los tipos que cuenta ("4.1"), o "fuera del SGO". */
    codigos: string
    tipos: string[]
    criterios: Criterios | []
    objetivo: number
    tolerancia: number
    tipo_meta: 'maximo' | 'minimo'
    cant_pedidos: number | null
    /** Importancia dentro del sector, en %. */
    importancia: number | null
    estado: string
    valores: Record<string, number>
}

export type Origen = 'Todos' | 'Internos' | 'Externos'

export interface EvalMes {
    mes: string
    valor: number | null
    objetivo: number
    tolerancia: number
}

export interface EvalTipo {
    ms: string[]
    vals: (number | null)[]
    /** Suma de los valores del período (lo que se muestra como cantidad). */
    tot: number
    /** Semáforo del PEOR mes. */
    worst: Semaforo
    /** Mes y valor del peor. */
    wm: string
    wv: number
    /** Cuántos meses excedieron. */
    nExc: number
    /** Uso del máximo promedio de los meses completos. */
    uso: number
    /** Si algún mes tuvo dato. */
    conDato: boolean
}

export type Semaforo = [Cls, string, string]

const COLORES = { ok: '#16A34A', warn: '#F59E0B', bad: '#DC2626' }

// ── Qué cuenta ──────────────────────────────────────────────────────

/** ¿Esta observación cuenta para este indicador del SGO? (copia de `Indicador::cuenta()`) */
export function cuenta(ind: IndicadorT, caso: Pick<Caso, 't' | 'ti' | 'mo'>): boolean {
    if (ind.origen_dato !== 'sgo' || !ind.tipos.includes(caso.t)) return false

    const c: Criterios = Array.isArray(ind.criterios) ? {} : ind.criterios
    const titulo = (caso.ti ?? '').toUpperCase()
    const motivo = caso.mo

    if (c.titulo_contiene && !titulo.includes(c.titulo_contiene.toUpperCase())) return false
    if (c.titulo_no_contiene && titulo.includes(c.titulo_no_contiene.toUpperCase())) return false
    if (c.motivo?.length && !(motivo !== null && c.motivo.includes(motivo))) return false
    // Un criterio de motivo exige que el caso TENGA motivo.
    if (c.motivo_no?.length && (!motivo || c.motivo_no.includes(motivo))) return false

    return true
}

// ── Período y filtros ───────────────────────────────────────────────

export type Modo = 'mes' | 'anual' | 'varios'

export function mesesDelPeriodo(modo: Modo, mes: string, elegidos: Iterable<string>, todos: string[]): string[] {
    if (modo === 'mes') return [mes]
    if (modo === 'anual') return todos

    return [...elegidos].sort()
}

export const okOrigen = (c: Pick<Caso, 'x'>, origen: Origen): boolean =>
    origen === 'Todos' || (origen === 'Externos') === (c.x === 1)

const ABIERTOS = ['pendiente_clasificacion', 'clasificada', 'en_proceso', 'derivada', 'derivada_nc']

export const esAbierta = (c: Pick<Caso, 'e'>) => ABIERTOS.includes(c.e)

/** Abierta con el plazo vigente ya cumplido. La prórroga cuenta: vence al terminar su día. */
export const esVencida = (c: Pick<Caso, 'e' | 'v'>, hoy: string) => esAbierta(c) && !!c.v && c.v < hoy

// ── Semáforo y uso del máximo ───────────────────────────────────────

/**
 * Máximo (menos es mejor): verde hasta el objetivo, amarillo hasta la tolerancia,
 * rojo por encima. Mínimo (más es mejor): al revés.
 */
export function semUno(valor: number | null, objetivo: number, tolerancia: number, tipo: 'maximo' | 'minimo'): Semaforo {
    if (valor === null) return ['s-na', 'Sin dato', '#9CA3AF']

    if (tipo === 'minimo') {
        if (valor >= objetivo) return ['s-ok', 'Cumple', COLORES.ok]

        return valor >= tolerancia ? ['s-warn', 'Sobre objetivo', COLORES.warn] : ['s-bad', 'Excede', COLORES.bad]
    }

    if (valor <= objetivo) return ['s-ok', 'Cumple', COLORES.ok]

    return valor <= tolerancia ? ['s-warn', 'Sobre objetivo', COLORES.warn] : ['s-bad', 'Excede', COLORES.bad]
}

/**
 * Cuánto del margen se usó: 1 = justo en la tolerancia, más de 1 = la pasó.
 *
 * Máximo: fallos ÷ tolerancia. Mínimo: lo que falta para el objetivo ÷ el margen
 * hasta la tolerancia (simétrico: llegar a la tolerancia es 100 %).
 * Con tolerancia 0 cualquier fallo ya la pasa.
 */
export function uso(valor: number, objetivo: number, tolerancia: number, tipo: 'maximo' | 'minimo'): number {
    if (tipo === 'minimo') {
        const margen = objetivo - tolerancia

        if (valor >= objetivo) return 0

        return margen > 0 ? (objetivo - valor) / margen : 1 + (objetivo - valor)
    }

    if (tolerancia > 0) return valor / tolerancia

    return valor > 0 ? 1 + valor : 0
}

/**
 * Objetivo y tolerancia de un mes. Los que dependen de los pedidos (el de armado
 * es un 1 % de los pedidos) escalan: la planilla trae el valor para
 * `cant_pedidos` y el mes real tuvo otra cantidad. Sin pedidos del mes, el base.
 */
export function limitesDelMes(ind: IndicadorT, mes: string, pedidos: Record<string, number>): { objetivo: number; tolerancia: number } {
    const real = pedidos[mes]

    if (!ind.cant_pedidos || !real) return { objetivo: ind.objetivo, tolerancia: ind.tolerancia }

    const k = real / ind.cant_pedidos

    return { objetivo: ind.objetivo * k, tolerancia: ind.tolerancia * k }
}

// ── Evaluación ──────────────────────────────────────────────────────

const RANGO: Record<Cls, number> = { 's-na': -1, 's-ok': 0, 's-warn': 1, 's-bad': 2 }

/** Los meses completos (sin el mes en curso) si hay alguno; si no, todos. */
export const mesesCompletos = (ms: string[], parcial: string): string[] => {
    const full = ms.filter(m => m !== parcial)

    return full.length ? full : ms
}

export function valorDelMes(ind: IndicadorT, mes: string, casos: Caso[]): number | null {
    // Los del SGO se cuentan de las observaciones; los demás, del valor cargado a mano.
    if (ind.origen_dato === 'sgo') return casos.filter(c => c.m === mes && cuenta(ind, c)).length

    return ind.valores[mes] ?? null
}

/**
 * Evaluación de un tipo de fallo en el período: cada mes contra su límite
 * mensual, y vale el PEOR mes (empata el de más fallos). El uso del máximo es el
 * promedio de los meses completos.
 */
export function evaluarTipo(
    ind: IndicadorT,
    casos: Caso[],
    ms: string[],
    parcial: string,
    pedidos: Record<string, number>,
): EvalTipo {
    const meses: EvalMes[] = ms.map(mes => ({ mes, valor: valorDelMes(ind, mes, casos), ...limitesDelMes(ind, mes, pedidos) }))
    const vals = meses.map(m => m.valor)
    const st = meses.map(m => semUno(m.valor, m.objetivo, m.tolerancia, ind.tipo_meta))

    let wi = 0
    st.forEach((x, k) => {
        const mejor = RANGO[x[0]] > RANGO[st[wi][0]]
            || (RANGO[x[0]] === RANGO[st[wi][0]] && (vals[k] ?? 0) > (vals[wi] ?? 0))

        if (mejor) wi = k
    })

    const usar = mesesCompletos(ms, parcial)
    const usados = meses.filter(m => usar.includes(m.mes) && m.valor !== null)
    const usoMedio = usados.length
        ? usados.reduce((a, m) => a + uso(m.valor as number, m.objetivo, m.tolerancia, ind.tipo_meta), 0) / usados.length
        : 0

    return {
        ms,
        vals,
        tot: vals.reduce<number>((a, v) => a + (v ?? 0), 0),
        worst: st[wi] ?? semUno(null, 0, 0, 'maximo'),
        wm: ms[wi],
        wv: vals[wi] ?? 0,
        nExc: st.filter(x => x[0] === 's-bad').length,
        uso: usoMedio,
        conDato: vals.some(v => v !== null),
    }
}

/** El peso de un indicador: su importancia; si algún indicador del sector no la tiene, todos pesan igual. */
function pesos(inds: IndicadorT[]): number[] {
    return inds.every(i => i.importancia !== null) ? inds.map(i => i.importancia as number) : inds.map(() => 1)
}

/**
 * Índice de fallos de un sector en UN mes: Σ importancia × (fallos ÷ máximo) ÷ Σ importancia.
 * 0 % = sin fallos · 100 % = justo en el máximo · más de 100 % = se pasó. Más alto es peor.
 * `g` es el mismo cálculo con el OBJETIVO en lugar de los fallos: hasta ahí es verde.
 *
 * Entran solo los indicadores con dato ese mes (un manual sin cargar no pesa).
 */
export function indiceMes(
    inds: IndicadorT[],
    mes: string,
    casos: Caso[],
    pedidos: Record<string, number>,
): { v: number; g: number } | null {
    const w = pesos(inds)
    let num = 0
    let g = 0
    let den = 0

    inds.forEach((ind, k) => {
        const valor = valorDelMes(ind, mes, casos)

        if (valor === null) return

        const { objetivo, tolerancia } = limitesDelMes(ind, mes, pedidos)

        num += w[k] * uso(valor, objetivo, tolerancia, ind.tipo_meta)
        g += w[k] * uso(objetivo, objetivo, tolerancia, ind.tipo_meta === 'minimo' ? 'maximo' : ind.tipo_meta)
        den += w[k]
    })

    return den ? { v: num / den, g: g / den } : null
}

/**
 * Índice del período: el promedio de los índices mensuales. El mes en curso no
 * entra mientras haya meses completos.
 */
export function indiceSector(
    inds: IndicadorT[],
    ms: string[],
    parcial: string,
    casos: Caso[],
    pedidos: Record<string, number>,
): { v: number; g: number } | null {
    const r = mesesCompletos(ms, parcial)
        .map(m => indiceMes(inds, m, casos, pedidos))
        .filter((x): x is { v: number; g: number } => x !== null)

    if (!r.length) return null

    return { v: r.reduce((a, c) => a + c.v, 0) / r.length, g: r[0].g }
}

/** Color del índice: verde hasta su meta, amarillo hasta 100 % del máximo, rojo si se pasó. */
export const cColor = (v: number, g: number | undefined): string =>
    v <= (g ?? 0.6) ? COLORES.ok : v <= 1 ? '#D97706' : COLORES.bad

export const cCls = (v: number, g: number | undefined): Cls =>
    v <= (g ?? 0.6) ? 's-ok' : v <= 1 ? 's-warn' : 's-bad'

// ── Utilidades ──────────────────────────────────────────────────────

/** Días entre dos fechas `YYYY-MM-DD`. */
export const dias = (a: string, b: string): number => Math.round((Date.parse(b) - Date.parse(a)) / 86400000)

export function mediana(a: number[]): number | null {
    if (!a.length) return null

    const x = [...a].sort((p, q) => p - q)
    const m = Math.floor(x.length / 2)

    return x.length % 2 ? x[m] : Math.round((x[m - 1] + x[m]) / 2)
}

/** Cuenta valores y los ordena de más a menos frecuente. */
export function contar<T extends Caso>(arr: T[], clave: (c: T) => string | number | null | undefined): [string, number][] {
    const m = new Map<string, number>()

    for (const c of arr) {
        const k = clave(c)

        if (k === null || k === undefined || k === '') continue

        m.set(String(k), (m.get(String(k)) ?? 0) + 1)
    }

    return [...m.entries()].sort((a, b) => b[1] - a[1])
}

/** "ACME S.A." → "ACME": para que el mismo cliente no figure dos veces. */
export const corto = (n: string): string => n.replace(/ S\.?\s?A\.?$| S\.?R\.?L\.?$| S R L$| SRL$/i, '')

export interface Tiempos {
    cerradas: number
    promedio: number | null
    mediana: number | null
    maximo: number | null
    /** Cerradas en o antes del plazo, sobre las cerradas. */
    enPlazo: number
    abiertas: number
    /** Antigüedad promedio (días) de las abiertas. */
    edad: number | null
    vencidas: number
}

export function tiemposDe(casos: Caso[], hoy: string): Tiempos {
    const cerr = casos.filter(c => c.z)
    const d = cerr.map(c => dias(c.f, c.z as string))
    const ab = casos.filter(esAbierta)
    const edades = ab.map(c => dias(c.f, hoy))

    return {
        cerradas: cerr.length,
        promedio: d.length ? Math.round((d.reduce((a, b) => a + b, 0) / d.length) * 10) / 10 : null,
        mediana: mediana(d),
        maximo: d.length ? Math.max(...d) : null,
        enPlazo: cerr.filter(c => c.v && (c.z as string) <= c.v).length,
        abiertas: ab.length,
        edad: edades.length ? Math.round(edades.reduce((a, b) => a + b, 0) / edades.length) : null,
        vencidas: ab.filter(c => esVencida(c, hoy)).length,
    }
}

// ── Tipos de los gráficos ───────────────────────────────────────────

export interface ItemBarra {
    k: string
    v: number
    color: string
    /** Línea punteada roja a esta altura (el máximo permitido). */
    max?: number
    title?: string
    label2?: string
    label2c?: string | null
}

export interface Serie {
    name: string
    color: string
    vals: number[]
}
