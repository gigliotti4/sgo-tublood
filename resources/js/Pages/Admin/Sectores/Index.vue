<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Icon from '@/Components/Icon.vue'
import Input from '@/Components/Input.vue'
import Modal from '@/Components/Modal.vue'
import TableCard from '@/Components/TableCard.vue'
import DataRow from '@/Components/DataRow.vue'
import type { Sector } from '@/types'

defineProps<{ sectores: Sector[] }>()

const { hasPermission } = usePermissions()

const editando = ref<Sector | null>(null)
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

const abrirEdicion = (sector: Sector) => {
    form.nombre = sector.nombre
    form.dias_gestion = sector.dias_gestion
    form.activo = sector.activo
    form.clearErrors()
    editando.value = sector
}

const cerrar = () => {
    editando.value = null
    creando.value = false
}

const guardar = () => {
    if (creando.value) {
        form.post(route('sectores.store'), { onSuccess: cerrar })

        return
    }

    if (editando.value) {
        form.put(route('sectores.update', editando.value.id), { onSuccess: cerrar })
    }
}
</script>

<template>
    <Head title="Sectores" />

    <AppLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Sectores</h1>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                    Dónde trabaja cada persona y a dónde se derivan las observaciones. El plazo de gestión es
                    el que arranca cuando se asigna un responsable de ese sector a una observación.
                </p>
            </div>
            <div class="flex gap-3">
                <Link
                    :href="route('users.index')"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                >
                    Usuarios
                </Link>
                <Button v-if="hasPermission('users.edit')" variant="primary" @click="abrirNueva">
                    Nuevo sector
                </Button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="hidden overflow-x-auto md:block">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Sector</th>
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Plazo de gestión</th>
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Usuarios</th>
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Estado</th>
                        <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <tr v-for="sector in sectores" :key="sector.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                        <td class="px-6 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ sector.nombre }}</td>
                        <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">
                            <span v-if="sector.dias_gestion">{{ sector.dias_gestion }} días hábiles</span>
                            <span v-else class="text-warning-600 dark:text-warning-400">sin plazo — no alerta</span>
                        </td>
                        <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ sector.usuarios_count ?? 0 }}</td>
                        <td class="px-6 py-3.5">
                            <Badge :variant="sector.activo ? 'emerald' : 'slate'">
                                {{ sector.activo ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </td>
                        <td class="px-6 py-3.5">
                            <button
                                v-if="hasPermission('users.edit')"
                                type="button"
                                class="cursor-pointer rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                title="Editar"
                                @click="abrirEdicion(sector)"
                            >
                                <Icon name="pencil" class="h-4.5 w-4.5" />
                                <span class="sr-only">Editar sector {{ sector.nombre }}</span>
                            </button>
                        </td>
                    </tr>
                    <tr v-if="sectores.length === 0">
                        <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-400">
                            Todavía no hay sectores.
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>

            <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
            <div v-if="sectores.length" class="space-y-3 p-4 md:hidden">
                <TableCard v-for="sector in sectores" :key="sector.id">
                    <template #header>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ sector.nombre }}</p>
                            <Badge :variant="sector.activo ? 'emerald' : 'slate'">{{ sector.activo ? 'Activo' : 'Inactivo' }}</Badge>
                        </div>
                    </template>
                    <template #actions>
                        <button
                            v-if="hasPermission('users.edit')"
                            type="button"
                            class="cursor-pointer rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                            title="Editar"
                            @click="abrirEdicion(sector)"
                        >
                            <Icon name="pencil" class="h-4.5 w-4.5" />
                            <span class="sr-only">Editar sector {{ sector.nombre }}</span>
                        </button>
                    </template>
                    <template #body>
                        <DataRow label="Plazo de gestión">
                            <span v-if="sector.dias_gestion">{{ sector.dias_gestion }} días hábiles</span>
                            <span v-else class="text-warning-600 dark:text-warning-400">sin plazo — no alerta</span>
                        </DataRow>
                        <DataRow label="Usuarios">{{ sector.usuarios_count ?? 0 }}</DataRow>
                    </template>
                </TableCard>
            </div>
            <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">Todavía no hay sectores.</p>
        </div>

        <Modal
            :show="creando || editando !== null"
            :title="creando ? 'Nuevo sector' : 'Editar sector'"
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
                <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Sin plazo, las observaciones de este sector nunca disparan alertas.
                </p>

                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                    <input type="checkbox" v-model="form.activo" class="h-4 w-4 rounded accent-brand-500 dark:accent-brand-400" />
                    Activo
                </label>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Guardar</Button>
                    <Button variant="outline" @click="cerrar">Cancelar</Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
