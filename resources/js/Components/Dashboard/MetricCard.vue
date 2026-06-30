<script setup lang="ts">
import { computed } from 'vue'

interface Props {
    title: string
    value: string | number
    subtitle?: string
    change?: number       // % positivo = sube, negativo = baja
    icon: string
    color?: 'primary' | 'success' | 'warning' | 'danger' | 'info'
    sparkline?: number[]
    loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    color: 'primary',
    loading: false,
})

const colorMap = {
    primary: { bg: 'bg-[#2a3182]/10', icon: 'text-[#2a3182]', border: 'border-[#2a3182]/20' },
    success: { bg: 'bg-emerald-50',   icon: 'text-emerald-600', border: 'border-emerald-100' },
    warning: { bg: 'bg-amber-50',     icon: 'text-amber-600',   border: 'border-amber-100' },
    danger:  { bg: 'bg-red-50',       icon: 'text-red-500',     border: 'border-red-100' },
    info:    { bg: 'bg-sky-50',       icon: 'text-sky-600',     border: 'border-sky-100' },
}

const colors = computed(() => colorMap[props.color])

const changeColor = computed(() =>
    props.change === undefined ? '' :
    props.change >= 0 ? 'text-emerald-600' : 'text-red-500'
)

// Sparkline SVG points
const sparklinePoints = computed(() => {
    if (!props.sparkline || props.sparkline.length < 2) return ''
    const data = props.sparkline
    const min = Math.min(...data)
    const max = Math.max(...data)
    const range = max - min || 1
    const w = 80, h = 28
    return data.map((v, i) => {
        const x = (i / (data.length - 1)) * w
        const y = h - ((v - min) / range) * h
        return `${x},${y}`
    }).join(' ')
})

// Heroicons paths
const icons: Record<string, string> = {
    users: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    shield: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    chart: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
    key: 'M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z',
    check: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
    lightning: 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z',
    package: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
}
</script>

<template>
    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm hover:shadow-md transition-shadow duration-200">

        <!-- Loading skeleton -->
        <div v-if="loading" class="animate-pulse space-y-3">
            <div class="flex justify-between items-start">
                <div class="space-y-2">
                    <div class="h-3 w-20 bg-slate-200 rounded" />
                    <div class="h-7 w-16 bg-slate-200 rounded" />
                </div>
                <div class="w-10 h-10 bg-slate-200 rounded-lg" />
            </div>
            <div class="h-3 w-24 bg-slate-200 rounded" />
        </div>

        <!-- Content -->
        <template v-else>
            <div class="flex items-start justify-between">
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ title }}</p>
                    <p class="text-2xl font-bold text-slate-800 mt-1 leading-none">{{ value }}</p>
                </div>
                <div :class="[colors.bg, colors.border, 'w-10 h-10 rounded-xl flex items-center justify-center border shrink-0 ml-3']">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" :class="['w-5 h-5', colors.icon]">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons[icon] ?? icons.check" />
                    </svg>
                </div>
            </div>

            <!-- Bottom row -->
            <div class="mt-3 flex items-end justify-between">
                <div class="flex items-center gap-1.5">
                    <!-- Change badge -->
                    <template v-if="change !== undefined">
                        <svg
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            class="w-3.5 h-3.5"
                            :class="changeColor"
                        >
                            <path v-if="change >= 0" fill-rule="evenodd" d="M10 17a.75.75 0 01-.75-.75V5.612L5.29 9.77a.75.75 0 01-1.08-1.04l5.25-5.5a.75.75 0 011.08 0l5.25 5.5a.75.75 0 11-1.08 1.04l-3.96-4.158V16.25A.75.75 0 0110 17z" clip-rule="evenodd" />
                            <path v-else fill-rule="evenodd" d="M10 3a.75.75 0 01.75.75v10.638l3.96-4.158a.75.75 0 111.08 1.04l-5.25 5.5a.75.75 0 01-1.08 0l-5.25-5.5a.75.75 0 111.08-1.04l3.96 4.158V3.75A.75.75 0 0110 3z" clip-rule="evenodd" />
                        </svg>
                        <span class="text-xs font-semibold" :class="changeColor">
                            {{ Math.abs(change) }}%
                        </span>
                    </template>
                    <span v-if="subtitle" class="text-xs text-slate-400">{{ subtitle }}</span>
                </div>

                <!-- Sparkline -->
                <svg v-if="sparkline && sparkline.length > 1" viewBox="0 0 80 28" class="w-20 h-7" preserveAspectRatio="none">
                    <polyline
                        :points="sparklinePoints"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        :class="changeColor || colors.icon"
                    />
                </svg>
            </div>
        </template>
    </div>
</template>
