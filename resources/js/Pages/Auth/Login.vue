<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Head, Link, usePage } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import CampoAuth from '@/Components/CampoAuth.vue'
import type { PageProps } from '@/types'

const marca = computed(() => usePage<PageProps>().props.configuracion)

// `remember` viene tildado: es un sistema interno de uso diario y la cookie de
// recordarme (30 días, ver AppServiceProvider) es lo que evita tener que
// reloguearse cada mañana. Queda el checkbox para quien prefiera destildarlo.
const form = useForm({
    email: '',
    password: '',
    remember: true,
})

const submit = () => form.post(route('login'))
</script>

<template>
    <Head title="Iniciar sesión" />

    <AuthLayout titulo="Bienvenido de nuevo" subtitulo="Ingresá tus credenciales para acceder al sistema">
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

            <CampoAuth
                id="password"
                v-model="form.password"
                label="Contraseña"
                es-password
                autocomplete="current-password"
                placeholder="••••••••"
                :error="form.errors.password"
            >
                <template #icon>
                    <svg class="w-4 h-4 text-corp-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </template>
            </CampoAuth>

            <!-- Recordarme + olvidé mi contraseña -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <input
                        id="remember"
                        v-model="form.remember"
                        type="checkbox"
                        class="w-4 h-4 text-brand-500 border-corp-300 rounded focus:ring-brand-500 focus:ring-offset-0 cursor-pointer"
                    />
                    <label for="remember" class="text-sm text-corp-600 cursor-pointer select-none">
                        Mantener sesión iniciada
                    </label>
                </div>
                <Link :href="route('password.request')" class="text-sm font-medium text-brand-500 hover:text-brand-600">
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>

            <!-- Botón principal -->
            <button
                type="submit"
                :disabled="form.processing"
                class="w-full flex items-center justify-center gap-2 bg-brand-500 hover:bg-brand-600 active:bg-brand-700 text-white py-2.5 px-4 rounded-lg text-sm font-semibold transition duration-150 disabled:opacity-60 disabled:cursor-not-allowed shadow-sm shadow-brand-100 mt-1"
            >
                <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                </svg>
                {{ form.processing ? 'Ingresando...' : 'Ingresar al sistema' }}
            </button>
        </form>

        <template #pie>
            <!-- Divisor -->
            <div class="relative my-7">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-corp-200" />
                </div>
                <div class="relative flex justify-center">
                    <span class="px-3 bg-corp-25 text-xs text-corp-400 font-medium">¿Sos cliente externo?</span>
                </div>
            </div>

            <!-- Acceso externo -->
            <Link
                :href="route('observaciones.public.create')"
                class="group flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-lg border border-corp-200 bg-white text-sm font-medium text-corp-600 hover:border-brand-300 hover:text-brand-500 hover:bg-brand-50/50 transition duration-150"
            >
                <svg class="w-4 h-4 text-corp-400 group-hover:text-brand-500 transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Cargar una observación
            </Link>

            <p class="text-center text-xs text-corp-400 mt-8">
                {{ marca.login_pie_sistema }}
            </p>
        </template>
    </AuthLayout>
</template>
