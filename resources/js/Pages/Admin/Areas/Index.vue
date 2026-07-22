<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Input from '@/Components/Input.vue'
import Modal from '@/Components/Modal.vue'
import type { Area } from '@/types'

defineProps<{ areas: Area[] }>()

const { hasPermission } = usePermissions()

const editando = ref<Area | null>(null)
const creando = ref(false)

const form = useForm({
    nombre: '',
    dias_gestion: null as number | null,
    activo: true,
})

const abrirNueva = () => {
    form.reset()
    form.clearErrors()
    creando.value = true
}

const abrirEdicion = (area: Area) => {
    form.nombre = area.nombre
    form.dias_gestion = area.dias_gestion
    form.activo = area.activo
    form.clearErrors()
    editando.value = area
}

const cerrar = () => {
    editando.value = null
    creando.value = false
}

const guardar = () => {
    if (creando.value) {
        form.post(route('areas.store'), { onSuccess: cerrar })

        return
    }

    if (editando.value) {
        form.put(route('areas.update', editando.value.id), { onSuccess: cerrar })
    }
}
</script>

<template>
    <Head title="Áreas" />

    <AppLayout>
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Áreas</h1>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                    Organigrama de la empresa. El plazo de gestión es el que arranca cuando se asigna un
                    responsable de esa área a una observación.
                </p>
            </div>
            <div class="flex gap-3">
                <Link
                    :href="route('users.index')"
                    class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200"
                >
                    Usuarios
                </Link>
                <Button v-if="hasPermission('users.edit')" variant="primary" @click="abrirNueva">
                    Nueva área
                </Button>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-slate-700/40 text-gray-500 dark:text-slate-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-3 text-left">Área</th>
                        <th class="px-6 py-3 text-left">Plazo de gestión</th>
                        <th class="px-6 py-3 text-left">Usuarios</th>
                        <th class="px-6 py-3 text-left">Estado</th>
                        <th class="px-6 py-3 text-left">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                    <tr v-for="area in areas" :key="area.id" class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                        <td class="px-6 py-4 font-medium text-gray-800 dark:text-slate-100">{{ area.nombre }}</td>
                        <td class="px-6 py-4 text-gray-500 dark:text-slate-400">
                            <span v-if="area.dias_gestion">{{ area.dias_gestion }} días hábiles</span>
                            <span v-else class="text-amber-600 dark:text-amber-400">sin plazo — no alerta</span>
                        </td>
                        <td class="px-6 py-4 text-gray-500 dark:text-slate-400">{{ area.usuarios_count ?? 0 }}</td>
                        <td class="px-6 py-4">
                            <Badge :variant="area.activo ? 'emerald' : 'slate'">
                                {{ area.activo ? 'Activa' : 'Inactiva' }}
                            </Badge>
                        </td>
                        <td class="px-6 py-4">
                            <button
                                v-if="hasPermission('users.edit')"
                                class="text-blue-600 dark:text-blue-400 hover:underline text-sm cursor-pointer"
                                @click="abrirEdicion(area)"
                            >
                                Editar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="areas.length === 0">
                        <td colspan="5" class="px-6 py-8 text-center text-gray-400 dark:text-slate-500">
                            Todavía no hay áreas. Se crean solas al importar el Excel de usuarios.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal
            :show="creando || editando !== null"
            :title="creando ? 'Nueva área' : 'Editar área'"
            @close="cerrar"
        >
            <form @submit.prevent="guardar" class="space-y-4">
                <Input v-model="form.nombre" label="Nombre" :error="form.errors.nombre" />

                <Input
                    v-model="form.dias_gestion"
                    type="number"
                    label="Plazo de gestión (días hábiles)"
                    :error="form.errors.dias_gestion"
                />
                <p class="text-xs text-gray-500 dark:text-slate-400 -mt-2">
                    Sin plazo, las observaciones de esta área nunca disparan alertas.
                </p>

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" v-model="form.activo" class="rounded" />
                    Activa
                </label>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Guardar</Button>
                    <button
                        type="button"
                        class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200"
                        @click="cerrar"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
