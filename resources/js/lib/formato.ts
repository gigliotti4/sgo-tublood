/**
 * Formato de números y fechas en es-AR.
 *
 * El resto del panel todavía tiene estos helpers duplicados inline en cada
 * página (unas 20 copias de `toLocaleString('es-AR')`). Este archivo es el lugar
 * al que hay que ir moviéndolos; el módulo Compras ya los usa desde acá.
 */

/** Entero con separador de miles. */
export const numero = (n: number): string => Math.round(n || 0).toLocaleString('es-AR')

/** Un decimal, sin el ",0" cuando es redondo. */
export const decimal = (n: number, decimales = 1): string =>
    (n || 0).toLocaleString('es-AR', {
        minimumFractionDigits: decimales,
        maximumFractionDigits: decimales,
    })

/**
 * Importe abreviado: los montos del tablero llegan a miles de millones y en la
 * tarjeta de KPI no entran con todos los dígitos.
 */
export const moneda = (v: number): string =>
    Math.abs(v) >= 1e6 ? `$${decimal(v / 1e6)} M` : `$${numero(v)}`

/** Importe completo, para tooltips y export. */
export const monedaExacta = (v: number): string =>
    (v || 0).toLocaleString('es-AR', {
        style: 'currency',
        currency: 'ARS',
        maximumFractionDigits: 2,
    })

/** Fecha y hora corta. `'Nunca'` cuando no hay valor. */
export const fechaHora = (valor: string | null | undefined): string =>
    valor
        ? new Date(valor).toLocaleString('es-AR', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : 'Nunca'

const MESES_CORTOS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']

/** `'2026-09'` → `'Sep 26'`. Es la etiqueta de las columnas de mes. */
export const etiquetaMes = (mes: string): string => {
    const [anio, numeroMes] = mes.split('-')
    return `${MESES_CORTOS[Number(numeroMes) - 1] ?? mes} ${anio?.slice(2) ?? ''}`
}
