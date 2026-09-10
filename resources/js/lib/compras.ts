/**
 * Motor de cálculo del tablero de reposición.
 *
 * Vive acá y no en el servidor porque cada número de la tabla depende del
 * período y del objetivo de cobertura que elige el usuario: recalcularlo en PHP
 * sería un roundtrip por cada click. `ReposicionService` manda los datos crudos
 * por artículo y todo lo derivado se arma acá.
 *
 * Lo comparten la página, la tabla y los dos exports: las fórmulas se escriben
 * una sola vez. Un export que recorriera su propia copia sería exactamente donde
 * los números dejarían de coincidir con lo que se ve en pantalla.
 */

import type { ArticuloReposicion, GrupoReposicion } from '@/types'

/** Estado de cobertura. Son TRES valores, no dos — ver `estadoDe()`. */
export type EstadoCobertura = 'si' | 'no' | 'sv'

export type ClasePareto = 'A' | 'B' | null

export interface FiltrosCompras {
    /** Meses de stock que se quieren tener cubiertos. */
    mesesObjetivo: number
    /** Índices (inclusive) dentro del array de meses. */
    desde: number
    hasta: number
    cats: string[]
    activo: 'all' | 'si' | 'no'
    stock: 'all' | 'si' | 'no'
    cubre: 'all' | EstadoCobertura
    pareto: 'all' | 'A' | 'B'
    q: string
    servicios: boolean
}

export interface FilaReposicion {
    grupo: GrupoReposicion
    /** Los artículos del grupo que pasaron los filtros por artículo. */
    items: ArticuloReposicion[]
    /** Envase para MOSTRAR: 0 = "varios". El cálculo usa el de cada artículo. */
    envase: number
    /** Ventas en unidades, mes a mes, de todo el historial. */
    ventas: number[]
    stockU: number
    stockEnv: number
    reservado: number
    ocPend: number
    stockTotal: number
    ventaPeriodo: number
    promMensual: number
    meses: number
    cubre: boolean
    cantComprar: number
    estado: EstadoCobertura
    importe: number
    /** El ERP trae stock negativo en algún artículo del grupo: se contó 0. */
    anomalia: boolean
    pareto: ClasePareto
    pctFact: number
    cumPct: number
}

export const filtrosPorDefecto = (mesesObjetivo: number, ultimoMes: number): FiltrosCompras => ({
    mesesObjetivo,
    desde: 0,
    hasta: ultimoMes,
    cats: [],
    activo: 'all',
    stock: 'all',
    cubre: 'all',
    pareto: 'all',
    q: '',
    servicios: false,
})

/**
 * Unidades por envase de un artículo, con el default aplicado.
 *
 * El ERP deja `CODIGO_REFERENCIA` vacío o en 0 cuando se cuenta de a uno.
 */
const envaseDe = (it: ArticuloReposicion): number => (it.u && it.u > 0 ? it.u : 1)

/** ¿Este artículo entra, según los filtros que se aplican por artículo? */
const itemPasa = (it: ArticuloReposicion, f: FiltrosCompras): boolean => {
    if (f.activo === 'si' && !it.a) return false
    if (f.activo === 'no' && it.a) return false

    const conStock = it.s > 0
    if (f.stock === 'si' && !conStock) return false
    if (f.stock === 'no' && conStock) return false

    return true
}

/**
 * Las columnas calculadas de un grupo, o `null` si ningún artículo pasa.
 *
 * ⚠️ **Se multiplica por el envase, nunca se divide.** El ERP cuenta en envases
 * y el tablero entero está en unidades.
 *
 * ⚠️ **El envase es POR ARTÍCULO, no por grupo.** Un grupo puede mezclar un
 * artículo x500 con otro x150 (hoy pasa en 8 grupos): cada uno convierte con el
 * suyo y el grupo suma en unidades. `envase` es solo para mostrar.
 */
