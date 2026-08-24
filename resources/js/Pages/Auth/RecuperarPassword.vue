<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import CampoAuth from '@/Components/CampoAuth.vue'

const form = useForm({
    email: '',
})

const submit = () => form.post(route('password.email'))
</script>

<template>
    <Head title="Recuperar contraseña" />

    <AuthLayout
        titulo="Recuperar contraseña"
        subtitulo="Ingresá tu correo y te mandamos un enlace para restablecerla"
    >
        <form @submit.prevent="submit" class="space-y-5">
            <CampoAuth
                id="email"
                v-model="form.email"
                label="Correo electrónico"
                type="email"
                autocomplete="email"
                placeholder="usuario@tublood.com"
                :error="form.errors.email"
            >
                <template #icon>
                    <svg class="w-4 h-4 text-corp-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                    </svg>
                </template>
            </CampoAuth>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full flex items-center justify-center gap-2 bg-brand-500 hover:bg-brand-600 active:bg-brand-700 text-white py-2.5 px-4 rounded-lg text-sm font-semibold transition duration-150 disabled:opacity-60 disabled:cursor-not-allowed shadow-sm shadow-brand-100 mt-1"
            >
                <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                {{ form.processing ? 'Enviando...' : 'Enviarme el enlace' }}
            </button>
        </form>

        <p class="text-center text-sm text-corp-500 mt-6">
            <Link :href="route('login')" class="font-medium text-brand-500 hover:text-brand-600">
                Volver al inicio de sesión
            </Link>
        </p>
    </AuthLayout>
</template>
