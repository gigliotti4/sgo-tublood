<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { usePermissions } from '@/composables/usePermissions'
import type { PageProps } from '@/types'

const { user, hasPermission } = usePermissions()
const page = usePage<PageProps>()

const sidebarCollapsed = ref(false)
const mobileSidebarOpen = ref(false)

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
            { label: 'Clientes', route: 'clientes.index', permission: 'clientes.view', icon: 'building' },
        ]
    },
    {
        title: 'Administración',
        items: [
            { label: 'Usuarios',   route: 'users.index', permission: 'users.view', icon: 'users' },
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
    if (e.key === 'Escape') mobileSidebarOpen.value = false
}
onMounted(() => window.addEventListener('keydown', handleEsc))
onUnmounted(() => window.removeEventListener('keydown', handleEsc))

// Heroicons paths (24px stroke)
const icons: Record<string, string> = {
    home: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
    users: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    shield: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    building: 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
    bell: 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
    logout: 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9',
    chevron: 'M8.25 4.5l7.5 7.5-7.5 7.5',
    menu: 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
    x: 'M6 18L18 6M6 6l12 12',
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 flex">

        <!-- Overlay mobile -->
        <Transition name="fade">
            <div
                v-if="mobileSidebarOpen"
                class="fixed inset-0 bg-black/50 z-30 lg:hidden"
                @click="mobileSidebarOpen = false"
            />
        </Transition>

        <!-- SIDEBAR -->
        <aside
            class="fixed top-0 left-0 h-full z-40 flex flex-col transition-all duration-300 bg-[#2a3182]"
            :class="[
                sidebarCollapsed ? 'w-16' : 'w-64',
                mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
            ]"
        >
            <!-- Logo -->
            <div class="flex items-center h-16 px-4 border-b border-white/10 shrink-0">
                <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <Transition name="slide">
                    <span v-if="!sidebarCollapsed" class="ml-3 text-white font-bold text-base tracking-tight">
                        SGO Admin
                    </span>
                </Transition>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto py-4 space-y-6">
                <div v-for="section in visibleSections" :key="section.title">
                    <p
                        v-if="!sidebarCollapsed"
                        class="px-4 mb-1 text-[10px] font-semibold uppercase tracking-widest text-white/40"
                    >
                        {{ section.title }}
                    </p>
                    <div class="space-y-0.5 px-2">
                        <Link
                            v-for="item in section.items"
                            :key="item.route"
                            :href="route(item.route)"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150 group relative"
                            :class="isActive(item.route)
                                ? 'bg-white/15 text-white'
                                : 'text-white/60 hover:bg-white/10 hover:text-white'"
                            :title="sidebarCollapsed ? item.label : ''"
                        >
                            <!-- Active indicator -->
                            <span
                                v-if="isActive(item.route)"
                                class="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-5 bg-white rounded-r-full"
                            />
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.75"
                                class="w-5 h-5 shrink-0"
                                :class="isActive(item.route) ? 'text-white' : 'text-white/50 group-hover:text-white'"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
                            </svg>
                            <Transition name="slide">
                                <span v-if="!sidebarCollapsed" class="truncate">{{ item.label }}</span>
                            </Transition>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- User -->
            <div class="border-t border-white/10 p-3 shrink-0">
                <div
                    class="flex items-center gap-3 rounded-lg p-2"
                    :class="sidebarCollapsed ? 'justify-center' : ''"
                >
                    <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-white text-xs font-bold shrink-0">
                        {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                    </div>
                    <Transition name="slide">
                        <div v-if="!sidebarCollapsed" class="flex-1 min-w-0">
                            <p class="text-white text-xs font-semibold truncate">{{ user?.name }}</p>
                            <p class="text-white/40 text-[11px] truncate">{{ user?.roles[0] ?? '' }}</p>
                        </div>
                    </Transition>
                    <Transition name="slide">
                        <button
                            v-if="!sidebarCollapsed"
                            @click="logout"
                            class="text-white/40 hover:text-white transition-colors shrink-0"
                            title="Cerrar sesión"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="icons.logout" />
                            </svg>
                        </button>
                    </Transition>
                </div>
            </div>

            <!-- Collapse toggle (desktop) -->
            <button
                class="hidden lg:flex absolute -right-3 top-20 w-6 h-6 rounded-full bg-white shadow-md border border-slate-200 items-center justify-center text-slate-500 hover:text-slate-700 transition-colors"
                @click="sidebarCollapsed = !sidebarCollapsed"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3 h-3 transition-transform" :class="sidebarCollapsed ? 'rotate-0' : 'rotate-180'">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="icons.chevron" />
                </svg>
            </button>
        </aside>

        <!-- MAIN -->
        <div
            class="flex-1 flex flex-col min-w-0 transition-all duration-300"
            :class="sidebarCollapsed ? 'lg:pl-16' : 'lg:pl-64'"
        >
            <!-- Header -->
            <header class="sticky top-0 z-20 h-16 bg-white border-b border-slate-200 flex items-center gap-4 px-4 lg:px-6 shrink-0">
                <!-- Mobile toggle -->
                <button
                    class="lg:hidden text-slate-500 hover:text-slate-700"
                    @click="mobileSidebarOpen = !mobileSidebarOpen"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.menu" />
                    </svg>
                </button>

                <!-- Page title from Inertia -->
                <div class="flex-1">
                    <h1 class="text-sm font-semibold text-slate-800">
                        {{ page.props.auth.user ? 'Panel Administrativo' : '' }}
                    </h1>
                </div>

                <!-- Flash success inline -->
                <Transition name="fade">
                    <span
                        v-if="page.props.flash.success"
                        class="hidden sm:inline-flex items-center gap-1.5 text-xs bg-emerald-50 text-emerald-700 border border-emerald-200 px-3 py-1.5 rounded-full"
                    >
                        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                        {{ page.props.flash.success }}
                    </span>
                </Transition>

                <!-- Notification bell -->
                <button class="relative text-slate-400 hover:text-slate-600 transition-colors">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.bell" />
                    </svg>
                </button>

                <!-- User avatar -->
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full bg-[#2a3182] flex items-center justify-center text-white text-xs font-bold">
                        {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                    </div>
                    <div class="hidden sm:block">
                        <p class="text-xs font-semibold text-slate-700 leading-tight">{{ user?.name }}</p>
                        <p class="text-[11px] text-slate-400 leading-tight">{{ user?.roles[0] ?? '' }}</p>
                    </div>
                </div>
            </header>

            <!-- Flash error banner -->
            <Transition name="slide-down">
                <div v-if="page.props.flash.error" class="bg-red-50 border-b border-red-200 px-6 py-3 flex items-center gap-2 text-sm text-red-700">
                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                    {{ page.props.flash.error }}
                </div>
            </Transition>

            <!-- Content -->
            <main class="flex-1 p-4 lg:p-6 overflow-auto">
                <slot />
            </main>
        </div>
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
