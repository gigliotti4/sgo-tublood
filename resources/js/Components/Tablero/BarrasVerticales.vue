<script setup lang="ts">
import { computed } from 'vue'
import type { ItemBarra } from '@/lib/tablero'

/**
 * Barras verticales del tablero, con la línea punteada del máximo y una segunda
 * leyenda debajo (el índice del sector, o la importancia del tipo de fallo).
 * Es el `vbars()` del prototipo del cliente: SVG a mano, sin librería.
 */
const props = defineProps<{ items: ItemBarra[]; clickable?: boolean }>()
const emit = defineEmits<{ pick: [index: number] }>()

const W = 720
const H = 230
const PAD = 34

const mx = computed(() => Math.max(1, ...props.items.map(e => Math.max(e.v, e.max ?? 0))))
const step = computed(() => (W - PAD - 10) / Math.max(1, props.items.length))
const bw = computed(() => Math.min(70, step.value * 0.58))

const guias = computed(() => [0, 1, 2, 3, 4].map(i => ({ y: H - ((H - 24) * i) / 4, t: Math.round((mx.value * i) / 4) })))

const barras = computed(() => props.items.map((e, i) => {
    const h = ((H - 24) * e.v) / mx.value
    const x = PAD + step.value * i + (step.value - bw.value) / 2

    // Dos renglones de hasta 16 caracteres para el nombre.
    let l1 = ''
    let l2 = ''
    String(e.k).split(' ').forEach(w => {
        if ((l1 + ' ' + w).length <= 16 && !l2) l1 = (l1 + ' ' + w).trim()
        else l2 = (l2 + ' ' + w).trim()
    })

    return {
        e, i, x, y: H - h, h: Math.max(h, 0), l1, l2: l2.slice(0, 20),
        ym: e.max ? H - ((H - 24) * e.max) / mx.value : null,
    }
}))
</script>

<template>
    <svg :viewBox="`0 0 ${W} ${H + 58}`" class="tb-svg" role="img">
        <g v-for="g in guias" :key="g.y">
            <line :x1="PAD" :x2="W - 6" :y1="g.y" :y2="g.y" stroke="currentColor" class="tb-grid" />
            <text :x="PAD - 6" :y="g.y + 4" font-size="11" text-anchor="end" fill="currentColor" class="tb-muted">{{ g.t }}</text>
        </g>
        <g
            v-for="b in barras"
            :key="b.e.k + b.i"
            :class="{ 'tb-barg': clickable }"
            @click="clickable && emit('pick', b.i)"
        >
            <title>{{ b.e.title ?? b.e.k }}</title>
            <rect :x="b.x" :y="b.y" :width="bw" :height="b.h" rx="5" :fill="b.e.color" />
            <line v-if="b.ym !== null" :x1="b.x - 6" :x2="b.x + bw + 6" :y1="b.ym" :y2="b.ym" stroke="#DC2626" stroke-dasharray="4 3" stroke-width="1.5" />
            <text :x="b.x + bw / 2" :y="b.y - 6" font-size="12" text-anchor="middle" font-weight="600" fill="currentColor">{{ b.e.v }}</text>
            <text :x="b.x + bw / 2" :y="H + 16" font-size="11" text-anchor="middle" fill="currentColor" class="tb-soft">{{ b.l1 }}</text>
            <text :x="b.x + bw / 2" :y="H + 30" font-size="11" text-anchor="middle" fill="currentColor" class="tb-soft">{{ b.l2 }}</text>
            <text
                v-if="b.e.label2"
                :x="b.x + bw / 2"
                :y="H + 46"
                :font-size="b.e.label2c ? 12 : 10"
                :font-weight="b.e.label2c ? 700 : 400"
                text-anchor="middle"
                :fill="b.e.label2c || 'currentColor'"
                :class="{ 'tb-muted': !b.e.label2c }"
            >{{ b.e.label2 }}</text>
        </g>
    </svg>
</template>
