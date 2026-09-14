import type { Observacion } from '@/types'

/**
 * Etiquetas y colores del estado de una observación.
 *
 * Compartido por Admin/Observaciones/Index.vue, Admin/Observaciones/Show.vue
 * y Layouts/AppLayout.vue — antes estaban copiados en los tres, y es el mismo
 * criterio que resources/js/lib/bitacora.ts y resources/js/lib/productos.ts:
 * duplicarlo garantizaba que se desincronizaran.
 *
 * El backend replica la misma regla en `Observacion::etiquetaEstado()` (PDF y
 * Excel), que no puede reusar este archivo — mantenerlos sincronizados.
 */

export type BadgeVariant = 'amber' | 'blue' | 'indigo' | 'purple' | 'emerald' | 'slate' | 'red'

export const estadoLabels: Record<string, string> = {
    pendiente_clasificacion: 'Pendiente de clasificación',
    clasificada: 'Clasificada',
    en_proceso: 'En proceso',
    derivada: 'Derivada',
    cerrada: 'Cerrada',
    cancelada: 'Cancelada',
}

export const estadoVariant: Record<string, BadgeVariant> = {
    pendiente_clasificacion: 'amber',
    clasificada: 'blue',
    en_proceso: 'indigo',
    derivada: 'purple',
    cerrada: 'emerald',
    cancelada: 'red',
}

/** Color por prioridad, para cuando el badge de estado muestra la prioridad en vez de "Clasificada". */
const prioridadVariant: Record<string, BadgeVariant> = {
    critica: 'red',
    alta: 'amber',
    media: 'blue',
    baja: 'slate',
}

/**
 * Lo que va en el badge de estado. "Clasificada" no dice nada de la urgencia
 * del caso: en ese estado se muestra la prioridad asignada (con su propio
 * color), y el resto de los estados usa `estadoLabels`/`estadoVariant` tal
 * cual. Sin prioridad cargada (dato viejo) cae a "Clasificada".
 */
export const badgeEstado = (
    // `estado` opcional/nulo: también lo usa el modal de avisos de AppLayout.vue
    // con `ObservacionSinClasificar`, donde no siempre viaja.
    o: { estado?: string | null; prioridad: Observacion['prioridad'] },
    prioridades: Record<string, string>,
): { label: string; variant: BadgeVariant } => {
    if (o.estado === 'clasificada' && o.prioridad) {
        return {
            label: prioridades[o.prioridad] ?? o.prioridad,
            variant: prioridadVariant[o.prioridad] ?? 'blue',
        }
    }

    return {
        label: estadoLabels[o.estado ?? ''] ?? o.estado ?? '',
        variant: estadoVariant[o.estado ?? ''] ?? 'slate',
    }
}
