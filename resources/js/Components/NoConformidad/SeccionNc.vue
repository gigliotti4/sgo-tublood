<script setup lang="ts">
import { computed } from 'vue'

/**
 * Una sección numerada del Informe de Desvío.
 *
 * Existe para que la ficha se lea como el papel: las siete secciones siempre
 * presentes, en orden, con su número. Las que la etapa todavía no habilitó se
 * ven en gris **pero se ven** — esconderlas oculta el recorrido, y alguien que
 * abre una NC recién aprobada no tendría forma de saber que después viene un
 * plan de acción y una verificación.
 *
 * El contenido se dibuja igual cuando está bloqueada: se muestra vacío y en
 * solo lectura, que es exactamente cómo se ve el formulario en papel antes de
 * llenarlo.
 */
const props = defineProps<{
    /**
     * El número que lleva en el formulario. Es `string | number` porque las
     * secciones 6 y 7 se cargan juntas en un solo bloque —se completan en el
     * mismo momento— y va rotulado "6 y 7".
     */
    numero: string | number
    titulo: string
    /** `false` mientras la etapa no llegó. Dibuja el candado y el gris. */
    habilitada?: boolean
    /** Qué falta para habilitarla. Solo se muestra si está bloqueada. */
    motivoBloqueo?: string
}>()

const bloqueada = computed(() => props.habilitada === false)
</script>

<template>
    <section
        class="rounded-2xl border bg-white p-6 transition dark:bg-white/[0.03]"
        :class="bloqueada
            ? 'border-gray-200 opacity-60 dark:border-gray-800'
            : 'border-gray-200 dark:border-gray-800'"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                <span class="font-mono">{{ numero }})</span>
                {{ titulo }}
            </h2>
            <span v-if="bloqueada" class="text-theme-xs text-gray-400">
                🔒 {{ motivoBloqueo ?? 'Todavía no corresponde' }}
            </span>
        </div>

        <div class="mt-4">
            <slot />
        </div>
    </section>
</template>
