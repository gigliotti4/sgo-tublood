<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'

interface PermOption { id: number; name: string }

defineProps<{ permissions: PermOption[] }>()

const form = useForm({
    name: '',
    permissions: [] as string[],
})

const submit = () => form.post(route('roles.store'))
</script>

<template>
    <Head title="Nuevo rol" />

    <AppLayout>
        <div class="flex items-center gap-3 mb-6">
            <Link :href="route('roles.index')" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Nuevo rol</h1>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow p-6 max-w-lg">
            <form @submit.prevent="submit" class="space-y-4">
                <Input v-model="form.name" label="Nombre del rol" :error="form.errors.name" />

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Permisos</label>
                    <div class="grid grid-cols-2 gap-1">
                        <label v-for="perm in permissions" :key="perm.id" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" :value="perm.name" v-model="form.permissions" class="rounded" />
                            {{ perm.name }}
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Crear rol</Button>
                    <Link :href="route('roles.index')" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                        Cancelar
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
