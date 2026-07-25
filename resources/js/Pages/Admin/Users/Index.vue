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
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Usuarios</h1>
            <div class="flex gap-3">
                <Link
                    :href="route('sectores.index')"
                    class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200"
                >
                    Sectores
                </Link>
                <Button v-if="hasPermission('users.create')" variant="brand" @click="showImportModal = true">
                    Importar Excel
                </Button>
                <Link
                    v-if="hasPermission('users.create')"
                    :href="route('users.create')"
                    class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 transition"
                >
                    Nuevo usuario
                </Link>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-slate-700/40 text-gray-500 dark:text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3 text-left">Nombre</th>
                        <th class="px-6 py-3 text-left">Email</th>
                        <th class="px-6 py-3 text-left">Sector</th>
                        <th class="px-6 py-3 text-left">Supervisor</th>
                        <th class="px-6 py-3 text-left">Gerente</th>
                        <th class="px-6 py-3 text-left">Roles</th>
                        <th class="px-6 py-3 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                        <td class="px-6 py-4 font-medium text-gray-800 dark:text-slate-100">
                            {{ nombreCompleto(user) }}
                            <Badge v-if="user.es_gerente" variant="amber" :pill="false">gerente</Badge>
                        </td>
                        <td class="px-6 py-4 text-gray-500 dark:text-slate-400">{{ user.email }}</td>
                        <td class="px-6 py-4 text-gray-500 dark:text-slate-400">
                            {{ user.sector?.nombre ?? '—' }}
                            <span v-if="user.sector?.dias_gestion" class="text-xs text-gray-400 dark:text-slate-500">
                                · {{ user.sector.dias_gestion }} días
                            </span>
                        </td>
                        <td class="px-6 py-4 text-gray-500 dark:text-slate-400">
                            {{ user.supervisor ? nombreCompleto(user.supervisor) : '—' }}
                        </td>
                        <td class="px-6 py-4 text-gray-500 dark:text-slate-400">
                            {{ user.gerente ? nombreCompleto(user.gerente) : '—' }}
                        </td>
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
                        <td colspan="7" class="px-6 py-8 text-center text-gray-400 dark:text-slate-500">No hay usuarios.</td>
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

        <!-- Importación masiva por Excel -->
        <Modal :show="showImportModal" title="Importar usuarios desde Excel" @close="showImportModal = false">
            <form @submit.prevent="submitImport" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Archivo (.xlsx, .xls o .csv)
                    </label>
                    <input
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        class="w-full text-sm text-gray-700 dark:text-gray-300"
                        @change="onArchivoChange"
                    />
                    <p v-if="importForm.errors.archivo" class="text-red-500 dark:text-red-400 text-xs mt-1">
                        {{ importForm.errors.archivo }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">
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

                <p class="text-xs text-gray-500 dark:text-slate-400">
                    A cada usuario nuevo se le genera una contraseña al azar, que vas a ver una sola vez al terminar.
                    Los que ya existan (mismo mail) se actualizan sin tocarles contraseña ni rol.
                </p>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="importForm.processing">
                        {{ importForm.processing ? 'Importando...' : 'Importar' }}
                    </Button>
                    <button
                        type="button"
                        class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200"
                        @click="showImportModal = false"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Contraseñas generadas: se muestran una sola vez -->
        <Modal :show="showCredenciales" size="lg" title="Contraseñas de los usuarios importados" @close="showCredenciales = false">
            <p class="text-sm text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-lg px-3 py-2">
                Guardá estas contraseñas ahora: se generaron al azar y no se pueden volver a ver.
            </p>

            <div class="mt-4 max-h-80 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-slate-700/40 text-gray-500 dark:text-slate-400 uppercase text-xs">
                        <tr>
                            <th class="px-3 py-2 text-left">Nombre</th>
                            <th class="px-3 py-2 text-left">Mail</th>
                            <th class="px-3 py-2 text-left">Contraseña</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <tr v-for="u in importados" :key="u.email">
                            <td class="px-3 py-2 text-gray-800 dark:text-slate-100">{{ u.nombre }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-slate-400">{{ u.email }}</td>
                            <td class="px-3 py-2 font-mono text-gray-800 dark:text-slate-100">{{ u.password }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-3 mt-6">
                <Button variant="primary" @click="copiarCredenciales">Copiar todo</Button>
                <button
                    class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200"
                    @click="showCredenciales = false"
                >
                    Ya las guardé
                </button>
            </div>
        </Modal>
    </AppLayout>
</template>
