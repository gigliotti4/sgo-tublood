<script setup lang="ts">
import { computed } from 'vue'

/** Torta (dona) con leyenda clickeable. Es el `pie()` del prototipo del cliente. */
const props = defineProps<{
    entries: [string, number][]
    colors: (k: string, i: number) => string
    center?: string
    clickable?: boolean
}>()
const emit = defineEmits<{ pick: [key: string] }>()

const R = 90
const r = 52
const cx = 110
const cy = 110

const tot = computed(() => props.entries.reduce((a, e) => a + e[1], 0))
const pct = (a: number) => (tot.value ? `${Math.round((a / tot.value) * 100)}%` : '—')

const trozos = computed(() => {
    let a0 = -Math.PI / 2

    return props.entries.map(([k, v], i) => {
        const a1 = a0 + (v / tot.value) * Math.PI * 2
        const large = a1 - a0 > Math.PI ? 1 : 0
        const p = (ang: number, rad: number) => [cx + rad * Math.cos(ang), cy + rad * Math.sin(ang)]
        const [x0, y0] = p(a0, R)
        const [x1, y1] = p(a1, R)
        const [x2, y2] = p(a1, r)
        const [x3, y3] = p(a0, r)
        // Una torta de un solo trozo es un anillo completo.
        const d = v === tot.value
            ? `M${cx} ${cy - R}A${R} ${R} 0 1 1 ${cx - 0.01} ${cy - R}L${cx - 0.01} ${cy - r}A${r} ${r} 0 1 0 ${cx} ${cy - r}Z`
            : `M${x0} ${y0}A${R} ${R} 0 ${large} 1 ${x1} ${y1}L${x2} ${y2}A${r} ${r} 0 ${large} 0 ${x3} ${y3}Z`

        a0 = a1

        return { k, v, i, d }
    })
})
</script>

<template>
    <p v-if="!tot" class="tb-note">Sin datos en el período.</p>
    <div v-else>
        <svg viewBox="0 0 220 220" class="tb-svg" style="max-width: 240px; margin: auto" role="img">
            <path
                v-for="t in trozos"
                :key="t.k"
                :d="t.d"
                :fill="colors(t.k, t.i)"
                :class="{ 'tb-slice': clickable }"
                @click="clickable && emit('pick', t.k)"
            >
                <title>{{ t.k }}: {{ t.v }} ({{ pct(t.v) }})</title>
            </path>
            <text x="110" y="106" text-anchor="middle" font-size="11" fill="currentColor" class="tb-muted">{{ center ?? 'Total' }}</text>
            <text x="110" y="128" text-anchor="middle" font-size="22" font-weight="700" fill="currentColor">{{ tot }}</text>
        </svg>
        <div class="tb-legend">
            <span v-for="t in trozos" :key="t.k" :style="{ cursor: clickable ? 'pointer' : 'default' }" @click="clickable && emit('pick', t.k)">
                <i :style="{ background: colors(t.k, t.i) }" />{{ t.k }} · {{ t.v }} ({{ pct(t.v) }})
            </span>
        </div>
    </div>
</template>