export const computeGroup = (
    g: GrupoReposicion,
    f: FiltrosCompras,
    totalMeses: number,
): FilaReposicion | null => {
    const items = g.i.filter(it => itemPasa(it, f))
    if (!items.length) return null

    const ventas = new Array<number>(totalMeses).fill(0)
    let stockEnv = 0
    let stockU = 0
    let reservado = 0
    let ocPend = 0
    let importe = 0
    let anomalia = false

    for (const it of items) {
        const u = envaseDe(it)

        // Un stock físico negativo es imposible: es un error de carga del ERP
        // (hoy 15 artículos, el peor en varios millones). Se cuenta 0 y se marca
        // el producto. Sin esto, ordenar por "cantidad a comprar" llena el tope
        // de basura.
        if (it.s < 0) anomalia = true
        const st = Math.max(0, it.s)

        stockEnv += st // envases crudos del ERP, comparables contra su pantalla
        stockU += st * u
        reservado += it.r * u
        ocPend += it.o * u

        if (it.v) for (let i = 0; i < totalMeses; i++) ventas[i] += it.v[i] * u
        // Las notas de crédito ya vienen en negativo desde el ERP: se suman tal
        // cual y por eso restan solas.
        if (it.m) for (let i = f.desde; i <= f.hasta; i++) importe += it.m[i]
    }

    const nMeses = f.hasta - f.desde + 1
    let ventaPeriodo = 0
    for (let i = f.desde; i <= f.hasta; i++) ventaPeriodo += ventas[i]

    const promMensual = ventaPeriodo / nMeses
    const stockTotal = stockU - reservado + ocPend

    // Si el disponible es 0 o negativo la cobertura es 0: no existen los "meses
    // negativos" de stock.
    const meses = stockTotal <= 0 ? 0 : promMensual > 0 ? stockTotal / promMensual : Infinity
    const cubre = meses >= f.mesesObjetivo

    return {
        grupo: g,
        items,
        envase: g.u,
        ventas,
        stockU,
        stockEnv,
        reservado,
        ocPend,
        stockTotal,
        ventaPeriodo,
        promMensual,
        meses,
        cubre,
        cantComprar: Math.max(0, promMensual * f.mesesObjetivo - stockTotal),
        estado: estadoDe(promMensual, cubre),
        importe,
        anomalia,
        pareto: null,
        pctFact: 0,
        cumPct: 0,
    }
}

/**
 * ⚠️ Son TRES estados, no dos.
 *
 * Un producto sin ventas en el período **no es** un "NO cubre": no tiene
 * cobertura medible. Meterlo en "NO" ensucia la lista de urgencias — en el
 * prototipo los "NO" bajaron de 342 a 166 reales al separar los 276 sin venta.
 */
const estadoDe = (promMensual: number, cubre: boolean): EstadoCobertura =>
    promMensual <= 0 ? 'sv' : cubre ? 'si' : 'no'

/**
 * Clasificación ABC sobre la facturación del período.
 *
 * Se calcula sobre el conjunto YA filtrado (menos el propio filtro de Pareto):
 * si se filtra una categoría, da el 80/20 de esa categoría. Muta las filas.
 *
 * `A` = entra en el 80% acumulado — el producto que CRUZA el 80% va en A.
 * `B` = el 20% restante. Sin facturación en el período: sin clase, y afuera de
 * los dos filtros.
 */
export const clasificarPareto = (rows: FilaReposicion[]): number => {
    for (const r of rows) {
        r.pareto = null
        r.pctFact = 0
        r.cumPct = 0
    }

    const conFact = rows.filter(r => r.importe > 0).sort((a, b) => b.importe - a.importe)
    const total = conFact.reduce((a, r) => a + r.importe, 0)

    let acumulado = 0

    for (const r of conFact) {
        const previo = acumulado
        acumulado += r.importe
        r.pctFact = total ? (r.importe / total) * 100 : 0
        r.cumPct = total ? (acumulado / total) * 100 : 0
        r.pareto = previo < total * 0.8 ? 'A' : 'B'
    }

    return total
}

