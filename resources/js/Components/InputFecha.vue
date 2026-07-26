<script setup lang="ts">
import { ref, watch } from 'vue'
import Input from '@/Components/Input.vue'

/**
 * Campo de fecha que se tipea como dd/mm/aaaa (texto, no el date picker del
 * navegador). Mientras se escribe se van intercalando las barras y, cuando la
 * fecha queda completa, el v-model recibe aaaa-mm-dd — lo que valida la regla
 * `date` del backend. Si lo tipeado no forma una fecha completa, el v-model
 * lleva el texto tal cual y el backend lo rechaza con su mensaje de validación.
 * Un valor entrante en aaaa-mm-dd se muestra como dd/mm/aaaa.
 */
defineProps<{
    label?: string
    error?: string
    /** Texto de ayuda debajo del campo. Lo tapa el error cuando hay uno. */
    hint?: string
    required?: boolean
    /** Ocupa las dos columnas de un FormSection. */
    full?: boolean
}>()

const model = defineModel<string | number | null>()

const aISO = (valor: string) => {
    const m = valor.match(/^(\d{2})\/(\d{2})\/(\d{4})$/)
    return m ? `${m[3]}-${m[2]}-${m[1]}` : valor
}

const aDisplay = (valor: string | number | null | undefined) => {
    if (valor === null || valor === undefined || valor === '') return ''
    const m = String(valor).match(/^(\d{4})-(\d{2})-(\d{2})/)
    return m ? `${m[3]}/${m[2]}/${m[1]}` : String(valor)
}

const display = ref(aDisplay(model.value))

// Un cambio externo del modelo (reset del formulario, carga inicial) refresca
// lo mostrado; los cambios que vienen del propio tipeo no, para no pisar el
// parcial que el usuario está escribiendo.
watch(model, valor => {
    if (aISO(display.value) !== (valor ?? '')) display.value = aDisplay(valor)
})

const onInput = (e: Event) => {
    const input = e.target as HTMLInputElement
    const digitos = input.value.replace(/\D/g, '').slice(0, 8)

    let out = digitos
    if (digitos.length > 4) out = `${digitos.slice(0, 2)}/${digitos.slice(2, 4)}/${digitos.slice(4)}`
    else if (digitos.length > 2) out = `${digitos.slice(0, 2)}/${digitos.slice(2)}`

    display.value = out
    input.value = out
    model.value = aISO(out)
}
</script>

<template>
    <Input
        :model-value="display"
        type="text"
        inputmode="numeric"
        placeholder="dd/mm/aaaa"
        maxlength="10"
        :label="label"
        :error="error"
        :hint="hint"
        :required="required"
        :full="full"
        @input="onInput"
    />
</template>
