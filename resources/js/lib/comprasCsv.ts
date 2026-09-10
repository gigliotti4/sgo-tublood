/**
 * Export del tablero de reposición a CSV.
 *
 * ⚠️ Se genera en el navegador, a diferencia del resto del panel, que baja
 * .xlsx armados con PhpSpreadsheet. El motivo es concreto: el tablero calcula
 * todo del lado del cliente, así que un export server-side obligaría a duplicar
 * en PHP el filtrado, el orden, el Pareto y las fórmulas. Esa copia es
 * exactamente donde los números dejarían de coincidir con lo que se ve en
 * pantalla — y "lo que ves es lo que baja" es el punto del botón.
 *
 * ⚠️ CSV y no XLSX: BOM UTF-8, separador `;` y coma decimal. Con eso Excel en
 * español lo abre con doble clic y reconoce los números sin pasar por el
 * asistente de importación. Con `,` como separador, Excel es-AR mete todo en
 * una sola columna.
 */

import type { FilaReposicion, FiltrosCompras } from '@/lib/compras'
import { detalleDeItem } from '@/lib/compras'
import { etiquetaMes } from '@/lib/formato'

type Celda = string | number | null | undefined

/** Entero sin separador de miles: el separador de Excel es el punto decimal. */
const entero = (v: number): string => String(Math.round(v || 0))

/** Decimal con coma, que es lo que espera Excel en español. */
const decimal = (v: number, decimales = 1): string => (Number(v) || 0).toFixed(decimales).replace('.', ',')

const celda = (v: Celda): string => {
    const t = v === null || v === undefined ? '' : String(v)
    return /[";\n\r]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t
}

const bajar = (filas: Celda[][], nombre: string): void => {
    // El BOM es lo que le dice a Excel que el archivo es UTF-8. Sin él, los
    // acentos y las eñes salen rotos.
    const texto = '﻿' + filas.map(f => f.map(celda).join(';')).join('\r\n')
    const url = URL.createObjectURL(new Blob([texto], { type: 'text/csv;charset=utf-8;' }))

    const a = document.createElement('a')
    a.href = url
    a.download = `${nombre}_${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)

    setTimeout(() => URL.revokeObjectURL(url), 1000)
}

const periodo = (meses: string[], f: FiltrosCompras): string =>
    `${etiquetaMes(meses[f.desde] ?? '')}-${etiquetaMes(meses[f.hasta] ?? '')}`

/** Un renglón por producto unificado. Respeta filtros y orden vigentes. */
export const exportarResumen = (
    filas: FilaReposicion[],
    filtros: FiltrosCompras,
    meses: string[],
    catName: (c: string) => string,
): void => {
    const etiquetas = meses.map(etiquetaMes)

    const out: Celda[][] = [[
        'Multimarca GTIN', 'Tipo', 'Filas', 'U. x Envase', 'Stock (u.)', 'Stock (env.)',
        `Ventas ${periodo(meses, filtros)} (u.)`, 'Prom. mensual (u.)', 'Reservado (u.)',
        'OC pend. (u.)', 'Stock total disp. (u.)', '¿Cubre?', 'Meses cubro', 'Cant. a comprar (u.)',
        'Pareto', 'Facturación período', '% facturación', '% acumulado',
        ...etiquetas,
    ]]

    for (const r of filas) {
        out.push([
            r.grupo.n,
            r.grupo.c.map(catName).join(' / '),
            r.items.length,
            r.envase === 0 ? 'varios' : r.envase > 1 ? entero(r.envase) : '',
            entero(r.stockU),
            entero(r.stockEnv),
            entero(r.ventaPeriodo),
            decimal(r.promMensual),
            entero(r.reservado),
            entero(r.ocPend),
            entero(r.stockTotal),
            r.estado === 'sv' ? 'SIN VENTA' : r.estado === 'si' ? 'SI' : 'NO',
            // Un producto sin ventas no tiene cobertura: celda vacía, no 0.
            r.estado === 'sv' || r.meses === Infinity ? '' : decimal(r.meses),
            decimal(r.cantComprar),
            r.pareto ?? '',
            decimal(r.importe, 2),
            decimal(r.pctFact, 2),
            decimal(r.cumPct, 2),
            ...r.ventas.map(entero),
        ])
    }

    bajar(out, 'Compras_resumen')
}

/** Un renglón por artículo, con código y descripción. Mismos filtros y orden. */
export const exportarDetalle = (
    filas: FilaReposicion[],
    filtros: FiltrosCompras,
    meses: string[],
    catName: (c: string) => string,
): void => {
    const etiquetas = meses.map(etiquetaMes)

    const out: Celda[][] = [[
        'Multimarca GTIN', 'Código', 'Descripción', 'Categoría', 'Activo', 'U. x Envase',
        'Stock (u.)', 'Stock (env.)', `Ventas ${periodo(meses, filtros)} (u.)`, 'Prom. mensual (u.)',
        'Reservado (u.)', 'OC pend. (u.)', 'Stock total disp. (u.)',
        ...etiquetas,
    ]]

    for (const r of filas) {
        for (const it of r.items) {
            const d = detalleDeItem(it, filtros, meses.length)

            out.push([
                r.grupo.n,
                // Como texto: hay códigos que empiezan con cero y Excel se los come.
                it.c,
                it.d,
                catName(it.k),
                it.a ? 'SI' : 'NO',
                d.envase > 1 ? entero(d.envase) : '',
                entero(d.stockU),
                entero(d.stockEnv),
                entero(d.ventaPeriodo),
                decimal(d.promMensual),
                entero(d.reservado),
                entero(d.ocPend),
                entero(d.stockTotal),
                ...d.ventas.map(entero),
            ])
        }
    }

    bajar(out, 'Compras_detalle')
}