const matchQ = (g: GrupoReposicion, q: string, catName: (c: string) => string): boolean => {
    if (!q) return true

    if (g.n.toLowerCase().includes(q)) return true
    if (g.c.map(catName).join(' / ').toLowerCase().includes(q)) return true

    return g.i.some(it => it.c.toLowerCase().includes(q) || it.d.toLowerCase().includes(q))
}

/**
 * Las filas que pasan todos los filtros, con el Pareto ya clasificado.
 *
 * Devuelve también la facturación total del conjunto, que el KPI necesita y no
 * se puede recalcular después sin volver a sumar.
 */
export const filtrar = (
    groups: GrupoReposicion[],
    f: FiltrosCompras,
    totalMeses: number,
    catName: (c: string) => string,
): { filas: FilaReposicion[]; totalFacturacion: number } => {
    const q = f.q.trim().toLowerCase()
    const cats = new Set(f.cats)
    const filas: FilaReposicion[] = []

    for (const g of groups) {
        // Un grupo se excluye solo si TODOS sus artículos no mueven stock.
        if (!f.servicios && g.c.every(c => c === 'SERVICIOS')) continue

        // ⚠️ La categoría es POR ARTÍCULO: el grupo aparece si CUALQUIERA de
        // sus categorías está seleccionada.
        if (cats.size && !g.c.some(c => cats.has(c))) continue

        if (!matchQ(g, q, catName)) continue

        const fila = computeGroup(g, f, totalMeses)
        if (!fila) continue
        if (f.cubre !== 'all' && fila.estado !== f.cubre) continue

        filas.push(fila)
    }

    const totalFacturacion = clasificarPareto(filas)

    return {
        filas: f.pareto === 'all' ? filas : filas.filter(r => r.pareto === f.pareto),
        totalFacturacion,
    }
}

export type ClaveOrden =
    | 'gtin' | 'cat' | 'filas' | 'envase' | 'stockU' | 'stockEnv' | 'ventas'
    | 'prom' | 'reservado' | 'oc' | 'total' | 'cubre' | 'meses' | 'comprar'
    | `m${number}`

const valorDeOrden = (
    r: FilaReposicion,
    key: ClaveOrden,
    catName: (c: string) => string,
): string | number => {
    switch (key) {
        case 'gtin': return r.grupo.n
        case 'cat': return r.grupo.c.map(catName).join(' / ')
        case 'filas': return r.items.length
        case 'envase': return r.envase
        case 'stockU': return r.stockU
        case 'stockEnv': return r.stockEnv
        case 'ventas': return r.ventaPeriodo
        case 'prom': return r.promMensual
        case 'reservado': return r.reservado
        case 'oc': return r.ocPend
        case 'total': return r.stockTotal
        // El semáforo ordena por gravedad, no alfabéticamente.
        case 'cubre': return r.estado === 'si' ? 2 : r.estado === 'sv' ? 1 : 0
        case 'meses': return r.meses === Infinity ? Number.MAX_VALUE : r.meses
        case 'comprar': return r.cantComprar
        default: {
            const m = /^m(\d+)$/.exec(key)
            return m ? (r.ventas[Number(m[1])] ?? 0) : 0
        }
    }
}

export const ordenar = (
    rows: FilaReposicion[],
    key: ClaveOrden | null,
    dir: 'asc' | 'desc',
    catName: (c: string) => string,
): FilaReposicion[] => {
    if (!key) return rows

    return rows.slice().sort((a, b) => {
        const va = valorDeOrden(a, key, catName)
        const vb = valorDeOrden(b, key, catName)

        if (typeof va === 'string' && typeof vb === 'string') {
            const c = va.localeCompare(vb, 'es')
            return dir === 'asc' ? c : -c
        }

        return dir === 'asc' ? (va as number) - (vb as number) : (vb as number) - (va as number)
    })
}

