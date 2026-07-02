<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import Pagination from '@/Components/Pagination.vue'
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

const userToDelete = ref<UserRow | null>(null)

const confirmDestroy = (user: UserRow) => { userToDelete.value = user }

const destroy = () => {
    if (!userToDelete.value) return
    router.delete(route('users.destroy', userToDelete.value.id), {
        onFinish: () => { userToDelete.value = null },
    })
}
</script>

<template>
    <Head title="Usuarios" />

    <AppLayout>
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Usuarios</h1>
            <Link
                v-if="hasPermission('users.create')"
                :href="route('users.create')"
                class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition"
            >
                Nuevo usuario
            </Link>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-slate-700/40 text-gray-500 dark:text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3 text-left">Nombre</th>
                        <th class="px-6 py-3 text-left">Email</th>
                        <th class="px-6 py-3 text-left">Roles</th>
                        <th class="px-6 py-3 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                        <td class="px-6 py-4 font-medium text-gray-800 dark:text-slate-100">{{ user.name }}</td>
                        <td class="px-6 py-4 text-gray-500 dark:text-slate-400">{{ user.email }}</td>
                        <td class="px-6 py-4">
                            <Badge v-for="role in user.roles" :key="role.name" variant="indigo" :pill="false">
                                {{ role.name }}
                            </Badge>
                        </td>
                        <td class="px-6 py-4 flex gap-3">
                            <Link
                                v-if="hasPermission('users.edit')"
                                :href="route('users.edit', user.id)"
                                class="text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                Editar
                            </Link>
                            <Button
                                v-if="hasPermission('users.delete')"
                                variant="danger-text"
                                @click="confirmDestroy(user)"
                            >
                                Eliminar
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="users.data.length === 0">
                        <td colspan="4" class="px-6 py-8 text-center text-gray-400 dark:text-slate-500">No hay usuarios.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <div v-if="users.last_page > 1" class="mt-4">
            <Pagination :links="users.links" />
        </div>

        <!-- Confirmación de eliminación -->
        <Modal :show="userToDelete !== null" title="Eliminar usuario" @close="userToDelete = null">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                ¿Eliminar a <strong>{{ userToDelete?.name }}</strong>? Esta acción no se puede deshacer.
            </p>
            <div class="flex gap-3 mt-6">
                <Button variant="danger" @click="destroy">Eliminar</Button>
                <button class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200" @click="userToDelete = null">
                    Cancelar
                </button>
            </div>
        </Modal>
    </AppLayout>
</template>
