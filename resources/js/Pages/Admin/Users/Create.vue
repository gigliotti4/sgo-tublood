<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'

interface RoleOption { id: number; name: string }

defineProps<{ roles: RoleOption[] }>()

const form = useForm({
    name: '',
    email: '',
    password: '',
    roles: [] as string[],
})

const submit = () => form.post(route('users.store'))
</script>

<template>
    <Head title="Nuevo usuario" />

    <AppLayout>
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('users.index')" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Nuevo usuario</h1>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-6 max-w-lg">
            <form @submit.prevent="submit" class="space-y-4">
                <Input v-model="form.name" label="Nombre" :error="form.errors.name" />
                <Input v-model="form.email" type="email" label="Email" :error="form.errors.email" />
                <Input v-model="form.password" type="password" label="Contraseña" :error="form.errors.password" />

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Roles</label>
                    <div class="space-y-1">
                        <label v-for="role in roles" :key="role.id" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" :value="role.name" v-model="form.roles" class="rounded" />
                            {{ role.name }}
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Crear usuario</Button>
                    <Link :href="route('users.index')" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                        Cancelar
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
