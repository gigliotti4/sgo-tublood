<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { route } from 'ziggy-js'
import Input from '@/Components/Input.vue'

/**
 * Campo "Proveedor" de los fallos externos (E.2 y E.3): texto con sugerencias
 * del padrón de proveedores de RP.
 *
 * Es texto libre con ayuda, no un selector cerrado: un fallo de un proveedor que
 * todavía no está en el padrón (o un transporte) no puede quedar sin cargarse.
 * Lo que se guarda es el nombre tal cual se eligió o se escribió, y de ahí sale
 * el ranking "Proveedores y transportes" del tablero.
 *
 * Va a `observaciones.proveedores` y no a `proveedores.buscar`: aquél pide
 * `proveedores.view`, y cargar una observación no lo exige.
 */
defineProps<{
    label: string
    required?: boolean
    error?: string
}>()

const model = defineModel<string | number | null>()

const sugerencias = ref<string[]>([])
const listaId = `proveedores-${Math.random().toString(36).slice(2, 8)}`

let debounce: ReturnType<typeof setTimeout>
let peticion = 0

watch(model, (valor) => {
    clearTimeout(debounce)

    const termino = String(valor ?? '').trim()

    if (termino.length < 2) {
        sugerencias.value = []

        return
    }

    debounce = setTimeout(async () => {
        const propia = ++peticion

        try {
            const r = await fetch(`${route('observaciones.proveedores')}?q=${encodeURIComponent(termino)}`, {
                headers: { Accept: 'application/json' },
            })

            // Llegó tarde: ya hay una búsqueda más nueva en curso.
            if (propia !== peticion || !r.ok) return

            sugerencias.value = ((await r.json()) as { razon_social: string }[]).map(p => p.razon_social)
        } catch {
            // Sin respuesta, sigue siendo texto libre: no bloquea el guardado.
        }
    }, 250)
})

onBeforeUnmount(() => clearTimeout(debounce))
</script>

<template>
    <div>
        <Input
            v-model="model"
            :list="listaId"
            :label="label"
            :required="required"
            :error="error"
            hint="Elegí uno de la lista; si no figura, escribilo."
            autocomplete="off"
        />
        <datalist :id="listaId">
            <option v-for="s in sugerencias" :key="s" :value="s" />
        </datalist>
    </div>
</template>
