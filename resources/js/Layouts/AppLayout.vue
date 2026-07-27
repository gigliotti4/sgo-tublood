<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { Link, router, usePage, usePoll } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { usePermissions } from '@/composables/usePermissions'
import { useDarkMode } from '@/composables/useDarkMode'
import Modal, { modalesAbiertos } from '@/Components/Modal.vue'
import type { PageProps } from '@/types'

const { user, hasPermission } = usePermissions()
const { isDark, toggleTheme } = useDarkMode()
const page = usePage<PageProps>()

const sidebarCollapsed = ref(false)
const mobileSidebarOpen = ref(false)
const notificacionesOpen = ref(false)
const userMenuOpen = ref(false)

const vencimientos = computed(() => page.props.notificaciones?.vencimientos ?? [])
const alertas = computed(() => page.props.notificaciones?.alertas ?? [])
// Reclamos sin clasificar: sale de una consulta viva contra `observations`, no
// de una notificación, así que se autolimpia sola en cuanto alguien clasifica
// el caso. Es la fuente de verdad; `externasNuevas` (más abajo) es un
// subconjunto de esta misma lista.
const sinClasificar = computed(() => page.props.notificaciones?.sinClasificar ?? [])
const totalNotificaciones = computed(() => vencimientos.value.length + alertas.value.length + sinClasificar.value.length)

const marcarLeidas = () => router.post(route('notificaciones.leidas'), {}, { preserveScroll: true })

const origenLabels: Record<string, string> = { interna: 'Interna', externa: 'Externa' }

/**
 * Aviso (una sola vez) de reclamos nuevos del portal para el equipo de
 * Garantía de Calidad. Es el subconjunto de `sinClasificar` que este usuario
 * todavía no vio — cerrar el modal solo calla el popup, el reclamo se sigue
 * viendo en la campana hasta que alguien lo clasifique de verdad.
 *
 * Se refresca con polling (no solo al entrar), así que puede aparecer en
 * cualquier pantalla mientras la persona ya está trabajando — por eso se
 * abstiene mientras haya un formulario en pantalla o cualquier otro modal
 * abierto (ver `modalesAbiertos` en Modal.vue), y solo se evalúa mientras está
 * cerrado: una vez abierto no se vuelve a tocar hasta que el usuario lo cierra.
 */
const externasNuevas = computed(() => page.props.notificaciones?.externas ?? [])
const mostrarExternas = ref(false)
const cerrandoExternas = ref(false)

const esPantallaDeFormulario = computed(() => /Crear|Create|Edit|Nuevo/.test(page.component))

watch(
    [externasNuevas, () => page.component, modalesAbiertos],
    () => {
        if (mostrarExternas.value || cerrandoExternas.value) return
        if (externasNuevas.value.length === 0) return
        if (esPantallaDeFormulario.value) return
        if (modalesAbiertos.value > 0) return

        mostrarExternas.value = true
    },
    { immediate: true },
)

usePoll(60000, { only: ['notificaciones'] })

const cerrarExternas = (observacionId?: number) => {
    mostrarExternas.value = false
    // Bloquea la reapertura mientras page.props todavía tiene la lista vieja
    // (la respuesta del post es lo que la actualiza): sin esto, el watch de
    // arriba reabriría el modal en el instante entre el clic y esa respuesta.
    cerrandoExternas.value = true

    router.post(
        route('notificaciones.externas.vistas'),
        observacionId ? { observacion_id: observacionId } : {},
        { preserveScroll: true, onFinish: () => { cerrandoExternas.value = false } },
    )
}

const fechaCorta = (fecha: string) =>
    new Date(fecha).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })

const diasParaVencer = (fecha: string) => {
    const hoy = new Date()
    hoy.setHours(0, 0, 0, 0)
    const venc = new Date(fecha)
    venc.setHours(0, 0, 0, 0)
    return Math.round((venc.getTime() - hoy.getTime()) / 86400000)
}