export interface KpisCompras {
    total: number
    aComprar: number
    stockOk: number
    sinVenta: number
    categorias: number
    promedioMeses: number
    medianaMeses: number
    conCobertura: number
    facturacion: number
    claseA: number
    claseB: number
}

/** Los KPIs siempre se calculan sobre TODO el conjunto filtrado, no sobre la página. */
export const calcularKpis = (rows: FilaReposicion[]): KpisCompras => {
    // Los "sin venta" no tienen cobertura medible: quedan fuera del promedio.
    const conCobertura = rows.filter(r => r.estado !== 'sv' && Number.isFinite(r.meses))
    const meses = conCobertura.map(r => r.meses).sort((a, b) => a - b)
    const mitad = meses.length >> 1

    return {
        total: rows.length,
        aComprar: rows.filter(r => r.estado === 'no').length,
        stockOk: rows.filter(r => r.estado === 'si').length,
        sinVenta: rows.filter(r => r.estado === 'sv').length,
        categorias: new Set(rows.flatMap(r => r.grupo.c)).size,
        promedioMeses: meses.length ? meses.reduce((a, m) => a + m, 0) / meses.length : 0,
        // La mediana es mucho más representativa: el promedio se dispara con
        // productos de stock alto y venta mínima (coberturas de cientos de meses).
        medianaMeses: meses.length
            ? (meses.length % 2 ? meses[mitad]! : ((meses[mitad - 1]! + meses[mitad]!) / 2))
            : 0,
        conCobertura: conCobertura.length,
        facturacion: rows.reduce((a, r) => a + Math.max(0, r.importe), 0),
        claseA: rows.filter(r => r.pareto === 'A').length,
        claseB: rows.filter(r => r.pareto === 'B').length,
    }
}

export interface TotalesCompras {
    stockU: number
    stockEnv: number
    ventaPeriodo: number
    promMensual: number
    reservado: number
    ocPend: number
    stockTotal: number
    cantComprar: number
    ventasPorMes: number[]
}

/** La fila de TOTAL: suma todo el conjunto filtrado, no la página visible. */
export const calcularTotales = (rows: FilaReposicion[], totalMeses: number): TotalesCompras => {
    const ventasPorMes = new Array<number>(totalMeses).fill(0)

    for (const r of rows) {
        for (let i = 0; i < totalMeses; i++) ventasPorMes[i] += r.ventas[i] ?? 0
    }

    const suma = (f: (r: FilaReposicion) => number) => rows.reduce((a, r) => a + f(r), 0)

    return {
        stockU: suma(r => r.stockU),
        stockEnv: suma(r => r.stockEnv),
        ventaPeriodo: suma(r => r.ventaPeriodo),
        promMensual: suma(r => r.promMensual),
        reservado: suma(r => r.reservado),
        ocPend: suma(r => r.ocPend),
        stockTotal: suma(r => r.stockTotal),
        cantComprar: suma(r => r.cantComprar),
        ventasPorMes,
    }
}

/** Las columnas calculadas de UN artículo, para la fila de detalle y el export. */
export const detalleDeItem = (it: ArticuloReposicion, f: FiltrosCompras, totalMeses: number) => {
    const u = envaseDe(it)
    const stockEnv = Math.max(0, it.s)
    const ventas = (it.v ?? new Array<number>(totalMeses).fill(0)).map(x => x * u)

    let ventaPeriodo = 0
    for (let i = f.desde; i <= f.hasta; i++) ventaPeriodo += ventas[i] ?? 0

    const stockU = stockEnv * u
    const reservado = it.r * u
    const ocPend = it.o * u

    return {
        envase: u,
        stockEnv,
        stockU,
        ventas,
        ventaPeriodo,
        promMensual: ventaPeriodo / (f.hasta - f.desde + 1),
        reservado,
        ocPend,
        stockTotal: stockU - reservado + ocPend,
    }
}
