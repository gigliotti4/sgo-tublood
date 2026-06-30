<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import type { PaginatedData } from '@/types'

interface UserRow {
    id: number
    name: string
    email: string
    created_at: string
    roles: { name: string }[]
}

defineProps<{ users: PaginatedData<UserRow> }>()

const { hasPermission } = usePermissions()

const destroy = (id: number) => {
    if (confirm('¿Eliminar este usuario?')) {
        router.delete(route('users.destroy', id))
    }
}
</script>

<template>
    <Head title="Usuarios" />

    <AppLayout>
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Usuarios</h1>
            <Link
                v-if="hasPermission('users.create')"
                :href="route('users.create')"
                class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition"
            >
                Nuevo usuario
            </Link>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3 text-left">Nombre</th>
                        <th class="px-6 py-3 text-left">Email</th>
                        <th class="px-6 py-3 text-left">Roles</th>
                        <th class="px-6 py-3 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 font-medium text-gray-800">{{ user.name }}</td>
                        <td class="px-6 py-4 text-gray-500">{{ user.email }}</td>
                        <td class="px-6 py-4">
                            <span
                                v-for="role in user.roles"
                                :key="role.name"
                                class="inline-block bg-indigo-100 text-indigo-700 text-xs px-2 py-0.5 rounded mr-1"
                            >
                                {{ role.name }}
                            </span>
                        </td>
                        <td class="px-6 py-4 flex gap-3">
                            <Link
                                v-if="hasPermission('users.edit')"
                                :href="route('users.edit', user.id)"
                                class="text-blue-600 hover:underline"
                            >
                                Editar
                            </Link>
                            <button
                                v-if="hasPermission('users.delete')"
                                @click="destroy(user.id)"
                                class="text-red-600 hover:underline"
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="4" class="px-6 py-8 text-center text-gray-400">No hay usuarios.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <div v-if="users.last_page > 1" class="flex gap-1 mt-4">
            <Link
                v-for="link in users.links"
                :key="link.label"
                :href="link.url ?? '#'"
                v-html="link.label"
                class="px-3 py-1 rounded border text-sm"
                :class="link.active
                    ? 'bg-indigo-600 text-white border-indigo-600'
                    : 'hover:bg-gray-50 text-gray-600'"
            />
        </div>
    </AppLayout>
</template>