const labelVencimiento = (fecha: string) => {
    const dias = diasParaVencer(fecha)
    if (dias < 0) return `Vencido hace ${Math.abs(dias)} día${Math.abs(dias) !== 1 ? 's' : ''}`
    if (dias === 0) return 'Vence hoy'
    return `Vence en ${dias} día${dias !== 1 ? 's' : ''}`
}

interface MenuItem {
    label: string
    route: string
    permission?: string
    icon: string
}

interface MenuSection {
    title: string
    items: MenuItem[]
}

const menuSections: MenuSection[] = [
    {
        title: 'General',
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: 'home' },
        ]
    },
    {
        title: 'Gestión',
        items: [
            { label: 'Observaciones', route: 'observaciones.index', permission: 'observaciones.view', icon: 'document' },
            { label: 'Clientes', route: 'clientes.index', permission: 'clientes.view', icon: 'building' },
        ]
    },
    {
        title: 'Administración',
        items: [
            { label: 'Usuarios',   route: 'users.index', permission: 'users.view', icon: 'users' },
            { label: 'Sectores',   route: 'sectores.index', permission: 'users.view', icon: 'squares' },
            { label: 'Roles',      route: 'roles.index',  permission: 'roles.view',  icon: 'shield' },
        ]
    }
]

const visibleSections = computed(() =>
    menuSections.map(section => ({
        ...section,
        items: section.items.filter(item => !item.permission || hasPermission(item.permission))
    })).filter(section => section.items.length > 0)
)

const isActive = (routeName: string) => {
    const segment = routeName.split('.')[0]
    return page.url === `/${segment}` || page.url.startsWith(`/${segment}/`)
}

const logout = () => router.post(route('logout'))

const handleEsc = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        mobileSidebarOpen.value = false
        userMenuOpen.value = false
        notificacionesOpen.value = false
    }
}
onMounted(() => window.addEventListener('keydown', handleEsc))
onUnmounted(() => window.removeEventListener('keydown', handleEsc))

