<script setup lang="ts">
import { computed } from 'vue'
import Badge from '@/Components/Badge.vue'
import type { EstadoDocumentacion } from '@/types'

/**
 * El "Control automático" de la planilla: qué falta, qué está vencido y cuándo
 * vence lo próximo. Todo se calcula en el backend
 * (`ClasificacionDocumental::estadoDocumentacion()`), acá solo se muestra.
 *
 * Lo comparten la ficha de cliente y la de proveedor: es la misma información
 * y el mismo cálculo, solo cambia cómo se llama el registro.
 */
const props = defineProps<{
    estado: EstadoDocumentacion
    /** Slug del tipo. `null` = sin clasificar, y entonces no hay nada que exigir. */
    tipo: string | null
    /** Vencimiento del registro, ya derivado. */
    fechaVencimiento: string | null
    /** Nombre del documento del que sale ese vencimiento, si el tipo tiene uno. */
    etiquetaDeterminante?: string | null
    /** "cliente" / "proveedor", para los textos. */
    entidad?: string
}>()

const formatFecha = (d: string | null) => {
    if (!d) return '—'
    return new Date(`${d.slice(0, 10)}T00:00:00`).toLocaleDateString('es-AR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
    })
}

/** Ámbar a 30 días o menos, rojo si ya venció — el mismo umbral que la campana. */
const varianteVencimiento = computed(() => {
    const dias = props.estado.dias_para_vencer
    if (dias === null) return 'slate'
    if (dias < 0) return 'red'
    return dias <= 30 ? 'amber' : 'emerald'
})
</script>

<template>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-4 flex items-center justify-between gap-3">
            <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">Control automático</p>
            <Badge v-if="!tipo" variant="slate">Sin tipo de {{ entidad ?? 'cliente' }}</Badge>
            <Badge v-else-if="estado.completa" variant="emerald">Documentación completa</Badge>
            <Badge v-else variant="red">Documentación incompleta</Badge>
        </div>

        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
            <dt class="text-gray-400">Documentación faltante</dt>
            <dd class="text-gray-800 dark:text-white/90">
                {{ estado.faltantes.length ? estado.faltantes.join(', ') : '—' }}
            </dd>
            <dt class="text-gray-400">Documentación vencida</dt>
            <dd class="text-gray-800 dark:text-white/90">
                <span v-if="!estado.vencidos.length">—</span>
                <span v-for="v in estado.vencidos" :key="v.documento" class="block">
                    {{ v.label }} <span class="text-error-500">({{ formatFecha(v.fecha_vencimiento) }})</span>
                </span>
            </dd>
            <dt class="text-gray-400">Próximo vencimiento</dt>
            <dd class="text-gray-800 dark:text-white/90">{{ formatFecha(estado.proximo_vencimiento) }}</dd>
            <dt class="text-gray-400">Días para vencer</dt>
            <dd>
                <Badge v-if="estado.dias_para_vencer !== null" :variant="varianteVencimiento">
                    {{ estado.dias_para_vencer < 0
                        ? `Vencido hace ${-estado.dias_para_vencer} días`
                        : `${estado.dias_para_vencer} días` }}
                </Badge>
                <span v-else class="text-gray-400">—</span>
            </dd>
            <dt class="text-gray-400">Vencimiento del {{ entidad ?? 'cliente' }}</dt>
            <dd class="text-gray-800 dark:text-white/90">
                {{ formatFecha(fechaVencimiento) }}
                <span v-if="etiquetaDeterminante" class="block text-theme-xs text-gray-400">
                    Sale de: {{ etiquetaDeterminante }}
                </span>
                <span v-else-if="tipo" class="block text-theme-xs text-gray-400">
                    Este tipo no tiene documentos que venzan.
                </span>
            </dd>
        </dl>
    </div>
</template>
