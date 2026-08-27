<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { mensajesDeError, type ErroresDeFormulario } from '@/lib/errores'

/**
 * La caja que le dice a alguien por qué no se guardó lo que estaba cargando.
 *
 * Los formularios de observación son largos: si el campo que falta quedó fuera
 * de la pantalla, el re-render de Inertia no cambia nada visible y lo que se
 * percibe es que el botón "no hace nada". Esta caja aparece arriba con todos
 * los mensajes y **hace scroll hasta sí misma**, que es lo que convierte un
 * "no pasó nada" en "me falta el lote del producto".
 *
 * Scrollea a la caja y no al primer campo con error a propósito: los
 * componentes de formulario del panel no ponen `id` en el `<input>`, así que no
 * hay a dónde saltar sin agregárselo a mano a cada campo de cada pantalla. La
 * caja está siempre arriba del formulario y sirve igual.
 *
 * ⚠️ Lo usan el panel y el portal público, que no comparten paleta (el portal
 * es siempre claro, sin `dark:`). Las clases de acá tienen que funcionar en los
 * dos: van los tonos de error con su variante oscura, que en el portal
 * simplemente no se activa.
 */
const props = withDefaults(defineProps<{
    errors: ErroresDeFormulario
    titulo?: string
}>(), {
    titulo: 'No pudimos guardar el formulario',
})

const caja = ref<HTMLElement | null>(null)

const mensajes = computed(() => mensajesDeError(props.errors))

// Scrollea en **cada** envío fallido, no solo cuando la lista de errores
// cambia de contenido: si alguien reintenta sin corregir nada y vuelve a
// fallar por lo mismo, ya scrolleó hasta el botón y hay que traerlo de vuelta.
// Inertia reemplaza el objeto de errores en cada respuesta, así que el watch
// corre una vez por envío y no mientras se tipea.
//
// `nextTick` porque en el momento del watch la caja todavía no está en el DOM:
// aparece con el mismo cambio de `errors` que lo dispara.
watch(mensajes, async (nuevos) => {
    if (!nuevos.length) return

    await nextTick()
    caja.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}, { immediate: true })
</script>

<template>
    <div
        v-if="mensajes.length"
        ref="caja"
        role="alert"
        aria-live="assertive"
        class="rounded-lg border border-error-200 bg-error-50 px-4 py-3.5 dark:border-error-500/30 dark:bg-error-500/10"
    >
        <div class="flex items-start gap-2.5">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-error-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-error-700 dark:text-error-400">{{ titulo }}</p>

                <!--
                    Un solo error no necesita viñeta: la lista de un elemento se
                    lee como si faltara algo más.
                -->
                <p v-if="mensajes.length === 1" class="mt-1 text-sm text-error-600 dark:text-error-400/90">
                    {{ mensajes[0] }}
                </p>
                <ul v-else class="mt-1.5 list-disc space-y-1 pl-4 text-sm text-error-600 dark:text-error-400/90">
                    <li v-for="mensaje in mensajes" :key="mensaje">{{ mensaje }}</li>
                </ul>
            </div>
        </div>
    </div>
</template>
