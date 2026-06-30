<script setup lang="ts">
import { ref } from 'vue'

export interface Alert {
    id: number
    type: 'info' | 'success' | 'warning' | 'error'
    title: string
    message: string
    dismissible?: boolean
}

const props = defineProps<{ alerts: Alert[] }>()

const dismissed = ref<number[]>([])

const dismiss = (id: number) => dismissed.value.push(id)

const visible = () => props.alerts.filter(a => !dismissed.value.includes(a.id))

const typeStyles = {
    info:    { wrapper: 'bg-sky-50 border-sky-200', icon: 'text-sky-500', text: 'text-sky-800', sub: 'text-sky-600', path: 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z' },
    success: { wrapper: 'bg-emerald-50 border-emerald-200', icon: 'text-emerald-500', text: 'text-emerald-800', sub: 'text-emerald-600', path: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
    warning: { wrapper: 'bg-amber-50 border-amber-200', icon: 'text-amber-500', text: 'text-amber-800', sub: 'text-amber-600', path: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z' },
    error:   { wrapper: 'bg-red-50 border-red-200',  icon: 'text-red-500',  text: 'text-red-800',  sub: 'text-red-600',  path: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z' },
}
</script>

<template>
    <TransitionGroup name="alert" tag="div" class="space-y-2">
        <div
            v-for="alert in visible()"
            :key="alert.id"
            :class="['flex items-start gap-3 p-4 rounded-xl border', typeStyles[alert.type].wrapper]"
        >
            <!-- Icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" :class="['w-5 h-5 mt-0.5 shrink-0', typeStyles[alert.type].icon]">
                <path stroke-linecap="round" stroke-linejoin="round" :d="typeStyles[alert.type].path" />
            </svg>

            <!-- Text -->
            <div class="flex-1 min-w-0">
                <p :class="['text-sm font-semibold', typeStyles[alert.type].text]">{{ alert.title }}</p>
                <p :class="['text-sm mt-0.5', typeStyles[alert.type].sub]">{{ alert.message }}</p>
            </div>

            <!-- Dismiss -->
            <button
                v-if="alert.dismissible !== false"
                @click="dismiss(alert.id)"
                :class="['shrink-0 hover:opacity-70 transition-opacity', typeStyles[alert.type].icon]"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                </svg>
            </button>
        </div>
    </TransitionGroup>
</template>

<style scoped>
.alert-enter-active, .alert-leave-active { transition: all 0.3s ease; }
.alert-enter-from { opacity: 0; transform: translateY(-8px); }
.alert-leave-to   { opacity: 0; transform: translateX(20px); max-height: 0; }
</style>
