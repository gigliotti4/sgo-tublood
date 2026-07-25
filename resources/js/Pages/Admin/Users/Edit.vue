<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Button from '@/Components/Button.vue'
import CamposUsuario, { type SectorOption, type RoleOption, type UserFormData, type UsuarioOption } from '@/Components/CamposUsuario.vue'

interface UserData {
    id: number
    name: string
    apellido: string | null
    email: string
    sector_id: number | null
    supervisor_id: number | null
    gerente_id: number | null
    es_gerente: boolean
    roles: { name: string }[]
}

const props = defineProps<{ user: UserData; roles: RoleOption[]; sectores: SectorOption[]; usuarios: UsuarioOption[] }>()

const form = useForm<UserFormData>({
    name: props.user.name,
    apellido: props.user.apellido ?? '',
    email: props.user.email,
    password: '',
    sector_id: props.user.sector_id,
    supervisor_id: props.user.supervisor_id,
    gerente_id: props.user.gerente_id,
    es_gerente: props.user.es_gerente,
    roles: props.user.roles.map(r => r.name),
})

const submit = () => form.put(route('users.update', props.user.id))
</script>

<template>
    <Head title="Editar usuario" />

    <AppLayout>
        <div class="max-w-3xl mx-auto">
            <div class="flex items-center gap-3 mb-6">
                <Link :href="route('users.index')" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
                <h1 class="text-xl font-bold text-gray-800 dark:text-gray-100">Editar usuario</h1>
            </div>

            <form
                @submit.prevent="submit"
                class="bg-white dark:bg-slate-800 rounded-2xl shadow p-6 sm:p-8 space-y-8"
            >
                <CamposUsuario
                    modo="editar"
                    :form="form"
                    :roles="roles"
                    :sectores="sectores"
                    :usuarios="usuarios"
                />

                <div class="flex justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-700">
                    <Link
                        :href="route('users.index')"
                        class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-600 transition"
                    >
                        Cancelar
                    </Link>
                    <Button type="submit" variant="primary" :disabled="form.processing">
                        {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
