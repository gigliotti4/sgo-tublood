<script lang="ts">
export interface CampoDef {
    id: string
    label: string
    /**
     * `texto_sugerido`: texto libre con la lista de `opciones` como sugerencias
     * ("lista + Otro"). `proveedor`: texto libre con sugerencias del padrón de RP.
     */
    tipo: 'text' | 'textarea' | 'number' | 'date' | 'time' | 'select' | 'radio' | 'texto_sugerido' | 'proveedor'
    required?: boolean
    opciones?: string[]
    /**
     * Aparece solo si otro campo tiene ese valor (ej. el fabricante de origen
     * solo si el producto es importado). Lo resuelve cada formulario con
     * `campoVisible()`; el backend descarta lo que quedó de una opción anterior.
     */
    visible_si?: { campo: string; valor: string }
}

/** ¿Corresponde mostrar este campo, dados los datos cargados hasta ahora? */
export const campoVisible = (campo: CampoDef, datos: Record<string, string | number | null | undefined>): boolean =>
    !campo.visible_si || datos[campo.visible_si.campo] === campo.visible_si.valor
</script>

<script setup lang="ts">
import CampoProveedor from '@/Components/CampoProveedor.vue'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'

/**
 * Renderiza un campo de "Datos específicos" desde su definición en
 * config/incidencias.php. No trae estilo propio: despacha a los componentes
 * compartidos, así el modo oscuro y cualquier retoque visual salen de un
 * solo lugar.
 */
const props = defineProps<{
    campo: CampoDef
    error?: string
}>()

const model = defineModel<string | number | null>()

// Id propio de la lista de sugerencias: varios campos pueden convivir en la pantalla.
const listaId = `sugerencias-${props.campo.id}-${Math.random().toString(36).slice(2, 8)}`
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

    <CampoProveedor
        v-else-if="campo.tipo === 'proveedor'"
        v-model="model"
        :label="campo.label"
        :required="campo.required"
        :error="error"
    />

    <div v-else-if="campo.tipo === 'texto_sugerido'">
        <Input
            v-model="model"
            :list="listaId"
            :label="campo.label"
            :required="campo.required"
            :error="error"
            hint="Elegí uno de la lista o escribí otro."
            autocomplete="off"
        />
        <datalist :id="listaId">
            <option v-for="op in campo.opciones" :key="op" :value="op" />
        </datalist>
    </div>

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
