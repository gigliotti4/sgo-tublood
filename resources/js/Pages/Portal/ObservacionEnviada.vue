<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

defineProps<{
    numero: string
}>()
</script>

<template>
    <Head title="Observación enviada" />

    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-12 font-outfit">
        <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-theme-sm">
            <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-success-50">
                <svg class="h-7 w-7 text-success-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </div>

            <h1 class="text-xl font-bold text-gray-900">¡Observación registrada!</h1>
            <p class="mt-2 text-sm text-gray-500">
                Tu observación fue recibida correctamente y será revisada por nuestro equipo de Calidad.
            </p>

            <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 py-4">
                <p class="text-theme-xs uppercase tracking-wide text-gray-400">N° de seguimiento</p>
                <p class="mt-1 text-2xl font-bold text-brand-500">{{ numero }}</p>
            </div>

            <Link
                :href="route('login')"
                class="mt-8 inline-block rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600"
            >
                Volver al inicio
            </Link>
        </div>
    </div>
</template>
