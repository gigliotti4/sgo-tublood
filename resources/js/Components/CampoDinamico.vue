<script setup lang="ts">
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'

export interface CampoDef {
    id: string
    label: string
    tipo: 'text' | 'textarea' | 'number' | 'date' | 'time' | 'select' | 'radio'
    required?: boolean
    opciones?: string[]
}

/**
 * Renderiza un campo de "Datos específicos" desde su definición en
 * config/incidencias.php. No trae estilo propio: despacha a los componentes
 * compartidos, así el modo oscuro y cualquier retoque visual salen de un
 * solo lugar.
 */
defineProps<{
    campo: CampoDef
    error?: string
}>()

const model = defineModel<string | number | null>()
</script>

<template>
    <Textarea
        v-if="campo.tipo === 'textarea'"
        v-model="model"
        full
        :label="campo.label"
        :required="campo.required"
        :error="error"
    />

    <Select
        v-else-if="campo.tipo === 'select'"
        v-model="model"
        :label="campo.label"
        :required="campo.required"
        :error="error"
    >
        <option value="" disabled>— Seleccionar —</option>
        <option v-for="op in campo.opciones" :key="op" :value="op">{{ op }}</option>
    </Select>

    <RadioGroup
        v-else-if="campo.tipo === 'radio'"
        v-model="model"
        :label="campo.label"
        :opciones="campo.opciones ?? []"
        :required="campo.required"
        :error="error"
    />

    <InputFecha
        v-else-if="campo.tipo === 'date'"
        v-model="model"
        :label="campo.label"
        :required="campo.required"
        :error="error"
    />

    <!--
        `time` sí usa el widget nativo, a diferencia de las fechas (que van con
        el texto enmascarado de InputFecha): no hay un equivalente propio para
        horas, y el nativo ya devuelve HH:MM, que es lo que valida el backend.
    -->
    <Input
        v-else
        v-model="model"
        :type="campo.tipo === 'number' || campo.tipo === 'time' ? campo.tipo : 'text'"
        :label="campo.label"
        :required="campo.required"
        :error="error"
    />
</template>
