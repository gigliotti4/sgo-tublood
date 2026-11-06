<script setup lang="ts">
import { computed } from 'vue'
import type { Serie } from '@/lib/tablero'

/**
 * Líneas mes a mes. El último tramo va punteado cuando el mes en curso está
 * incompleto. Es el `line()` del prototipo del cliente.
 */
const props = defineProps<{ series: Serie[]; meses: string[]; etiquetas: string[]; parcial?: string }>()

const W = 720
const H = 220
const PL = 34
const PR = 60

const mx = computed(() => Math.max(1, ...props.series.flatMap(s => s.vals)))
const xs = computed(() => props.meses.map((_, i) => PL + ((W - PL - PR) * i) / Math.max(1, props.meses.length - 1)))
const guias = computed(() => [0, 1, 2, 3, 4].map(i => ({ y: H - ((H - 16) * i) / 4, t: Math.round((mx.value * i) / 4) })))

const lineas = computed(() => props.series.map(s => {
    const pts = s.vals.map((v, i) => [xs.value[i], H - ((H - 16) * v) / mx.value])
    const parcial = props.parcial !== undefined && props.meses[props.meses.length - 1] === props.parcial && pts.length > 1

    return {
        s,
        pts,
        solida: (parcial ? pts.slice(0, -1) : pts).map(p => p.join(',')).join(' '),
        punteada: parcial ? pts.slice(-2).map(p => p.join(',')).join(' ') : '',
    }
}))
</script>

<template>
    <div>
        <svg :viewBox="`0 0 ${W} ${H + 30}`" class="tb-svg" role="img">
            <g v-for="g in guias" :key="g.y">
                <line :x1="PL" :x2="W - PR" :y1="g.y" :y2="g.y" stroke="currentColor" class="tb-grid" />
                <text :x="PL - 6" :y="g.y + 4" font-size="11" text-anchor="end" fill="currentColor" class="tb-muted">{{ g.t }}</text>
            </g>
            <text v-for="(m, i) in meses" :key="m" :x="xs[i]" :y="H + 20" font-size="12" text-anchor="middle" fill="currentColor" class="tb-muted">
                {{ etiquetas[i] }}{{ m === parcial ? ' (parcial)' : '' }}
            </text>
            <g v-for="l in lineas" :key="l.s.name">
                <polyline fill="none" :stroke="l.s.color" stroke-width="2.5" :points="l.solida" />
                <polyline v-if="l.punteada" fill="none" :stroke="l.s.color" stroke-width="2" stroke-dasharray="5 4" opacity=".7" :points="l.punteada" />
                <g v-for="(p, i) in l.pts" :key="i">
                    <circle :cx="p[0]" :cy="p[1]" r="4" class="tb-dot" :stroke="l.s.color" stroke-width="2">
                        <title>{{ l.s.name }} · {{ etiquetas[i] }}: {{ l.s.vals[i] }}</title>
                    </circle>
                    <text :x="p[0] + 7" :y="p[1] - 7" font-size="11" :fill="l.s.color" font-weight="600">{{ l.s.vals[i] }}</text>
                </g>
            </g>
        </svg>
        <div class="tb-legend">
            <span v-for="l in lineas" :key="l.s.name"><i :style="{ background: l.s.color }" />{{ l.s.name }}</span>
        </div>
    </div>
</template>
