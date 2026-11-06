<script setup lang="ts">
import { computed } from 'vue'

/**
 * Barras horizontales con nombre y cantidad. Si se pasa `seleccionado`, tocar una
 * fila la elige (y volver a tocarla la suelta): es el filtro por clic del
 * tablero. Es el `hbars()`/`hbarsSel()` del prototipo del cliente.
 */
const props = defineProps<{
    entries: [string, number][]
    color?: string | ((k: string) => string)
    clickable?: boolean
    seleccionado?: string | null
}>()
const emit = defineEmits<{ pick: [key: string | null] }>()

const mx = computed(() => Math.max(1, ...props.entries.map(e => e[1])))
const colorDe = (k: string) => (typeof props.color === 'function' ? props.color(k) : props.color)
</script>

<template>
    <p v-if="!entries.length" class="tb-note">Sin datos.</p>
    <div
        v-for="[k, v] in entries"
        :key="k"
        class="tb-hbar"
        :class="{ 'tb-click': clickable, 'tb-on': seleccionado === k }"
        @click="clickable && emit('pick', seleccionado === k ? null : k)"
    >
        <span>{{ k }}</span>
        <span class="tb-track"><i :style="{ width: `${(v / mx) * 100}%`, background: colorDe(k) }" /></span>
        <b>{{ v }}</b>
    </div>
</template>
