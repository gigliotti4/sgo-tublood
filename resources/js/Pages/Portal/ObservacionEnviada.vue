<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

defineProps<{
    numero: string
}>()
</script>

<template>
    <Head title="Observación enviada" />

    <div class="min-h-screen bg-slate-50 flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-md bg-white rounded-2xl shadow p-8 text-center">
            <div class="w-14 h-14 mx-auto rounded-full bg-emerald-100 flex items-center justify-center mb-5">
                <svg class="w-7 h-7 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </div>

            <h1 class="text-xl font-bold text-slate-900">¡Observación registrada!</h1>
            <p class="text-slate-500 text-sm mt-2">
                Tu observación fue recibida correctamente y será revisada por nuestro equipo de Garantía de Calidad.
            </p>

            <div class="mt-6 bg-slate-50 border border-slate-200 rounded-xl py-4">
                <p class="text-xs text-slate-400 uppercase tracking-wide">N° de seguimiento</p>
                <p class="text-2xl font-bold text-indigo-600 mt-1">{{ numero }}</p>
            </div>

            <Link
                :href="route('login')"
                class="inline-block mt-8 px-5 py-2.5 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition"
            >
                Volver al inicio
            </Link>
        </div>
    </div>
</template>
