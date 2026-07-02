<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3'
import { ref } from 'vue'

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const showPassword = ref(false)

const features = [
    'Registro y clasificación de observaciones',
    'Gestión de no conformidades y CAPA',
    'Trazabilidad completa por expediente',
]

const submit = () => form.post(route('login'))
</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="min-h-screen flex">

        <!-- ── Panel izquierdo: branding ── -->
        <div class="hidden lg:flex lg:w-[55%] bg-linear-to-br from-indigo-950 via-indigo-900 to-slate-900 flex-col justify-between p-14 relative overflow-hidden select-none">

            <!-- Formas decorativas de fondo -->
            <div class="absolute -top-32 -right-32 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none" />
            <div class="absolute -bottom-24 -left-24 w-80 h-80 bg-indigo-400/10 rounded-full blur-3xl pointer-events-none" />
            <div
                class="absolute inset-0 opacity-[0.025] pointer-events-none"
                style="background-image: radial-gradient(circle, #ffffff 1px, transparent 1px); background-size: 28px 28px;"
            />

            <!-- Logo -->
            <div class="relative flex items-center gap-3">
                <div class="w-10 h-10 bg-indigo-500 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-900/50 shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <span class="text-white font-bold text-lg leading-tight block">Tublood SA</span>
                    <span class="text-indigo-400 text-xs">Dispositivos Médicos</span>
                </div>
            </div>

            <!-- Contenido central -->
            <div class="relative space-y-7">
                <div class="space-y-2">
                    <p class="text-indigo-400 text-sm font-semibold tracking-widest uppercase">Sistema de Gestión</p>
                    <h2 class="text-4xl xl:text-5xl font-bold text-white leading-tight">
                        Observaciones<br>
                        <span class="text-indigo-400">bajo control.</span>
                    </h2>
                </div>

                <p class="text-slate-400 text-sm leading-relaxed max-w-xs">
                    Plataforma interna para la gestión, clasificación y seguimiento de observaciones y no conformidades según normativas vigentes.
                </p>

                <div class="space-y-3">
                    <div v-for="item in features" :key="item" class="flex items-center gap-3">
                        <div class="w-5 h-5 rounded-full bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center shrink-0">
                            <svg class="w-3 h-3 text-indigo-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <span class="text-slate-300 text-sm">{{ item }}</span>
                    </div>
                </div>
            </div>

            <!-- Footer del panel -->
            <div class="relative">
                <p class="text-slate-600 text-xs">© {{ new Date().getFullYear() }} Tublood SA · Todos los derechos reservados</p>
            </div>
        </div>

        <!-- ── Panel derecho: formulario ── -->
        <div class="flex-1 flex items-center justify-center bg-slate-50 px-6 py-12">
            <div class="w-full max-w-sm">

                <!-- Logo móvil (solo en pantallas pequeñas) -->
                <div class="flex items-center gap-3 mb-10 lg:hidden">
                    <div class="w-9 h-9 bg-indigo-600 rounded-xl flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div>
                        <span class="font-bold text-slate-800 text-base block leading-tight">Tublood SA</span>
                        <span class="text-slate-400 text-xs">Sistema SGO</span>
                    </div>
                </div>

                <!-- Encabezado -->
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Bienvenido de nuevo</h1>
                    <p class="text-slate-500 text-sm mt-1.5">Ingresá tus credenciales para acceder al sistema</p>
                </div>

                <!-- Formulario -->
                <form @submit.prevent="submit" class="space-y-5">

                    <!-- Email -->
                    <div class="space-y-1.5">
                        <label for="email" class="block text-sm font-medium text-slate-700">
                            Correo electrónico
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                                </svg>
                            </span>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                placeholder="usuario@tublood.com"
                                class="w-full pl-9 pr-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 placeholder-slate-400 transition duration-150 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.email
                                    ? 'border-red-400 ring-1 ring-red-300'
                                    : 'border-slate-200 hover:border-slate-300'"
                            />
                        </div>
                        <p v-if="form.errors.email" class="flex items-center gap-1.5 text-red-500 text-xs">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- Contraseña -->
                    <div class="space-y-1.5">
                        <label for="password" class="block text-sm font-medium text-slate-700">
                            Contraseña
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                            </span>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                placeholder="••••••••"
                                class="w-full pl-9 pr-10 py-2.5 text-sm rounded-lg border bg-white text-slate-900 placeholder-slate-400 transition duration-150 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.password
                                    ? 'border-red-400 ring-1 ring-red-300'
                                    : 'border-slate-200 hover:border-slate-300'"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 transition"
                                :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                            >
                                <!-- Ojo abierto -->
                                <svg v-if="!showPassword" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <!-- Ojo tachado -->
                                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                </svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="flex items-center gap-1.5 text-red-500 text-xs">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <!-- Recordarme -->
                    <div class="flex items-center gap-2.5">
                        <input
                            id="remember"
                            v-model="form.remember"
                            type="checkbox"
                            class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500 focus:ring-offset-0 cursor-pointer"
                        />
                        <label for="remember" class="text-sm text-slate-600 cursor-pointer select-none">
                            Mantener sesión iniciada
                        </label>
                    </div>

                    <!-- Botón principal -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white py-2.5 px-4 rounded-lg text-sm font-semibold transition duration-150 disabled:opacity-60 disabled:cursor-not-allowed shadow-sm shadow-indigo-200 mt-1"
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

                <!-- Divisor -->
                <div class="relative my-7">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200" />
                    </div>
                    <div class="relative flex justify-center">
                        <span class="px-3 bg-slate-50 text-xs text-slate-400 font-medium">¿Sos cliente externo?</span>
                    </div>
                </div>

                <!-- Acceso externo -->
                <Link
                    :href="route('observaciones.public.create')"
                    class="group flex items-center justify-center gap-2 w-full py-2.5 px-4 rounded-lg border border-slate-200 bg-white text-sm font-medium text-slate-600 hover:border-indigo-300 hover:text-indigo-600 hover:bg-indigo-50/50 transition duration-150"
                >
                    <svg class="w-4 h-4 text-slate-400 group-hover:text-indigo-500 transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    Cargar una observación
                </Link>

                <p class="text-center text-xs text-slate-400 mt-8">
                    SGO · Sistema de Gestión de Observaciones
                </p>
            </div>
        </div>
    </div>
</template>
