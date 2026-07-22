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
        <span v-if="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ label }}
            <span v-if="required" class="text-red-500">*</span>
        </span>
        <div class="flex flex-wrap gap-x-6 gap-y-2 pt-1">
            <label
                v-for="op in items"
                :key="op.value"
                class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer"
            >
                <input
                    v-model="model"
                    type="radio"
                    :name="name"
                    :value="op.value"
                    class="text-indigo-600 focus:ring-indigo-500 dark:bg-slate-700 dark:border-slate-600"
                />
                {{ op.label }}
            </label>
        </div>
        <p v-if="error" class="text-red-500 dark:text-red-400 text-xs mt-1">{{ error }}</p>
        <p v-else-if="hint" class="text-xs text-gray-500 dark:text-slate-400 mt-1">{{ hint }}</p>
    </div>
</template>
