<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { PageProps } from '@/types'

defineProps<{
    titulo: string
    subtitulo: string
}>()

const page = usePage<PageProps>()

// Lo deja el handler de 419 de bootstrap/app.php cuando venció la sesión, o
// el flash de éxito de las acciones de este layout (link de reset enviado,
// contraseña restablecida).
const flashError = computed(() => page.props.flash?.error)
const flashSuccess = computed(() => page.props.flash?.success)

// Marca y textos administrables desde /configuracion.
const marca = computed(() => page.props.configuracion)

// Foto de fondo del panel de branding: la cargada desde /configuracion, o la
// que viene con el sistema si nadie subió otra.
//
// El default no puede vivir en el catálogo de `config/configuracion.php` como
// el de los textos: ahí las imágenes se resuelven contra el disco `public`
// (`Storage::disk('public')->url(...)`) y esta es un archivo estático de
// `public/img/`, servido directo por el server. Por eso el fallback es acá.
const fondo = computed(() => marca.value.login_fondo ?? '/img/fondo-login.jpg')

// Un punto destacado por línea. Se filtran las vacías para que un salto de
// más en el textarea no dibuje una viñeta suelta. El trim también se come el
// retorno de carro que deja el textarea al enviar en CRLF.
const features = computed(() =>
    (marca.value.login_features ?? '')
        .split('\n')
        .map((f) => f.trim())
        .filter(Boolean),
)
</script>

<template>
    <div class="min-h-screen flex">

        <!-- ── Panel izquierdo: branding ── -->
        <!-- El color de fondo queda igual por si la foto no carga: el texto de acá es blanco. -->
        <div class="hidden lg:flex lg:w-[55%] bg-linear-to-br from-brand-950 via-brand-900 to-corp-900 flex-col justify-between p-14 relative overflow-hidden select-none">

            <!--
                Foto de fondo. `object-right` porque la escena está en la mitad
                derecha de la imagen y este panel es angosto y alto: encuadrada
                al centro se recortaría a la pared vacía.
            -->
            <img
                :src="fondo"
                alt=""
                aria-hidden="true"
                class="absolute inset-0 h-full w-full object-cover object-right pointer-events-none"
            />

            <!--
                Velo sobre la foto: es una imagen muy clara y todo el texto del
                panel es blanco. Más opaco a la izquierda (donde va el texto) y
                más transparente a la derecha, para que la escena se siga viendo.
            -->
            <div class="absolute inset-0 bg-linear-to-r from-brand-950/95 via-brand-950/85 to-brand-900/60 pointer-events-none" />
            <div class="absolute inset-0 bg-linear-to-t from-brand-950/90 via-transparent to-brand-950/40 pointer-events-none" />

            <!-- Formas decorativas de fondo -->
            <div class="absolute -top-32 -right-32 w-96 h-96 bg-brand-400/10 rounded-full blur-3xl pointer-events-none" />
            <div class="absolute -bottom-24 -left-24 w-80 h-80 bg-brand-400/10 rounded-full blur-3xl pointer-events-none" />
            <div
                class="absolute inset-0 opacity-[0.025] pointer-events-none"
                style="background-image: radial-gradient(circle, #ffffff 1px, transparent 1px); background-size: 28px 28px;"
            />

            <!-- Logo -->
            <!--
                Este panel siempre tiene fondo oscuro, así que va el logo claro.
                Sin logo claro cargado se usa el ícono por defecto (blanco) y no
                el `logo` normal: ese es para fondo claro y acá no se leería.
                Sin texto al lado: el logo ya trae la marca.
            -->
            <div class="relative flex items-center">
                <img
                    v-if="marca.logo_dark"
                    :src="marca.logo_dark"
                    :alt="marca.empresa_nombre"
                    class="h-14 w-auto max-w-55 object-contain"
                />
                <div v-else class="w-12 h-12 bg-brand-500 rounded-xl flex items-center justify-center shadow-lg shadow-brand-950/50 shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="sr-only">{{ marca.empresa_nombre }}</span>
                </div>
            </div>

            <!-- Contenido central -->
            <div class="relative space-y-7">
                <div class="space-y-2">
                    <p class="text-brand-100 text-sm font-semibold tracking-widest uppercase">{{ marca.login_kicker }}</p>
                    <h2 class="text-4xl xl:text-5xl font-bold text-white leading-tight">
                        {{ marca.login_titulo }}<br>
                        <span class="text-brand-100">{{ marca.login_titulo_destacado }}</span>
                    </h2>
                </div>

                <p class="text-corp-400 text-sm leading-relaxed max-w-xs">
                    {{ marca.login_parrafo }}
                </p>

                <div class="space-y-3">
                    <div v-for="item in features" :key="item" class="flex items-center gap-3">
                        <div class="w-5 h-5 rounded-full bg-brand-400/20 border border-brand-400/30 flex items-center justify-center shrink-0">
                            <svg class="w-3 h-3 text-brand-100" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                        <span class="text-corp-300 text-sm">{{ item }}</span>
                    </div>
                </div>
            </div>

            <!-- Footer del panel -->
            <div class="relative">
                <p class="text-corp-600 text-xs">© {{ new Date().getFullYear() }} {{ marca.login_footer }}</p>
            </div>
        </div>

        <!-- ── Panel derecho: formulario ── -->
        <div class="flex-1 flex items-center justify-center bg-corp-25 px-6 py-12">
            <div class="w-full max-w-sm">

                <!--
                    Logo móvil (solo en pantallas chicas, donde el panel oscuro
                    de la izquierda no se ve). Acá el fondo es claro, así que va
                    el `logo` normal y no la versión clara.
                -->
                <div class="flex items-center mb-10 lg:hidden">
                    <img
                        v-if="marca.logo"
                        :src="marca.logo"
                        :alt="marca.empresa_nombre"
                        class="h-10 w-auto max-w-45 object-contain"
                    />
                    <div v-else class="w-10 h-10 bg-brand-500 rounded-xl flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span class="sr-only">{{ marca.empresa_nombre }}</span>
                    </div>
                </div>

                <!-- Encabezado -->
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-corp-900 tracking-tight">{{ titulo }}</h1>
                    <p class="text-corp-500 text-sm mt-1.5">{{ subtitulo }}</p>
                </div>

                <!-- Aviso de error -->
                <div
                    v-if="flashError"
                    class="mb-5 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm text-amber-800"
                >
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    {{ flashError }}
                </div>

                <!-- Aviso de éxito -->
                <div
                    v-if="flashSuccess"
                    class="mb-5 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-sm text-emerald-800"
                >
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    {{ flashSuccess }}
                </div>

                <slot />

                <slot name="pie" />
            </div>
        </div>
    </div>
</template>
