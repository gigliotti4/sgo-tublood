<script setup lang="ts">
import { computed, ref } from 'vue'
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
import ThOrdenable from '@/Components/ThOrdenable.vue'
import type { Sector } from '@/types'

const props = defineProps<{ sectores: Sector[] }>()

// ── Ordenamiento, en el cliente ─────────────────────────────────────
//
// Y no en el servidor como los otros listados: son 10 filas fijas que ya viajan
// enteras (`SectorController::index()` hace `->get()`, sin paginar), sin
// filtros ni URL que preservar. Un roundtrip por click sería el único del
// panel que recarga una tabla que ya está en memoria. Mismo criterio que
// Compras. El `<th>` sí se comparte: `ThOrdenable` no sabe de Inertia.

type ClaveOrden = 'sector' | 'plazo' | 'tope' | 'usuarios' | 'estado'

const orden = ref<{ sort: ClaveOrden | null; dir: 'asc' | 'desc' }>({ sort: 'sector', dir: 'asc' })

const ordenarPor = (campo: string) => {
    const clave = campo as ClaveOrden

    orden.value = orden.value.sort === clave
        ? { sort: clave, dir: orden.value.dir === 'asc' ? 'desc' : 'asc' }
        // Texto A→Z; los números, de mayor a menor.
        : { sort: clave, dir: clave === 'sector' ? 'asc' : 'desc' }
}

const valorDeOrden = (s: Sector, clave: ClaveOrden): string | number => {
    switch (clave) {
        case 'sector': return s.nombre
        // Sin plazo cargado, las observaciones de ese sector nunca alertan: va
        // al fondo en vez de mezclarse con los plazos cortos.
        case 'plazo': return s.dias_gestion ?? Number.MAX_VALUE
        // Mismo criterio: sin tope cargado, ese sector nunca avisa por
        // saturación, así que va al fondo y no entre los topes chicos.
        case 'tope': return s.tope_observaciones ?? Number.MAX_VALUE
        case 'usuarios': return s.usuarios_count ?? 0
        case 'estado': return s.activo ? 1 : 0
    }
}

const sectoresOrdenados = computed(() => {
    const { sort, dir } = orden.value
    if (!sort) return props.sectores

    return props.sectores.slice().sort((a, b) => {
        const va = valorDeOrden(a, sort)
        const vb = valorDeOrden(b, sort)

        if (typeof va === 'string' && typeof vb === 'string') {
            const c = va.localeCompare(vb, 'es')

            return dir === 'asc' ? c : -c
        }

        return dir === 'asc' ? (va as number) - (vb as number) : (vb as number) - (va as number)
    })
})

const { hasPermission } = usePermissions()

const editando = ref<Sector | null>(null)
const creando = ref(false)

const form = useForm({
    nombre: '',
    dias_gestion: null as number | null,
    tope_observaciones: null as number | null,
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
    form.tope_observaciones = sector.tope_observaciones
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
                        <ThOrdenable campo="sector" :orden="orden" pad="px-6" @ordenar="ordenarPor">Sector</ThOrdenable>
                        <ThOrdenable campo="plazo" :orden="orden" pad="px-6" @ordenar="ordenarPor">Plazo de gestión</ThOrdenable>
                        <ThOrdenable campo="tope" :orden="orden" pad="px-6" @ordenar="ordenarPor">Tope de abiertas</ThOrdenable>
                        <ThOrdenable campo="usuarios" :orden="orden" pad="px-6" @ordenar="ordenarPor">Usuarios</ThOrdenable>
                        <ThOrdenable campo="estado" :orden="orden" pad="px-6" @ordenar="ordenarPor">Estado</ThOrdenable>
                        <ThOrdenable :orden="orden" pad="px-6">Acciones</ThOrdenable>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <tr v-for="sector in sectoresOrdenados" :key="sector.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                        <td class="px-6 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ sector.nombre }}</td>
                        <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">
                            <span v-if="sector.dias_gestion">{{ sector.dias_gestion }} días hábiles</span>
                            <span v-else class="text-warning-600 dark:text-warning-400">sin plazo — no alerta</span>
                        </td>
                        <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">
                            <span v-if="sector.tope_observaciones">{{ sector.tope_observaciones }} abiertas</span>
                            <span v-else class="text-gray-400">sin tope</span>
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
                        <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-400">
                            Todavía no hay sectores.
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>

            <!-- Cards: mismos datos que la tabla, en formato de lista para mobile -->
            <div v-if="sectores.length" class="space-y-3 p-4 md:hidden">
                <TableCard v-for="sector in sectoresOrdenados" :key="sector.id">
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
                        <DataRow label="Tope de abiertas">
                            <span v-if="sector.tope_observaciones">{{ sector.tope_observaciones }} abiertas</span>
                            <span v-else class="text-gray-400">sin tope</span>
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

                <Input
                    v-model="form.tope_observaciones"
                    type="number"
                    label="Tope de observaciones abiertas"
                    :error="form.errors.tope_observaciones"
                />
                <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Al superarlo se le avisa al gerente del sector, una vez por cada episodio de
                    saturación. Sin tope, este sector nunca avisa.
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
