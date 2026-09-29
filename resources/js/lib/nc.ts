/**
 * Etiquetas y lectura de la bitácora de una No Conformidad.
 *
 * Vive acá y no en cada pantalla por el mismo motivo que `lib/bitacora.ts`: lo
 * usan el listado, la ficha y el bloque de bitácora, y duplicarlo garantizaba
 * que se desincronizaran.
 *
 * ⚠️ **Espejo del backend.** `estadoLabels` replica `NoConformidad::ESTADOS` y
 * `accionLabels` replica `NonConformityHistory::ACCION_LABELS`. Si se agrega un
 * estado o una acción, hay que tocar los dos lados.
 */

import type { NoConformidad, NonConformityHistoryEntry } from '@/types'

type Variante = 'slate' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'amber' | 'red'

export const estadoLabels: Record<string, string> = {
    borrador: 'Borrador',
    pendiente_aprobacion: 'Pendiente de aprobación',
    abierta: 'Abierta / En investigación',
    plan_accion: 'Plan de acción',
    en_implementacion: 'En implementación',
    verificacion_eficacia: 'Verificación de eficacia',
    cerrada: 'Cerrada',
    rechazada: 'Rechazada',
    cancelada: 'Cancelada',
}

/**
 * El color cuenta en qué etapa del circuito está la NC:
 * gris lo que todavía no entró, ámbar lo que espera una decisión, azul/violeta
 * el trabajo en curso, verde lo cerrado y rojo lo que no prosperó.
 */
export const estadoVariant: Record<string, Variante> = {
    borrador: 'slate',
    pendiente_aprobacion: 'amber',
    abierta: 'blue',
    plan_accion: 'indigo',
    en_implementacion: 'indigo',
    verificacion_eficacia: 'purple',
    cerrada: 'emerald',
    rechazada: 'red',
    cancelada: 'slate',
}

export const accionLabels: Record<string, string> = {
    comentario: 'Comentario',
    estado: 'Cambio de estado',
    aprobacion: 'Aprobación',
    devolucion: 'Devolución',
    rechazo: 'Rechazo',
    observaciones: 'Observaciones vinculadas',
    investigacion: 'Investigación',
    contencion: 'Acción de contención',
    responsable: 'Responsable del caso',
    plan: 'Plan de acción',
    avance: 'Avance de acción',
    verificacion: 'Verificación de eficacia',
    cierre: 'Cierre',
    adjunto: 'Archivo adjunto',
    reapertura: 'Reapertura',
    cancelacion: 'Cancelación',
    sistema: 'Sistema',
}

export const accionVariant: Record<string, Variante> = {
    comentario: 'slate',
    estado: 'blue',
    aprobacion: 'emerald',
    devolucion: 'amber',
    rechazo: 'red',
    observaciones: 'indigo',
    investigacion: 'blue',
    contencion: 'amber',
    responsable: 'indigo',
    plan: 'indigo',
    avance: 'indigo',
    verificacion: 'purple',
    cierre: 'emerald',
    adjunto: 'slate',
    reapertura: 'amber',
    cancelacion: 'red',
    sistema: 'slate',
}

/**
 * Las cinco condiciones que traban el cierre, en el orden en que se cumplen.
 *
 * ⚠️ Espejo de `NoConformidad::condicionesDeCierre()`: las claves son las que
 * devuelve ese método, y el orden de este objeto es el de la checklist en
 * pantalla. Una condición nueva en el backend que no esté acá **no se muestra**
 * y la persona no entiende por qué no puede cerrar.
 *
 * ⚠️ §4.8 enumera siete. Las evidencias adjuntas y las observaciones vinculadas
 * cerradas dejaron de trabar el 28/9/2026 y salieron de acá: ahora se avisan
 * sin bloquear, por `advertenciasCierre`.
 */
export const condicionesCierreLabels: Record<string, string> = {
    investigacion: 'Investigación completa',
    causa: 'Causa raíz analizada',
    acciones: 'Acciones del plan completadas o canceladas',
    verificada: 'Eficacia verificada',
    eficaz: 'La verificación dio eficaz',
}

/**
 * Cómo se muestra el número de una NC.
 *
 * ⚠️ Una NC en borrador o rechazada **no tiene número** y eso es correcto, no
 * un dato faltante: recién se asigna al aprobarla (§4.4). Por eso dice "Sin
 * número" y no un guion mudo, que se leería como un error de carga.
 */
export function numeroDe(nc: NoConformidad): { texto: string; asignado: boolean } {
    return nc.numero
        ? { texto: nc.numero, asignado: true }
        : { texto: 'Sin número', asignado: false }
}

/** `{de, a}` de un cambio de estado. `null` cuando la entrada no es de esa forma. */
export function comoCambioDeEstado(
    cambios: Record<string, unknown> | null,
): { de: string; a: string } | null {
    if (!cambios || typeof cambios.de !== 'string' || typeof cambios.a !== 'string') {
        return null
    }

    return { de: cambios.de, a: cambios.a }
}

/** Entrada del vínculo con observaciones: `{sumadas, sacadas}` con los números. */
export function comoObservaciones(
    entrada: NonConformityHistoryEntry,
): { sumadas: string[]; sacadas: string[] } | null {
    if (entrada.accion !== 'observaciones' || !entrada.cambios) {
        return null
    }

    return {
        sumadas: (entrada.cambios.sumadas as string[]) ?? [],
        sacadas: (entrada.cambios.sacadas as string[]) ?? [],
    }
}

/**
 * Cambio de responsable del caso: `{de, a}` con los nombres, o `null` en cada
 * punta si no había nadie / quedó sin asignar.
 *
 * ⚠️ Se chequea **antes** que `comoCambioDeEstado()`, que mira la misma forma
 * `{de, a}` y si no lo leería como un cambio de estado.
 */
export function comoResponsable(
    entrada: NonConformityHistoryEntry,
): { de: string | null; a: string | null } | null {
    if (entrada.accion !== 'responsable' || !entrada.cambios) {
        return null
    }

    return {
        de: (entrada.cambios.de as string) ?? null,
        a: (entrada.cambios.a as string) ?? null,
    }
}

/** Entrada de avance de una acción: `{accion, estado}` ya en castellano. */
export function comoAvance(
    entrada: NonConformityHistoryEntry,
): { accion: string; estado: string } | null {
    if (entrada.accion !== 'avance' || !entrada.cambios) {
        return null
    }

    return {
        accion: (entrada.cambios.accion as string) ?? '',
        estado: (entrada.cambios.estado as string) ?? '',
    }
}

/** El resultado de una verificación de eficacia, para esa entrada de bitácora. */
export function comoVerificacion(entrada: NonConformityHistoryEntry): string | null {
    if (entrada.accion !== 'verificacion' || !entrada.cambios) {
        return null
    }

    return (entrada.cambios.resultado as string) ?? null
}

/** El número que quedó asignado al aprobar, para mostrarlo en esa entrada. */
export function numeroAsignado(entrada: NonConformityHistoryEntry): string | null {
    if (entrada.accion !== 'aprobacion' || !entrada.cambios) {
        return null
    }

    return (entrada.cambios.numero as string) ?? null
}

export function nombreAutor(entrada: NonConformityHistoryEntry): string {
    if (!entrada.user) {
        return 'Sistema'
    }

    return [entrada.user.name, entrada.user.apellido].filter(Boolean).join(' ')
}
