import type { ObservationProduct } from '@/types'

/**
 * De quién es el producto que falló, para mostrar en las tablas de productos.
 *
 * Vive acá y no en cada pantalla porque lo usan el detalle (`Show.vue`), el
 * modal del listado y —con el mismo criterio— el ranking del Dashboard: si se
 * duplicara, se desincronizarían. Mismo motivo que `lib/bitacora.ts`.
 *
 * ⚠️ Distingue los dos "sin dato" a propósito, porque se arreglan en lugares
 * distintos y confundirlos manda a buscar al lado equivocado:
 *   - el código no matchea ningún artículo del catálogo → se corrige el código
 *     de la observación (o falta sincronizar el artículo);
 *   - el artículo existe pero no tiene proveedor → se corrige en el padrón de
 *     artículos.
 */
export function proveedorDeProducto(producto: ObservationProduct): {
    texto: string
    atribuido: boolean
} {
    if (!producto.articulo) {
        return { texto: 'Código sin artículo', atribuido: false }
    }

    if (!producto.articulo.proveedor) {
        return { texto: 'Artículo sin proveedor', atribuido: false }
    }

    return { texto: producto.articulo.proveedor.razon_social, atribuido: true }
}
