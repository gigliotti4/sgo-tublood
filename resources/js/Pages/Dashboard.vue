<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'
import MetricCard from '@/Components/Dashboard/MetricCard.vue'
import QuickActionCard from '@/Components/Dashboard/QuickActionCard.vue'
import RecentActivity from '@/Components/Dashboard/RecentActivity.vue'
import AlertPanel from '@/Components/Dashboard/AlertPanel.vue'
import ChartCard from '@/Components/Dashboard/ChartCard.vue'
import { usePermissions } from '@/composables/usePermissions'
import type { Alert } from '@/Components/Dashboard/AlertPanel.vue'
import type { Activity } from '@/Components/Dashboard/RecentActivity.vue'
import { route } from 'ziggy-js'

const { hasPermission } = usePermissions()

// ── Datos mock — reemplazar con props desde el controlador ──────────────────

const metrics = [
    {
        title: 'Total Usuarios',
        value: '128',
        change: 12,
        subtitle: 'vs mes anterior',
        icon: 'users',
        color: 'primary' as const,
        sparkline: [40, 55, 48, 70, 62, 85, 80],
    },
    {
        title: 'Roles Activos',
        value: '5',
        change: 0,
        subtitle: 'sin cambios',
        icon: 'shield',
        color: 'info' as const,
        sparkline: [3, 3, 4, 4, 5, 5, 5],
    },
    {
        title: 'Permisos',
        value: '24',
        change: 4,
        subtitle: 'nuevos este mes',
        icon: 'key',
        color: 'success' as const,
        sparkline: [18, 18, 20, 20, 22, 22, 24],
    },
    {
        title: 'Sesiones Hoy',
        value: '37',
        change: -8,
        subtitle: 'vs ayer',
        icon: 'lightning',
        color: 'warning' as const,
        sparkline: [50, 40, 35, 45, 42, 38, 37],
    },
]

const alerts: Alert[] = [
    {
        id: 1,
        type: 'warning',
        title: 'Caché pendiente de limpiar',
        message: 'El caché de configuración tiene más de 7 días. Ejecutá php artisan optimize:clear.',
        dismissible: true,
    },
    {
        id: 2,
        type: 'info',
        title: 'Sistema actualizado',
        message: 'Se actualizó correctamente a la versión 1.0.0 el 26/06/2026.',
        dismissible: true,
    },
]

const chartData = {
    labels: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
    datasets: [
        { label: 'Accesos', data: [22, 35, 28, 45, 40, 18, 12] },
    ],
}

const activities: Activity[] = [
    { id: 1, user: 'Super Admin',   action: 'creó el usuario',    target: 'juan@empresa.com',   time: 'hace 5 min',  type: 'create' },
    { id: 2, user: 'Super Admin',   action: 'editó el rol',       target: 'Admin',              time: 'hace 18 min', type: 'update' },
    { id: 3, user: 'María López',   action: 'inició sesión',      target: '',                   time: 'hace 32 min', type: 'login'  },
    { id: 4, user: 'Carlos Ruiz',   action: 'eliminó el usuario', target: 'test@test.com',      time: 'hace 1 h',   type: 'delete' },
    { id: 5, user: 'Super Admin',   action: 'asignó el permiso',  target: 'users.edit → Admin', time: 'hace 2 h',   type: 'update' },
    { id: 6, user: 'Ana Gómez',     action: 'inició sesión',      target: '',                   time: 'hace 3 h',   type: 'login'  },
]

const loading = ref(false)

// ── Acciones rápidas según permisos ────────────────────────────────────────
const quickActions = [
    {
        title: 'Nuevo usuario',
        description: 'Crear y asignar roles a un nuevo miembro',
        icon: 'user-plus',
        href: route('users.create'),
        color: 'primary' as const,
        permission: 'users.create',
    },
    {
        title: 'Gestionar roles',
        description: 'Editar permisos de los roles existentes',
        icon: 'shield',
        href: route('roles.index'),
        color: 'info' as const,
        permission: 'roles.view',
    },
    {
        title: 'Ver usuarios',
        description: 'Listado completo de usuarios del sistema',
        icon: 'users',
        href: route('users.index'),
        color: 'success' as const,
        permission: 'users.view',
    },
    {
        title: 'Nuevo rol',
        description: 'Definir un rol con permisos personalizados',
        icon: 'cog',
        href: route('roles.create'),
        color: 'warning' as const,
        permission: 'roles.create',
    },
].filter(a => !a.permission || hasPermission(a.permission))
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <!-- Page header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-bold text-slate-800">Dashboard</h1>
                <p class="text-sm text-slate-500 mt-0.5">
                    {{ new Date().toLocaleDateString('es-AR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-xs bg-emerald-50 text-emerald-700 border border-emerald-200 px-3 py-1.5 rounded-full font-medium">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse" />
                    Sistema operativo
                </span>
            </div>
        </div>

        <!-- Alerts -->
        <div v-if="alerts.length" class="mb-5">
            <AlertPanel :alerts="alerts" />
        </div>

        <!-- Metrics -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <MetricCard
                v-for="m in metrics"
                :key="m.title"
                v-bind="m"
                :loading="loading"
            />
        </div>

        <!-- Chart + Quick actions -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
            <!-- Chart (2/3) -->
            <div class="lg:col-span-2">
                <ChartCard
                    title="Accesos al sistema"
                    subtitle="Sesiones iniciadas por día"
                    :labels="chartData.labels"
                    :datasets="chartData.datasets"
                    :loading="loading"
                />
            </div>

            <!-- Quick actions (1/3) -->
            <div class="flex flex-col gap-3">
                <h3 class="text-sm font-semibold text-slate-700 px-1">Acciones rápidas</h3>
                <div class="grid grid-cols-2 gap-3 flex-1">
                    <QuickActionCard
                        v-for="action in quickActions"
                        :key="action.title"
                        v-bind="action"
                    />
                </div>
            </div>
        </div>

        <!-- Recent activity -->
        <RecentActivity :activities="activities" :loading="loading" />

    </AppLayout>
</template>
