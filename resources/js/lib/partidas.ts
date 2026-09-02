import type { ObservationProduct } from '@/types'

/**
 * Qué sabe el padrón de partidas del lote que declaró el cliente.
 *
 * Vive acá y no en cada pantalla por el mismo motivo que `lib/productos.ts`:
 * lo van a usar el detalle y el modal del listado, y duplicarlo garantizaba
 * que se desincronizaran.
 *
 * ⚠️ Distingue los "sin dato" a propósito, porque se arreglan en lugares
 * distintos:
 *   - sin lote declarado → se le pide al cliente (o se completa el reclamo);
 *   - lote que no está en el padrón → o está mal tipeado, o la partida es de
 *     un artículo distinto al que dice el reclamo. Se revisa la observación.
 */
export function partidaDeProducto(producto: ObservationProduct): {
    texto: string
    atribuida: boolean
} {
    if (!producto.lote?.trim()) {
        return { texto: 'Sin lote declarado', atribuida: false }
    }

    if (!producto.partida) {
        return { texto: 'Lote sin partida en el padrón', atribuida: false }
    }

    return { texto: producto.partida.codigo_partida, atribuida: true }
}

/**
 * ¿El vencimiento que declaró el cliente coincide con el del padrón?
 *
 * Sirve para marcar la discrepancia en pantalla: si no coinciden, uno de los
 * dos está mal y conviene mirarlo antes de contestarle al cliente. Devuelve
 * `null` cuando falta alguno de los dos y no hay nada que comparar.
 */
export function vencimientoDiscrepa(producto: ObservationProduct): boolean | null {
    const declarado = producto.fecha_vencimiento?.slice(0, 10)
    const padron = producto.partida?.fecha_vencimiento?.slice(0, 10)

    if (!declarado || !padron) {
        return null
    }

    return declarado !== padron
}
