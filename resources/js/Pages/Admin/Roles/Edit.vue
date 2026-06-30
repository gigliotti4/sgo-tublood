<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

interface PermOption { id: number; name: string }
interface RoleData { id: number; name: string; permissions: { name: string }[] }

const props = defineProps<{ role: RoleData; permissions: PermOption[] }>()

const form = useForm({
    name: props.role.name,
    permissions: props.role.permissions.map(p => p.name),
})

const submit = () => form.put(route('roles.update', props.role.id))
</script>

<template>
    <Head title="Editar rol" />

    <AppLayout>
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('roles.index')" class="text-gray-400 hover:text-gray-600">← Volver</Link>
            <h1 class="text-2xl font-bold text-gray-800">Editar rol</h1>
        </div>

        <div class="bg-white rounded-xl shadow p-6 max-w-lg">
            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del rol</label>
                    <input v-model="form.name" type="text" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                    <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Permisos</label>
                    <div class="grid grid-cols-2 gap-1">
                        <label v-for="perm in permissions" :key="perm.id" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" :value="perm.name" v-model="form.permissions" class="rounded" />
                            {{ perm.name }}
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-50"
                    >
                        Guardar cambios
                    </button>
                    <Link :href="route('roles.index')" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                        Cancelar
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
