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

interface RoleRow {
    id: number
    name: string
    permissions: { name: string }[]
}

defineProps<{ roles: PaginatedData<RoleRow> }>()

const { hasPermission } = usePermissions()

const roleToDelete = ref<RoleRow | null>(null)

const confirmDestroy = (role: RoleRow) => { roleToDelete.value = role }

const destroy = () => {
    if (!roleToDelete.value) return
    router.delete(route('roles.destroy', roleToDelete.value.id), {
        onFinish: () => { roleToDelete.value = null },
    })
}
</script>

<template>
    <Head title="Roles" />

    <AppLayout>
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Roles</h1>
            <Link
                v-if="hasPermission('roles.create')"
                :href="route('roles.create')"
                class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition"
            >
                Nuevo rol
            </Link>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-slate-700/40 text-gray-500 dark:text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3 text-left">Nombre</th>
                        <th class="px-6 py-3 text-left">Permisos</th>
                        <th class="px-6 py-3 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr v-for="role in roles.data" :key="role.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                        <td class="px-6 py-4 font-medium text-gray-800 dark:text-slate-100">{{ role.name }}</td>
                        <td class="px-6 py-4">
                            <Badge v-for="perm in role.permissions" :key="perm.name" variant="slate" :pill="false">
                                {{ perm.name }}
                            </Badge>
                            <span v-if="role.permissions.length === 0" class="text-gray-400 dark:text-slate-500">Sin permisos</span>
                        </td>
                        <td class="px-6 py-4 flex gap-3">
                            <Link
                                v-if="hasPermission('roles.edit')"
                                :href="route('roles.edit', role.id)"
                                class="text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                Editar
                            </Link>
                            <Button
                                v-if="hasPermission('roles.delete') && role.name !== 'super-admin'"
                                variant="danger-text"
                                @click="confirmDestroy(role)"
                            >
                                Eliminar
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="roles.data.length === 0">
                        <td colspan="3" class="px-6 py-8 text-center text-gray-400 dark:text-slate-500">No hay roles.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <div v-if="roles.last_page > 1" class="mt-4">
            <Pagination :links="roles.links" />
        </div>

        <!-- Confirmación de eliminación -->
        <Modal :show="roleToDelete !== null" title="Eliminar rol" @close="roleToDelete = null">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                ¿Eliminar el rol <strong>{{ roleToDelete?.name }}</strong>? Esta acción no se puede deshacer.
            </p>
            <div class="flex gap-3 mt-6">
                <Button variant="danger" @click="destroy">Eliminar</Button>
                <button class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200" @click="roleToDelete = null">
                    Cancelar
                </button>
            </div>
        </Modal>
    </AppLayout>
</template>
