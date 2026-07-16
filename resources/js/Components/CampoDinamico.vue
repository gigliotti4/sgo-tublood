<script setup lang="ts">
export interface CampoDef {
    id: string
    label: string
    tipo: 'text' | 'textarea' | 'number' | 'date' | 'select' | 'radio'
    required?: boolean
    opciones?: string[]
}

defineProps<{
    campo: CampoDef
    error?: string
}>()

const model = defineModel<string | number | null>()

const inputClass = (hasError?: string) =>
    'w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent ' +
    (hasError ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200')
</script>

<template>
    <div class="space-y-1.5" :class="{ 'sm:col-span-2': campo.tipo === 'textarea' }">
        <label :for="`campo-${campo.id}`" class="block text-sm font-medium text-slate-700">
            {{ campo.label }} <span v-if="campo.required" class="text-red-500">*</span>
        </label>

        <textarea
            v-if="campo.tipo === 'textarea'"
            :id="`campo-${campo.id}`"
            v-model="model"
            rows="3"
            :class="inputClass(error)"
        />

        <select
            v-else-if="campo.tipo === 'select'"
            :id="`campo-${campo.id}`"
            v-model="model"
            :class="inputClass(error)"
        >
            <option value="" disabled>— Seleccionar —</option>
            <option v-for="op in campo.opciones" :key="op" :value="op">{{ op }}</option>
        </select>

        <div v-else-if="campo.tipo === 'radio'" class="flex flex-wrap gap-6 pt-1">
            <label v-for="op in campo.opciones" :key="op" class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="model" type="radio" :value="op" class="text-indigo-600 focus:ring-indigo-500" />
                {{ op }}
            </label>
        </div>

        <input
            v-else
            :id="`campo-${campo.id}`"
            v-model="model"
            :type="campo.tipo === 'number' ? 'number' : campo.tipo === 'date' ? 'date' : 'text'"
            :class="inputClass(error)"
        />

        <p v-if="error" class="text-red-500 text-xs">{{ error }}</p>
    </div>
</template>
