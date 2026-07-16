<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

interface Opcion {
    key: string
    titulo: string
    descripcion: string
    href: string
    icon: string
    color: string
    tituloColor: string
}

// Los formularios de cada opción se construyen en el siguiente paso;
// por ahora las tarjetas apuntan a las rutas previstas.
const opciones: Opcion[] = [
    {
        key: 'externa',
        titulo: 'Observación externa',
        descripcion: 'Reclamos de clientes (carga manual)',
        href: '/observaciones/crear?origen=externa',
        icon: 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008V11.25zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
        color: 'text-blue-500 dark:text-blue-400',
        tituloColor: 'text-blue-700 dark:text-blue-300',
    },
    {
        key: 'interna',
        titulo: 'Observación interna',
        descripcion: 'Registros por sector',
        href: '/observaciones/crear?origen=interna',
        icon: 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z',
        color: 'text-indigo-500 dark:text-indigo-400',
        tituloColor: 'text-indigo-700 dark:text-indigo-300',
    },
    {
        key: 'no_conformidad',
        titulo: 'No Conformidad',
        descripcion: 'NC independiente o vinculada',
        href: '/no-conformidades/crear',
        icon: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z',
        color: 'text-amber-500 dark:text-amber-400',
        tituloColor: 'text-purple-700 dark:text-purple-300',
    },
]
</script>

<template>
    <Head title="Crear nuevo registro" />

    <AppLayout>
        <div class="max-w-4xl mx-auto py-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Crear nuevo registro</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Elegí qué querés registrar</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <Link
                    v-for="o in opciones"
                    :key="o.key"
                    :href="o.href"
                    class="group flex flex-col items-center text-center bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-6 py-8 transition hover:border-indigo-300 dark:hover:border-indigo-500 hover:shadow-md"
                >
                    <svg
                        class="w-12 h-12 mb-4"
                        :class="o.color"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" :d="o.icon" />
                    </svg>
                    <h2 class="text-base font-semibold" :class="o.tituloColor">{{ o.titulo }}</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ o.descripcion }}</p>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
