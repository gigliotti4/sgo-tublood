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
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Roles</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">Roles y permisos del sistema</p>
            </div>
            <Link
                v-if="hasPermission('roles.create')"
                :href="route('roles.create')"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Nuevo rol
            </Link>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Nombre</th>
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Permisos</th>
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <tr v-for="role in roles.data" :key="role.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                        <td class="px-6 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ role.name }}</td>
                        <td class="px-6 py-3.5">
                            <Badge v-for="perm in role.permissions" :key="perm.name" variant="slate" :pill="false">
                                {{ perm.name }}
                            </Badge>
                            <span v-if="role.permissions.length === 0" class="text-theme-sm text-gray-400">Sin permisos</span>
                        </td>
                        <td class="flex gap-3 px-6 py-3.5 text-theme-sm">
                            <Link
                                v-if="hasPermission('roles.edit')"
                                :href="route('roles.edit', role.id)"
                                class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
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
                        <td colspan="3" class="px-6 py-10 text-center text-sm text-gray-400">No hay roles.</td>
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
            <div class="mt-6 flex gap-3">
                <Button variant="danger" @click="destroy">Eliminar</Button>
                <Button variant="outline" @click="roleToDelete = null">Cancelar</Button>
            </div>
        </Modal>
    </AppLayout>
</template>
