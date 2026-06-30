<script setup lang="ts">
export interface Activity {
    id: number
    user: string
    action: string
    target: string
    time: string
    type: 'create' | 'update' | 'delete' | 'login' | 'info'
}

defineProps<{
    activities: Activity[]
    loading?: boolean
}>()

const typeConfig = {
    create: { label: 'Creó',     color: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-400' },
    update: { label: 'Editó',    color: 'bg-sky-100 text-sky-700',         dot: 'bg-sky-400' },
    delete: { label: 'Eliminó',  color: 'bg-red-100 text-red-600',         dot: 'bg-red-400' },
    login:  { label: 'Ingresó',  color: 'bg-slate-100 text-slate-600',     dot: 'bg-slate-400' },
    info:   { label: 'Info',     color: 'bg-amber-100 text-amber-700',     dot: 'bg-amber-400' },
}
</script>

<template>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800">Actividad reciente</h3>
            <span class="text-xs text-slate-400">Últimas 24 h</span>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="p-4 space-y-4">
            <div v-for="i in 5" :key="i" class="flex items-center gap-3 animate-pulse">
                <div class="w-8 h-8 bg-slate-200 rounded-full shrink-0" />
                <div class="flex-1 space-y-1.5">
                    <div class="h-3 bg-slate-200 rounded w-3/4" />
                    <div class="h-2.5 bg-slate-100 rounded w-1/2" />
                </div>
                <div class="h-2.5 w-12 bg-slate-100 rounded" />
            </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="activities.length === 0" class="flex flex-col items-center justify-center py-12 px-4">
            <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mb-3">
                <svg class="w-6 h-6 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <p class="text-sm font-medium text-slate-600">Sin actividad reciente</p>
            <p class="text-xs text-slate-400 mt-1">Las acciones del sistema aparecerán aquí</p>
        </div>

        <!-- List -->
        <ul v-else class="divide-y divide-slate-50">
            <li
                v-for="activity in activities"
                :key="activity.id"
                class="flex items-center gap-3 px-5 py-3.5 hover:bg-slate-50/70 transition-colors"
            >
                <!-- Avatar -->
                <div class="relative shrink-0">
                    <div class="w-8 h-8 rounded-full bg-[#2a3182]/10 flex items-center justify-center text-[#2a3182] text-xs font-bold">
                        {{ activity.user.charAt(0).toUpperCase() }}
                    </div>
                    <span :class="['absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-white', typeConfig[activity.type].dot]" />
                </div>

                <!-- Description -->
                <div class="flex-1 min-w-0">
                    <p class="text-xs text-slate-700 leading-relaxed">
                        <span class="font-semibold">{{ activity.user }}</span>
                        {{ activity.action }}
                        <span class="font-medium text-slate-600">{{ activity.target }}</span>
                    </p>
                    <span :class="['inline-block text-[10px] font-medium px-1.5 py-0.5 rounded mt-0.5', typeConfig[activity.type].color]">
                        {{ typeConfig[activity.type].label }}
                    </span>
                </div>

                <!-- Time -->
                <span class="text-[11px] text-slate-400 shrink-0">{{ activity.time }}</span>
            </li>
        </ul>
    </div>
</template>
