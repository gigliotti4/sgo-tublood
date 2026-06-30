<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

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
            <Link :href="route('users.index')" class="text-gray-400 hover:text-gray-600">← Volver</Link>
            <h1 class="text-2xl font-bold text-gray-800">Nuevo usuario</h1>
        </div>

        <div class="bg-white rounded-xl shadow p-6 max-w-lg">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                    <input v-model="form.name" type="text" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                    <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input v-model="form.email" type="email" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                    <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                    <input v-model="form.password" type="password" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                    <p v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Roles</label>
                    <div class="space-y-1">
                        <label v-for="role in roles" :key="role.id" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" :value="role.name" v-model="form.roles" class="rounded" />
                            {{ role.name }}
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-50"
                    >
                        Crear usuario
                    </button>
                    <Link :href="route('users.index')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                        Cancelar
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
