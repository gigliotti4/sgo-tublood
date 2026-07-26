<script setup lang="ts">
import { computed, useId } from 'vue'

export interface OpcionRadio {
    value: string
    label: string
}

/**
 * Acepta opciones como strings sueltos (la taxonomía de incidencias las declara
 * así) o como pares {value, label} cuando lo que se guarda difiere de lo que se
 * muestra (ej. 'factura' → "Factura").
 */
const props = defineProps<{
    label?: string
    error?: string
    hint?: string
    opciones: (string | OpcionRadio)[]
    required?: boolean
    /** Ocupa las dos columnas de un FormSection. */
    full?: boolean
}>()

const model = defineModel<string | number | null>()

const name = useId()

const items = computed<OpcionRadio[]>(() =>
    props.opciones.map(o => (typeof o === 'string' ? { value: o, label: o } : o))
)
</script>

<template>
    <div :class="full ? 'sm:col-span-2' : ''">
        <span v-if="label" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ label }}
            <span v-if="required" class="text-error-500">*</span>
        </span>
        <div class="flex flex-wrap gap-x-6 gap-y-2 pt-1">
            <label
                v-for="op in items"
                :key="op.value"
                class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-400"
            >
                <input
                    v-model="model"
                    type="radio"
                    :name="name"
                    :value="op.value"
                    class="h-4 w-4 accent-brand-500 dark:accent-brand-400"
                />
                {{ op.label }}
            </label>
        </div>
        <p v-if="error" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ hint }}</p>
    </div>
</template>
