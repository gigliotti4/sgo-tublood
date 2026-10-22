import type { ObservationHistoryEntry } from '@/types'

/**
 * Helpers para mostrar una entrada de bitácora: la forma de `cambios` varía
 * según `accion` (ver ObservacionObserver), así que estas funciones son la
 * única fuente de verdad para interpretarla.
 *
 * Compartido por BitacoraObservacion.vue (el timeline de un caso puntual) y
 * Admin/Bitacora/Index.vue (el listado transversal): duplicarlo garantizaba
 * que se desincronizaran apenas cambiara una de las dos formas de `cambios`.
 */

export interface CambioSimple { de: string; a: string }
export interface CambioClasificacion { prioridad: CambioSimple; tipo_caso: CambioSimple }
export interface CambioNotificados { sumados: string[]; sacados: string[] }
export interface CambioBaja { tipo: 'cancelacion' | 'borrado' }
/** Solo los campos que cambiaron, con el texto viejo y el nuevo completos. */
export interface CambioContenido { titulo?: CambioSimple; descripcion?: CambioSimple }

export const esClasificacion = (
    entrada: ObservationHistoryEntry,
): entrada is ObservationHistoryEntry & { cambios: CambioClasificacion } => entrada.accion === 'clasificacion'

export const esNotificados = (
    entrada: ObservationHistoryEntry,
): entrada is ObservationHistoryEntry & { cambios: CambioNotificados } => entrada.accion === 'notificados'

/** El motivo de la baja va en `entrada.nota`; `cambios.tipo` solo distingue cancelación de borrado. */
export const esBaja = (
    entrada: ObservationHistoryEntry,
): entrada is ObservationHistoryEntry & { cambios: CambioBaja } => entrada.accion === 'baja'

export const esContenido = (
    entrada: ObservationHistoryEntry,
): entrada is ObservationHistoryEntry & { cambios: CambioContenido } => entrada.accion === 'contenido'

/**
 * Los "Datos específicos" que tenía la observación antes de un cambio de tipo
 * (acción `tipo`, desde el 21/10/2026). El cambio los reemplaza, así que la
 * bitácora es el único lugar donde quedan. La clave se muestra legible
 * (`detalle_proceso` → "Detalle proceso"): la etiqueta original es del tipo
 * viejo y la pantalla no tiene la taxonomía.
 */
export const datosAnteriores = (entrada: ObservationHistoryEntry): [string, string][] => {
    if (entrada.accion !== 'tipo' || !entrada.cambios || !('datos_anteriores' in entrada.cambios)) return []

    const datos = (entrada.cambios as { datos_anteriores?: Record<string, unknown> }).datos_anteriores ?? {}

    return Object.entries(datos)
        .filter(([, v]) => v !== null && v !== '')
        .map(([k, v]) => [k.charAt(0).toUpperCase() + k.slice(1).replace(/_/g, ' '), String(v)])
}

export const comoCambioSimple = (cambios: ObservationHistoryEntry['cambios']): CambioSimple | null =>
    cambios && 'de' in cambios && 'a' in cambios ? (cambios as unknown as CambioSimple) : null

export const accionLabels: Record<string, string> = {
    comentario: 'Comentario',
    estado: 'Cambio de estado',
    responsable: 'Cambio de responsable',
    sector: 'Derivación de sector',
    clasificacion: 'Clasificación',
    contenido: 'Corrección del texto',
    tipo: 'Cambio de tipo',
    adjunto: 'Archivo adjunto',
    notificados: 'Usuarios a notificar',
    sistema: 'Sistema',
    baja: 'Baja',
    restauracion: 'Restauración',
}

export const accionVariant: Record<string, 'slate' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'amber' | 'red'> = {
    comentario: 'slate',
    estado: 'amber',
    responsable: 'indigo',
    sector: 'purple',
    clasificacion: 'blue',
    contenido: 'amber',
    tipo: 'purple',
    adjunto: 'slate',
    notificados: 'emerald',
    sistema: 'slate',
    baja: 'red',
    restauracion: 'emerald',
}

export const nombreAutor = (entrada: Pick<ObservationHistoryEntry, 'user'>) =>
    entrada.user ? [entrada.user.name, entrada.user.apellido].filter(Boolean).join(' ') : 'Sistema'

export const formatFechaHora = (fecha: string) =>
    new Date(fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })

export const formatSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
