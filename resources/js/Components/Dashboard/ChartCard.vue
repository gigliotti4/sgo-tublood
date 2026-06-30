<script setup lang="ts">
import { ref, computed } from 'vue'

interface Dataset {
    label: string
    data: number[]
    color?: string
}

interface Props {
    title: string
    subtitle?: string
    labels: string[]
    datasets: Dataset[]
    periods?: string[]
    loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    periods: () => ['Semana', 'Mes', 'Año'],
    loading: false,
})

const selectedPeriod = ref(props.periods[0])

// SVG chart dimensions
const svgWidth  = 500
const svgHeight = 180
const paddingL  = 32
const paddingB  = 24
const paddingT  = 10
const paddingR  = 8

const chartW = computed(() => svgWidth - paddingL - paddingR)
const chartH = computed(() => svgHeight - paddingB - paddingT)

// Compute max value across all datasets
const maxVal = computed(() => {
    const all = props.datasets.flatMap(d => d.data)
    return Math.max(...all, 1)
})

// Y-axis gridlines (5 levels)
const yTicks = computed(() => {
    const step = maxVal.value / 4
    return [0, 1, 2, 3, 4].map(i => ({
        y: chartH.value - (i / 4) * chartH.value + paddingT,
        label: Math.round(step * i).toLocaleString(),
    }))
})

// Bar positions
const barWidth = computed(() => {
    const total = props.labels.length
    const groupW = chartW.value / total
    return Math.min(groupW * 0.5, 24)
})

const bars = computed(() =>
    props.datasets[0]?.data.map((val, i) => {
        const x = paddingL + (i / props.labels.length) * chartW.value + (chartW.value / props.labels.length) / 2
        const barH = (val / maxVal.value) * chartH.value
        return {
            x: x - barWidth.value / 2,
            y: paddingT + chartH.value - barH,
            w: barWidth.value,
            h: barH,
            val,
            label: props.labels[i],
        }
    }) ?? []
)

// Tooltip
const tooltip = ref<{ visible: boolean; x: number; y: number; val: number; label: string }>({
    visible: false, x: 0, y: 0, val: 0, label: ''
})

const showTooltip = (bar: typeof bars.value[0]) => {
    tooltip.value = { visible: true, x: bar.x + bar.w / 2, y: bar.y - 8, val: bar.val, label: bar.label }
}
const hideTooltip = () => { tooltip.value.visible = false }
</script>

<template>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <!-- Header -->
        <div class="flex items-start justify-between px-5 py-4 border-b border-slate-100">
            <div>
                <h3 class="text-sm font-semibold text-slate-800">{{ title }}</h3>
                <p v-if="subtitle" class="text-xs text-slate-400 mt-0.5">{{ subtitle }}</p>
            </div>
            <!-- Period tabs -->
            <div class="flex gap-1 bg-slate-100 rounded-lg p-1">
                <button
                    v-for="period in periods"
                    :key="period"
                    @click="selectedPeriod = period"
                    class="text-xs px-2.5 py-1 rounded-md font-medium transition-all"
                    :class="selectedPeriod === period
                        ? 'bg-white text-slate-800 shadow-sm'
                        : 'text-slate-500 hover:text-slate-700'"
                >
                    {{ period }}
                </button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="p-5 animate-pulse">
            <div class="flex items-end gap-2 h-44">
                <div v-for="i in 7" :key="i" class="flex-1 bg-slate-200 rounded-t" :style="{ height: (30 + Math.random() * 70) + '%' }" />
            </div>
        </div>

        <!-- Chart -->
        <div v-else class="p-4 overflow-x-auto">
            <svg
                :viewBox="`0 0 ${svgWidth} ${svgHeight}`"
                class="w-full"
                :style="{ minWidth: '280px', height: '180px' }"
                @mouseleave="hideTooltip"
            >
                <!-- Grid lines -->
                <g>
                    <line
                        v-for="tick in yTicks"
                        :key="tick.label"
                        :x1="paddingL"
                        :y1="tick.y"
                        :x2="svgWidth - paddingR"
                        :y2="tick.y"
                        stroke="#e2e8f0"
                        stroke-width="1"
                        stroke-dasharray="4,3"
                    />
                    <!-- Y labels -->
                    <text
                        v-for="tick in yTicks"
                        :key="'l' + tick.label"
                        :x="paddingL - 4"
                        :y="tick.y + 4"
                        text-anchor="end"
                        font-size="9"
                        fill="#94a3b8"
                    >{{ tick.label }}</text>
                </g>

                <!-- Bars -->
                <g>
                    <g
                        v-for="bar in bars"
                        :key="bar.label"
                        class="cursor-pointer"
                        @mouseenter="showTooltip(bar)"
                        @mouseleave="hideTooltip"
                    >
                        <!-- Background bar (hover) -->
                        <rect
                            :x="bar.x - 6"
                            :y="paddingT"
                            :width="bar.w + 12"
                            :height="chartH"
                            fill="transparent"
                            class="hover:fill-slate-50"
                        />
                        <!-- Actual bar -->
                        <rect
                            :x="bar.x"
                            :y="bar.y"
                            :width="bar.w"
                            :height="bar.h"
                            rx="4"
                            fill="#2a3182"
                            opacity="0.85"
                            class="hover:opacity-100 transition-opacity"
                        />
                        <!-- X label -->
                        <text
                            :x="bar.x + bar.w / 2"
                            :y="svgHeight - 6"
                            text-anchor="middle"
                            font-size="9"
                            fill="#94a3b8"
                        >{{ bar.label }}</text>
                    </g>
                </g>

                <!-- Tooltip -->
                <g v-if="tooltip.visible">
                    <rect
                        :x="tooltip.x - 28"
                        :y="tooltip.y - 22"
                        width="56"
                        height="20"
                        rx="4"
                        fill="#1e293b"
                    />
                    <text
                        :x="tooltip.x"
                        :y="tooltip.y - 8"
                        text-anchor="middle"
                        font-size="10"
                        font-weight="600"
                        fill="white"
                    >{{ tooltip.val.toLocaleString() }}</text>
                    <!-- Arrow -->
                    <polygon
                        :points="`${tooltip.x - 4},${tooltip.y - 2} ${tooltip.x + 4},${tooltip.y - 2} ${tooltip.x},${tooltip.y + 4}`"
                        fill="#1e293b"
                    />
                </g>
            </svg>
        </div>

        <!-- Legend -->
        <div class="px-5 pb-4 flex items-center gap-4">
            <div
                v-for="ds in datasets"
                :key="ds.label"
                class="flex items-center gap-1.5"
            >
                <span class="w-2.5 h-2.5 rounded-sm bg-[#2a3182]" />
                <span class="text-xs text-slate-500">{{ ds.label }}</span>
            </div>
        </div>
    </div>
</template>
