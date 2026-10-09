/**
 * Cómo se muestran las fechas de entrega de las OC pendientes en el tablero.
 *
 * Aparte de `compras.ts` porque es presentación, no cálculo: lo comparten la
 * fila del producto y la del artículo, y el export.
 */

import { estaAtrasada, type EntregaFila } from '@/lib/compras'
import { numero } from '@/lib/formato'

/** `2026-10-09` → `09/10/26`. */
export const fechaEntrega = (iso: string | null): string => {
    if (!iso) return ''
    const [a, m, d] = iso.split('-')
    return `${d}/${m}/${a?.slice(2)}`
}

/**
 * Todas las entregas de un producto, una por línea, para el tooltip.
 *
 * Compras carga entregas escalonadas (una OC con tres renglones, cada uno con
 * su fecha): la celda muestra la más próxima y acá van todas.
 */
export const describirEntregas = (entregas: EntregaFila[]): string | undefined => {
    if (!entregas.length) return undefined

    return entregas
        .map(e => {
            const cuando = e.fecha ? fechaEntrega(e.fecha) : 'sin fecha'
            const atraso = estaAtrasada(e.fecha) ? ' (atrasada)' : ''
            return `${cuando}${atraso} · ${numero(e.cantidad)} u. · ${e.codigo}`
        })
        .join('\n')
}
