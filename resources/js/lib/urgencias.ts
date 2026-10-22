/**
 * Cálculos del tablero de urgencias en facturas proforma.
 *
 * El dataset viaja entero desde `UrgenciaController` y acá se filtra: mismo
 * esquema que Compras. Cada proforma es `[mes, vendedor, cliente, urgente]`,
 * con índices a las listas `meses`, `vendedores` y `clientes`.
 *
 * Qué es "urgente" lo decide el backend (`App\Support\Urgencia`), no esta capa.
 */

export type FilaProforma = [mes: number, vendedor: number, cliente: number, urgente: 0 | 1]

/** Una proforma urgente, con lo necesario para el listado de control. */
export interface ProformaUrgente {
    n: string
    f: string
    m: number
    v: number
    c: number
    o: string | null
}

export interface DatasetUrgencias {
    meses: string[]
    vendedores: string[]
    clientes: string[]
    filas: FilaProforma[]
    urgentes: ProformaUrgente[]
}

/** `null` = todos. */
export interface FiltroUrgencias {
    mes: number | null
    vendedor: number | null
    cliente: number | null
}

export interface ConteoUrgencias {
    emitidas: number
    urgentes: number
    /** % de urgencias sobre lo emitido: "emitió 100, 20 urgentes → 20%". */
    pct: number
}

export interface FilaVendedor extends ConteoUrgencias { vendedor: number }

export interface FilaCliente extends ConteoUrgencias { cliente: number }

export interface PuntoMes extends ConteoUrgencias { mes: number }

export const porcentaje = (parte: number, total: number): number => (total ? (parte / total) * 100 : 0)

const pasa = (f: FilaProforma, filtro: FiltroUrgencias, ignorarMes = false): boolean =>
    (ignorarMes || filtro.mes === null || f[0] === filtro.mes)
    && (filtro.vendedor === null || f[1] === filtro.vendedor)
    && (filtro.cliente === null || f[2] === filtro.cliente)

const conteo = (emitidas: number, urgentes: number): ConteoUrgencias => ({ emitidas, urgentes, pct: porcentaje(urgentes, emitidas) })

/** Totales del filtro: las tres tarjetas de arriba. */
export const totales = (d: DatasetUrgencias, filtro: FiltroUrgencias): ConteoUrgencias => {
    let emitidas = 0
    let urgentes = 0
    for (const f of d.filas) {
        if (!pasa(f, filtro)) continue
        emitidas++
        urgentes += f[3]
    }
    return conteo(emitidas, urgentes)
}

/**
 * Por vendedor, del que más urgencias tiene al que menos (empate: el que más
 * emitió). La torta y la tabla salen de acá. No aplica el filtro de vendedor:
 * con un vendedor elegido, la torta tiene que seguir mostrando a los demás.
 */
export const porVendedor = (d: DatasetUrgencias, filtro: FiltroUrgencias): FilaVendedor[] => {
    const acum = new Map<number, [number, number]>()
    const sinVendedor = { ...filtro, vendedor: null }

    for (const f of d.filas) {
        if (!pasa(f, sinVendedor)) continue
        const a = acum.get(f[1]) ?? [0, 0]
        a[0]++
        a[1] += f[3]
        acum.set(f[1], a)
    }

    return [...acum.entries()]
        .map(([vendedor, [e, u]]) => ({ vendedor, ...conteo(e, u) }))
        .sort((a, b) => b.urgentes - a.urgentes || b.emitidas - a.emitidas)
}

/**
 * Los clientes de un vendedor en el filtro: lo que se despliega al pararse
 * sobre él (pedido de la captura del documento). Primero los que tuvieron
 * urgencias; `sinUrgencias` cuenta el resto.
 */
export const clientesDeVendedor = (
    d: DatasetUrgencias,
    filtro: FiltroUrgencias,
    vendedor: number,
): { conUrgencias: FilaCliente[]; sinUrgencias: number; emitidas: number; urgentes: number } => {
    const acum = new Map<number, [number, number]>()
    const delVendedor = { ...filtro, vendedor }

    for (const f of d.filas) {
        if (!pasa(f, delVendedor)) continue
        const a = acum.get(f[2]) ?? [0, 0]
        a[0]++
        a[1] += f[3]
        acum.set(f[2], a)
    }

    const todos = [...acum.entries()].map(([cliente, [e, u]]) => ({ cliente, ...conteo(e, u) }))

    return {
        conUrgencias: todos.filter(c => c.urgentes > 0).sort((a, b) => b.urgentes - a.urgentes || b.emitidas - a.emitidas),
        sinUrgencias: todos.filter(c => c.urgentes === 0).length,
        emitidas: todos.reduce((s, c) => s + c.emitidas, 0),
        urgentes: todos.reduce((s, c) => s + c.urgentes, 0),
    }
}

/**
 * Los últimos `n` meses, para la línea de evolución. Ignora el filtro de mes
 * (la línea ES la serie de meses) pero respeta vendedor y cliente.
 */
export const evolucion = (d: DatasetUrgencias, filtro: FiltroUrgencias, n = 12): PuntoMes[] => {
    const desde = Math.max(0, d.meses.length - n)
    const acum = d.meses.slice(desde).map(() => [0, 0] as [number, number])

    for (const f of d.filas) {
        if (f[0] < desde || !pasa(f, filtro, true)) continue
        acum[f[0] - desde][0]++
        acum[f[0] - desde][1] += f[3]
    }

    return acum.map(([e, u], i) => ({ mes: desde + i, ...conteo(e, u) }))
}

/** Las proformas urgentes del filtro, de la más nueva a la más vieja. */
export const urgentesDelFiltro = (d: DatasetUrgencias, filtro: FiltroUrgencias): ProformaUrgente[] =>
    d.urgentes
        .filter(u => (filtro.mes === null || u.m === filtro.mes)
            && (filtro.vendedor === null || u.v === filtro.vendedor)
            && (filtro.cliente === null || u.c === filtro.cliente))
        .sort((a, b) => b.f.localeCompare(a.f) || b.n.localeCompare(a.n))

/** `12,5%`. */
export const pctTexto = (x: number): string => `${x.toFixed(1).replace('.', ',')}%`
