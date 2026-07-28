import type { ObservationHistoryEntry } from '@/types'

/**
 * Helpers para mostrar una entrada de bitácora: la forma de `cambios` varía
 * según `accion` (ver ObservacionObserver), así que estas funciones son la
 * única fuente de verdad para interpretarla.
 *
 * Compartido por BitacoraObservacion.vue (el timeline de un caso puntual) y
 * Admin/Auditoria/Index.vue (el listado transversal): duplicarlo garantizaba
 * que se desincronizaran apenas cambiara una de las dos formas de `cambios`.
 */

export interface CambioSimple { de: string; a: string }
export interface CambioClasificacion { prioridad: CambioSimple; tipo_caso: CambioSimple }

export const esClasificacion = (
    entrada: ObservationHistoryEntry,
): entrada is ObservationHistoryEntry & { cambios: CambioClasificacion } => entrada.accion === 'clasificacion'

export const comoCambioSimple = (cambios: ObservationHistoryEntry['cambios']): CambioSimple | null =>
    cambios && 'de' in cambios && 'a' in cambios ? (cambios as unknown as CambioSimple) : null

export const accionLabels: Record<string, string> = {
    comentario: 'Comentario',
    estado: 'Cambio de estado',
    responsable: 'Cambio de responsable',
    sector: 'Derivación de sector',
    clasificacion: 'Clasificación',
    adjunto: 'Archivo adjunto',
    sistema: 'Sistema',
}

export const accionVariant: Record<string, 'slate' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'amber' | 'red'> = {
    comentario: 'slate',
    estado: 'amber',
    responsable: 'indigo',
    sector: 'purple',
    clasificacion: 'blue',
    adjunto: 'slate',
    sistema: 'slate',
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
