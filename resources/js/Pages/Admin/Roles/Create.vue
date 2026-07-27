<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import SelectorPermisos from '@/Components/SelectorPermisos.vue'
import type { PermisoEtiquetado } from '@/types'

defineProps<{ permissions: PermisoEtiquetado[] }>()

const form = useForm({
    name: '',
    permissions: [] as string[],
})

const submit = () => form.post(route('roles.store'))
</script>

<template>
    <Head title="Nuevo rol" />

    <AppLayout>
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('roles.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Nuevo rol</h1>
        </div>

        <div class="max-w-2xl rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <form @submit.prevent="submit" class="space-y-5">
                <Input v-model="form.name" label="Nombre del rol" :error="form.errors.name" />

                <SelectorPermisos v-model="form.permissions" :permisos="permissions" :error="form.errors.permissions" />

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Crear rol</Button>
                    <Link :href="route('roles.index')" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]">
                        Cancelar
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
