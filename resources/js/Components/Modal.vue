<script setup lang="ts">
withDefaults(defineProps<{
    show: boolean
    title?: string
    size?: 'sm' | 'lg'
}>(), {
    size: 'sm',
})

const emit = defineEmits<{ close: [] }>()

const sizeClasses: Record<string, string> = {
    sm: 'max-w-md',
    lg: 'max-w-xl',
}
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="emit('close')" />
                <div class="relative bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full p-6 max-h-[90vh] overflow-y-auto" :class="sizeClasses[size]">
                    <h3 v-if="title" class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">{{ title }}</h3>
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.15s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
