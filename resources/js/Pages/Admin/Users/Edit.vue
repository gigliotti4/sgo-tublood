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
            <div class="mb-6 flex items-center gap-3">
                <Link :href="route('users.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Editar usuario</h1>
            </div>

            <form
                @submit.prevent="submit"
                class="space-y-8 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-8"
            >
                <CamposUsuario
                    modo="editar"
                    :form="form"
                    :roles="roles"
                    :sectores="sectores"
                    :usuarios="usuarios"
                />

                <div class="flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
                    <Link
                        :href="route('users.index')"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
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
