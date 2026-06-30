<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import type { PaginatedData } from '@/types'

interface RoleRow {
    id: number
    name: string
    permissions: { name: string }[]
}

defineProps<{ roles: PaginatedData<RoleRow> }>()

const { hasPermission } = usePermissions()

const destroy = (id: number) => {
    if (confirm('¿Eliminar este rol?')) {
        router.delete(route('roles.destroy', id))
    }
}
</script>

<template>
    <Head title="Roles" />

    <AppLayout>
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Roles</h1>
            <Link
                v-if="hasPermission('roles.create')"
                :href="route('roles.create')"
                class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition"
            >
                Nuevo rol
            </Link>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3 text-left">Nombre</th>
                        <th class="px-6 py-3 text-left">Permisos</th>
                        <th class="px-6 py-3 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="role in roles.data" :key="role.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 font-medium text-gray-800">{{ role.name }}</td>
                        <td class="px-6 py-4">
                            <span
                                v-for="perm in role.permissions"
                                :key="perm.name"
                                class="inline-block bg-gray-100 text-gray-600 text-xs px-2 py-0.5 rounded mr-1 mb-1"
                            >
                                {{ perm.name }}
                            </span>
                            <span v-if="role.permissions.length === 0" class="text-gray-400">Sin permisos</span>
                        </td>
                        <td class="px-6 py-4 flex gap-3">
                            <Link
                                v-if="hasPermission('roles.edit')"
                                :href="route('roles.edit', role.id)"
                                class="text-blue-600 hover:underline"
                            >
                                Editar
                            </Link>
                            <button
                                v-if="hasPermission('roles.delete') && role.name !== 'super-admin'"
                                @click="destroy(role.id)"
                                class="text-red-600 hover:underline"
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="roles.data.length === 0">
                        <td colspan="3" class="px-6 py-8 text-center text-gray-400">No hay roles.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
