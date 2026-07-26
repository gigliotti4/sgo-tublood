<script setup lang="ts">
import { computed, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Modal from '@/Components/Modal.vue'
import Pagination from '@/Components/Pagination.vue'
import Select from '@/Components/Select.vue'
import type { PaginatedData } from '@/types'

interface RoleOption { id: number; name: string }
interface Importado { nombre: string; email: string; password: string }
interface Persona { id: number; name: string; apellido: string | null }
interface UserRow {
    id: number
    name: string
    apellido: string | null
    email: string
    created_at: string
    es_gerente: boolean
    roles: { name: string }[]
    sector: { id: number; nombre: string; dias_gestion: number | null } | null
    supervisor: Persona | null
    gerente: Persona | null
}

const props = defineProps<{
    users: PaginatedData<UserRow>
    roles: RoleOption[]
    importados?: Importado[] | null
}>()

const { hasPermission } = usePermissions()

const userToDelete = ref<UserRow | null>(null)

const confirmDestroy = (user: UserRow) => { userToDelete.value = user }

const destroy = () => {
    if (!userToDelete.value) return
    router.delete(route('users.destroy', userToDelete.value.id), {
        onFinish: () => { userToDelete.value = null },
    })
}

const nombreCompleto = (persona: Persona | UserRow) => [persona.name, persona.apellido].filter(Boolean).join(' ')

const showImportModal = ref(false)
const importForm = useForm({
    archivo: null as File | null,
    rol: '',
})

const onArchivoChange = (e: Event) => {
    importForm.archivo = (e.target as HTMLInputElement).files?.[0] ?? null
}

const submitImport = () => {
    importForm.post(route('users.import'), {
        forceFormData: true,
        onSuccess: () => {
            showImportModal.value = false
            importForm.reset()
        },
    })
}

// Las contraseñas llegan por flash y solo se muestran una vez: se cierran a mano
// para que no desaparezcan por una navegación accidental.
const showCredenciales = ref((props.importados?.length ?? 0) > 0)

const credencialesComoTexto = computed(() =>
    (props.importados ?? []).map(u => `${u.nombre}\t${u.email}\t${u.password}`).join('\n')
)

const copiarCredenciales = () => navigator.clipboard.writeText(credencialesComoTexto.value)
</script>

<template>
    <Head title="Usuarios" />

    <AppLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Usuarios</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">Gestión de usuarios y jerarquía</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Link
                    :href="route('sectores.index')"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                >
                    Sectores
                </Link>
                <Button v-if="hasPermission('users.create')" variant="outline" @click="showImportModal = true">
                    Importar Excel
                </Button>
                <Link
                    v-if="hasPermission('users.create')"
                    :href="route('users.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nuevo usuario
                </Link>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Nombre</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Email</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Sector</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Supervisor</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Gerente</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Roles</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="user in users.data" :key="user.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <td class="px-6 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ nombreCompleto(user) }}
                                <Badge v-if="user.es_gerente" variant="amber" :pill="false">gerente</Badge>
                            </td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ user.email }}</td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ user.sector?.nombre ?? '—' }}
                                <span v-if="user.sector?.dias_gestion" class="text-theme-xs text-gray-400">
                                    · {{ user.sector.dias_gestion }} días
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ user.supervisor ? nombreCompleto(user.supervisor) : '—' }}
                            </td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ user.gerente ? nombreCompleto(user.gerente) : '—' }}
                            </td>
                            <td class="px-6 py-3.5">
                                <Badge v-for="role in user.roles" :key="role.name" variant="indigo" :pill="false">
                                    {{ role.name }}
                                </Badge>
                            </td>
                            <td class="flex gap-3 px-6 py-3.5 text-theme-sm">
                                <Link
                                    v-if="hasPermission('users.edit')"
                                    :href="route('users.edit', user.id)"
                                    class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
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
                            <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-400">No hay usuarios.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
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
            <div class="mt-6 flex gap-3">
                <Button variant="danger" @click="destroy">Eliminar</Button>
                <Button variant="outline" @click="userToDelete = null">Cancelar</Button>
            </div>
        </Modal>

        <!-- Importación masiva por Excel -->
        <Modal :show="showImportModal" title="Importar usuarios desde Excel" @close="showImportModal = false">
            <form @submit.prevent="submitImport" class="space-y-4">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Archivo (.xlsx, .xls o .csv)
                    </label>
                    <input
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        class="w-full cursor-pointer rounded-lg border border-gray-300 text-sm text-gray-700 shadow-theme-xs file:mr-4 file:cursor-pointer file:border-0 file:bg-gray-100 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-gray-700 dark:border-gray-700 dark:text-gray-300 dark:file:bg-white/[0.05] dark:file:text-gray-300"
                        @change="onArchivoChange"
                    />
                    <p v-if="importForm.errors.archivo" class="mt-1.5 text-xs text-error-500 dark:text-error-400">
                        {{ importForm.errors.archivo }}
                    </p>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        Con encabezado y en este orden: <strong>Nombre</strong>, <strong>Apellido</strong>,
                        <strong>Mail</strong>, <strong>Sector original</strong>, <strong>Supervisor</strong>,
                        <strong>Gerente aviso final</strong> y <strong>Tiempo de gestión</strong>.
                        Las últimas cuatro son opcionales; las columnas vacías no pisan lo que ya esté cargado.
                    </p>
                </div>

                <Select v-model="importForm.rol" label="Rol para los usuarios nuevos" :error="importForm.errors.rol">
                    <option value="" disabled>— Seleccionar —</option>
                    <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                </Select>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    A cada usuario nuevo se le genera una contraseña al azar, que vas a ver una sola vez al terminar.
                    Los que ya existan (mismo mail) se actualizan sin tocarles contraseña ni rol.
                </p>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="importForm.processing">
                        {{ importForm.processing ? 'Importando...' : 'Importar' }}
                    </Button>
                    <Button variant="outline" @click="showImportModal = false">Cancelar</Button>
                </div>
            </form>
        </Modal>

        <!-- Contraseñas generadas: se muestran una sola vez -->
        <Modal :show="showCredenciales" size="lg" title="Contraseñas de los usuarios importados" @close="showCredenciales = false">
            <p class="rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/15 dark:text-warning-400">
                Guardá estas contraseñas ahora: se generaron al azar y no se pueden volver a ver.
            </p>

            <div class="mt-4 max-h-80 overflow-y-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <th class="px-3 py-2 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Nombre</th>
                            <th class="px-3 py-2 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Mail</th>
                            <th class="px-3 py-2 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Contraseña</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="u in importados" :key="u.email">
                            <td class="px-3 py-2 text-theme-sm text-gray-800 dark:text-white/90">{{ u.nombre }}</td>
                            <td class="px-3 py-2 text-theme-sm text-gray-500 dark:text-gray-400">{{ u.email }}</td>
                            <td class="px-3 py-2 font-mono text-theme-sm text-gray-800 dark:text-white/90">{{ u.password }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-6 flex gap-3">
                <Button variant="primary" @click="copiarCredenciales">Copiar todo</Button>
                <Button variant="outline" @click="showCredenciales = false">Ya las guardé</Button>
            </div>
        </Modal>
    </AppLayout>
</template>
