<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'

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
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('roles.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Editar rol</h1>
        </div>

        <div class="max-w-lg rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <form @submit.prevent="submit" class="space-y-5">
                <Input v-model="form.name" label="Nombre del rol" :error="form.errors.name" />

                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">Permisos</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        <label v-for="perm in permissions" :key="perm.id" class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" :value="perm.name" v-model="form.permissions" class="h-4 w-4 rounded accent-brand-500 dark:accent-brand-400" />
                            {{ perm.name }}
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Guardar cambios</Button>
                    <Link :href="route('roles.index')" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]">
                        Cancelar
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