// Heroicons paths (24px stroke)
const icons: Record<string, string> = {
    home: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
    users: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    squares: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
    shield: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    building: 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
    document: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9h3.75M12 15.75h5.25M8.25 9h1.5m-1.5 3.75h1.5m-1.5 3.75h1.5M6.75 3h6.879a2.25 2.25 0 011.591.659l4.121 4.121a2.25 2.25 0 01.659 1.591V19.5a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25V5.25A2.25 2.25 0 016.75 3z',
    bell: 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
    logout: 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9',
    chevronDown: 'M19.5 8.25l-7.5 7.5-7.5-7.5',
    menu: 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
    x: 'M6 18L18 6M6 6l12 12',
    sun: 'M12 3v1.5m0 15V21m9-9h-1.5M4.5 12H3m15.364 6.364l-1.06-1.06M6.696 6.696l-1.06-1.06m12.728 0l-1.06 1.06M6.696 17.304l-1.06 1.06M16.5 12a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z',
    moon: 'M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z',
    dots: 'M6.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM12.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM18.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex font-outfit text-gray-700 dark:text-gray-400">

        <!-- Overlay mobile -->
        <Transition name="fade">
            <div
                v-if="mobileSidebarOpen"
                class="fixed inset-0 bg-gray-900/50 z-40 lg:hidden"
                @click="mobileSidebarOpen = false"
            />
        </Transition>

        <!-- SIDEBAR -->
        <aside
            class="fixed top-0 left-0 z-50 flex h-full flex-col border-r border-gray-200 bg-white transition-all duration-300 dark:border-gray-800 dark:bg-gray-900"
            :class="[
                sidebarCollapsed ? 'lg:w-[90px] w-[290px]' : 'w-[290px]',
                mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
            ]"
        >
            <!-- Logo -->
            <div
                class="flex h-16 shrink-0 items-center gap-3 px-5"
                :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
            >
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-500 shadow-theme-xs">
                    <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <Transition name="slide">
                    <div v-if="!sidebarCollapsed || mobileSidebarOpen" class="min-w-0">
                        <p class="truncate text-base font-bold tracking-tight text-gray-900 dark:text-white">SGO Tublood</p>
                        <p class="truncate text-theme-xs text-gray-400">Gestión de Observaciones</p>
                    </div>
                </Transition>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto px-4 py-4 space-y-6">
                <div v-for="section in visibleSections" :key="section.title">
                    <p
                        class="mb-2 text-xs uppercase leading-5 text-gray-400"
                        :class="sidebarCollapsed ? 'lg:text-center' : 'px-3'"
                    >
                        <template v-if="!sidebarCollapsed">{{ section.title }}</template>
                        <svg v-else class="mx-auto hidden h-4 w-4 lg:block" viewBox="0 0 24 24" fill="currentColor">
                            <path :d="icons.dots" />
                        </svg>
                        <template v-if="sidebarCollapsed"><span class="lg:hidden">{{ section.title }}</span></template>
                    </p>
                    <div class="space-y-1">
                        <Link
                            v-for="item in section.items"
                            :key="item.route"
                            :href="route(item.route)"
                            class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                            :class="[
                                isActive(item.route)
                                    ? 'bg-brand-50 text-brand-500 dark:bg-brand-500/[0.12] dark:text-brand-300'
                                    : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]',
                                sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''
                            ]"
                            :title="sidebarCollapsed ? item.label : ''"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.75"
                                class="h-5 w-5 shrink-0"
                                :class="isActive(item.route)
                                    ? 'text-brand-500 dark:text-brand-300'
                                    : 'text-gray-500 group-hover:text-gray-700 dark:text-gray-400 dark:group-hover:text-gray-200'"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
                            </svg>
                            <Transition name="slide">
                                <span v-if="!sidebarCollapsed || mobileSidebarOpen" class="truncate">{{ item.label }}</span>
                            </Transition>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- User -->
            <div class="shrink-0 border-t border-gray-200 p-4 dark:border-gray-800">
                <div
                    class="flex items-center gap-3 rounded-lg p-1.5"
                    :class="sidebarCollapsed ? 'lg:justify-center' : ''"
                >
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500 text-sm font-semibold text-white">
                        {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                    </div>
                    <Transition name="slide">
                        <div v-if="!sidebarCollapsed || mobileSidebarOpen" class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ user?.name }}</p>
                            <p class="truncate text-theme-xs text-gray-400">{{ user?.roles[0] ?? '' }}</p>
                        </div>
                    </Transition>
                    <Transition name="slide">
                        <button
                            v-if="!sidebarCollapsed || mobileSidebarOpen"
                            @click="logout"
                            class="shrink-0 text-gray-400 transition-colors hover:text-error-500"
                            title="Cerrar sesión"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="icons.logout" />
                            </svg>
                        </button>
                    </Transition>
                </div>
            </div>
        </aside>

        <!-- MAIN -->
        <div
            class="flex min-w-0 flex-1 flex-col transition-all duration-300"
            :class="sidebarCollapsed ? 'lg:pl-[90px]' : 'lg:pl-[290px]'"
        >
            <!-- Header -->
            <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 dark:border-gray-800 dark:bg-gray-900 lg:px-6">
                <!-- Sidebar toggle -->
                <button
                    class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200 lg:hidden"
                    @click="mobileSidebarOpen = !mobileSidebarOpen"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.menu" />
                    </svg>
                </button>
                <button
                    class="hidden h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200 lg:flex"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.menu" />
                    </svg>
                </button>

                <div class="flex-1" />

                <!-- Flash success inline -->
                <Transition name="fade">
                    <span
                        v-if="page.props.flash.success"
                        class="hidden items-center gap-1.5 rounded-full bg-success-50 px-3 py-1.5 text-theme-xs font-medium text-success-600 dark:bg-success-500/15 dark:text-success-400 sm:inline-flex"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                        {{ page.props.flash.success }}
                    </span>
                </Transition>

                <!-- Dark mode toggle -->
                <button
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200"
                    :title="isDark ? 'Modo claro' : 'Modo oscuro'"
                    @click="toggleTheme"
                >
                    <svg v-if="isDark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.sun" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.moon" />
                    </svg>
                </button>

                <!-- Notification bell -->
                <div class="relative">
                    <button
                        class="relative flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200"
                        @click="notificacionesOpen = !notificacionesOpen"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icons.bell" />
                        </svg>
                        <span
                            v-if="totalNotificaciones > 0"
                            class="absolute -right-0.5 -top-0.5 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-warning-500 px-1 text-[10px] font-bold text-white ring-2 ring-white dark:ring-gray-900"
                        >
                            {{ totalNotificaciones > 9 ? '9+' : totalNotificaciones }}
                        </span>
                    </button>

                    <!-- Click-outside overlay -->
                    <div v-if="notificacionesOpen" class="fixed inset-0 z-30" @click="notificacionesOpen = false" />

                    <Transition name="fade">
                        <div
                            v-if="notificacionesOpen"
                            class="absolute right-0 top-full z-40 mt-3 max-h-96 w-80 overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-900"
                        >
                            <!--
                                Sin clasificar: consulta viva contra `observations`, no una
                                notificación — no lleva "Marcar leídas" porque esta lista no se
                                descarta a mano, se limpia sola cuando alguien clasifica el caso.
                            -->
                            <template v-if="sinClasificar.length > 0">
                                <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Sin clasificar</p>
                                </div>
                                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <li v-for="o in sinClasificar" :key="o.id">
                                        <Link
                                            :href="route('observaciones.show', o.id)"
                                            class="block px-5 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                                            @click="notificacionesOpen = false"
                                        >
                                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">
                                                {{ o.numero }} — {{ o.titulo }}
                                            </p>
                                            <p class="mt-0.5 text-theme-xs text-warning-600 dark:text-warning-400">
                                                {{ origenLabels[o.origen] ?? o.origen }}<template v-if="o.contacto_nombre"> · {{ o.contacto_nombre }}</template>
                                            </p>
                                        </Link>
                                    </li>
                                </ul>
                            </template>

                            <!-- Alertas de observaciones: las deja el comando observaciones:alertas -->
                            <template v-if="alertas.length > 0">
                                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Observaciones</p>
                                    <button
                                        class="cursor-pointer text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                                        @click="marcarLeidas"
                                    >
                                        Marcar leídas
                                    </button>
                                </div>
                                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <li v-for="a in alertas" :key="a.id">
                                        <Link
                                            :href="route('observaciones.index')"
                                            class="block px-5 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                                            @click="notificacionesOpen = false"
                                        >
                                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">
                                                {{ a.data.numero }} — {{ a.data.titulo }}
                                            </p>
                                            <p
                                                class="mt-0.5 text-theme-xs"
                                                :class="a.data.tipo === 'observacion_finalizada'
                                                    ? 'text-success-600 dark:text-success-400'
                                                    : 'text-error-500'"
                                            >
                                                {{ a.data.mensaje }}
                                            </p>
                                        </Link>
                                    </li>
                                </ul>
                            </template>

                            <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                                <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Clientes por vencer</p>
                            </div>
                            <ul v-if="vencimientos.length > 0" class="divide-y divide-gray-100 dark:divide-gray-800">
                                <li v-for="c in vencimientos" :key="c.id">
                                    <Link
                                        :href="route('clientes.index', { search: c.numero })"
                                        class="block px-5 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                                        @click="notificacionesOpen = false"
                                    >
                                        <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ c.razon_social }}</p>
                                        <p
                                            class="mt-0.5 text-theme-xs"
                                            :class="diasParaVencer(c.fecha_vencimiento) < 0 ? 'text-error-500' : 'text-warning-600 dark:text-warning-400'"
                                        >
                                            {{ labelVencimiento(c.fecha_vencimiento) }}
                                        </p>
                                    </Link>
                                </li>
                            </ul>
                            <p v-else class="px-5 py-6 text-center text-sm text-gray-400">
                                No hay clientes por vencer.
                            </p>
                        </div>
                    </Transition>
                </div>

                <!-- User dropdown -->
                <div class="relative">
                    <button
                        class="flex cursor-pointer items-center gap-2.5 rounded-full py-1 pl-1 pr-2 transition-colors hover:bg-gray-100 dark:hover:bg-white/[0.05]"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-500 text-sm font-semibold text-white">
                            {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                        </div>
                        <span class="hidden text-sm font-medium text-gray-700 dark:text-gray-300 sm:block">{{ user?.name }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="hidden h-4 w-4 text-gray-400 transition-transform sm:block" :class="userMenuOpen ? 'rotate-180' : ''">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icons.chevronDown" />
                        </svg>
                    </button>

                    <div v-if="userMenuOpen" class="fixed inset-0 z-30" @click="userMenuOpen = false" />

                    <Transition name="fade">
                        <div
                            v-if="userMenuOpen"
                            class="absolute right-0 top-full z-40 mt-3 w-64 rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900"
                        >
                            <div class="border-b border-gray-100 px-2 pb-3 dark:border-gray-800">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ user?.name }}</p>
                                <p class="truncate text-theme-xs text-gray-400">{{ user?.email }}</p>
                                <p v-if="user?.roles[0]" class="mt-0.5 truncate text-theme-xs text-gray-400">{{ user?.roles[0] }}</p>
                            </div>
                            <button
                                class="mt-2 flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                                @click="logout"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5 text-gray-500 dark:text-gray-400">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="icons.logout" />
                                </svg>
                                Cerrar sesión
                            </button>
                        </div>
                    </Transition>
                </div>
            </header>

            <!-- Flash error banner -->
            <Transition name="slide-down">
                <div v-if="page.props.flash.error" class="flex items-center gap-2 border-b border-error-200 bg-error-50 px-6 py-3 text-sm text-error-600 dark:border-error-500/30 dark:bg-error-500/15 dark:text-error-400">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                    {{ page.props.flash.error }}
                </div>
            </Transition>

            <!-- Content -->
            <main class="flex-1 overflow-auto p-4 md:p-6">
                <div class="mx-auto w-full max-w-[1536px]">
                    <slot />
                </div>
            </main>
        </div>

        <!-- FAB: nueva observación, visible en todas las secciones -->
        <Link
            v-if="hasPermission('observaciones.edit')"
            :href="route('observaciones.nuevo')"
            class="fixed bottom-6 right-6 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-brand-500 text-white shadow-theme-lg transition hover:scale-105 hover:bg-brand-600"
            title="Nueva observación"
        >
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span class="sr-only">Nueva observación</span>
        </Link>

        <!-- Reclamos nuevos del portal, para el equipo de Garantía de Calidad -->
        <Modal
            :show="mostrarExternas"
            size="lg"
            :title="externasNuevas.length === 1 ? 'Entró un reclamo nuevo' : `Entraron ${externasNuevas.length} reclamos nuevos`"
            @close="cerrarExternas()"
        >
            <p class="-mt-2 mb-4 text-theme-sm text-gray-500 dark:text-gray-400">
                Cargados por clientes desde el portal. Tocá uno para abrirlo.
            </p>

            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                <li v-for="e in externasNuevas" :key="e.id">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-start justify-between gap-4 px-1 py-3.5 text-left transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                        @click="cerrarExternas(e.id)"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">
                                {{ e.numero }} — {{ e.titulo }}
                            </p>
                            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                <template v-if="e.contacto_nombre">Cargado por {{ e.contacto_nombre }}</template>
                                <template v-else>{{ origenLabels[e.origen] ?? e.origen }}</template>
                            </p>
                        </div>
                        <span class="shrink-0 pt-0.5 text-theme-xs text-gray-400">
                            {{ fechaCorta(e.created_at) }}
                        </span>
                    </button>
                </li>
            </ul>

            <div class="mt-5 flex justify-end gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                <button
                    type="button"
                    class="cursor-pointer rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                    @click="cerrarExternas()"
                >
                    Después los veo
                </button>
                <Link
                    :href="route('observaciones.index')"
                    class="cursor-pointer rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600"
                    @click="cerrarExternas()"
                >
                    Ver todos
                </Link>
            </div>
        </Modal>
    </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-enter-active, .slide-leave-active { transition: all 0.2s; overflow: hidden; }
.slide-enter-from, .slide-leave-to { opacity: 0; width: 0; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.2s; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-8px); }
</style>
