<script setup lang="ts">
import { computed, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import DataRow from '@/Components/DataRow.vue'
import Icon from '@/Components/Icon.vue'
import Input from '@/Components/Input.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import TableCard from '@/Components/TableCard.vue'
import type { Persona } from '@/types'

/**
 * Personal por sector, con o sin usuario del SGO.
 *
 * Alimenta el desplegable "Responsable del fallo": en Logística, Depósito y
 * Calidad hay gente (choferes, operarios, personal de laboratorio) que no usa el
 * sistema y por eso no aparece en ninguna lista. Se carga acá, sin usuario ni
 * contraseña.
 *
 * Los que dejan la empresa se marcan inactivos: no se ofrecen para elegir pero
 * se conservan en lo que ya tenían. Por eso no hay botón de borrar.
 */
const props = defineProps<{
    personas: Persona[]
    sectores: { id: number; nombre: string }[]
    usuarios: { id: number; name: string; apellido: string | null }[]
}>()

const { hasPermission } = usePermissions()

// Filtra en el cliente, como Sectores: la lista ya viaja entera.
const buscar = ref('')
const sectorFiltro = ref<number | ''>('')
const verInactivos = ref(false)

const filtradas = computed(() => {
    const q = buscar.value.trim().toLowerCase()

    return props.personas.filter(p =>
        (verInactivos.value || p.activo)
        && (sectorFiltro.value === '' || p.sector_id === sectorFiltro.value)
        && (q === '' || `${p.nombre} ${p.puesto ?? ''}`.toLowerCase().includes(q)),
    )
})

const nombreUsuario = (u: Persona['usuario']) => u ? [u.name, u.apellido].filter(Boolean).join(' ') : null

const editando = ref<Persona | null>(null)
const creando = ref(false)

const form = useForm({
    nombre: '',
    sector_id: null as number | null,
    puesto: '',
    activo: true,
    user_id: null as number | null,
})

const abrirNueva = () => {
    form.reset()
    form.clearErrors()
    // Si ya se filtró por un sector, se carga en ese: es lo más probable.
    if (sectorFiltro.value !== '') form.sector_id = sectorFiltro.value
    creando.value = true
}

const abrirEdicion = (p: Persona) => {
    form.nombre = p.nombre
    form.sector_id = p.sector_id
    form.puesto = p.puesto ?? ''
    form.activo = p.activo
    form.user_id = p.user_id
    form.clearErrors()
    editando.value = p
}

const cerrar = () => {
    editando.value = null
    creando.value = false
}

const guardar = () => {
    if (creando.value) {
        form.post(route('personas.store'), { onSuccess: cerrar })

        return
    }

    if (editando.value) {
        form.put(route('personas.update', editando.value.id), { onSuccess: cerrar })
    }
}
</script>

<template>
    <Head title="Personal por sector" />

    <AppLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Personal por sector</h1>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                    Las personas de cada sector, tengan o no usuario del sistema. Alimenta el desplegable
                    «Responsable del fallo» de las observaciones. Quien deja la empresa se marca inactivo: no
                    se ofrece más, pero se conserva en lo que ya tenía.
                </p>
            </div>
            <div class="flex gap-3">
                <Link
                    :href="route('sectores.index')"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                >
                    Sectores
                </Link>
                <Button v-if="hasPermission('users.edit')" variant="primary" @click="abrirNueva">
                    Nueva persona
                </Button>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <Input v-model="buscar" label="Buscar" placeholder="Nombre o puesto" />
            <Select v-model="sectorFiltro" label="Sector">
                <option value="">Todos</option>
                <option v-for="s in sectores" :key="s.id" :value="s.id">{{ s.nombre }}</option>
            </Select>
            <label class="flex cursor-pointer items-end gap-2 pb-2.5 text-sm font-medium text-gray-700 dark:text-gray-300">
                <input type="checkbox" v-model="verInactivos" class="h-4 w-4 rounded accent-brand-500 dark:accent-brand-400" />
                Mostrar inactivos
            </label>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-theme-xs uppercase tracking-wide text-gray-400 dark:border-gray-800">
                            <th class="px-6 py-3 font-medium">Nombre</th>
                            <th class="px-6 py-3 font-medium">Sector</th>
                            <th class="px-6 py-3 font-medium">Puesto</th>
                            <th class="px-6 py-3 font-medium">Usuario del sistema</th>
                            <th class="px-6 py-3 font-medium">Casos</th>
                            <th class="px-6 py-3 font-medium">Estado</th>
                            <th class="px-6 py-3 font-medium">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="p in filtradas" :key="p.id" class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <td class="px-6 py-3.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ p.nombre }}</td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ p.sector?.nombre ?? '—' }}</td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ p.puesto ?? '—' }}</td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">
                                <span v-if="p.usuario">{{ nombreUsuario(p.usuario) }}</span>
                                <span v-else class="text-gray-400">sin usuario</span>
                            </td>
                            <td class="px-6 py-3.5 text-theme-sm text-gray-500 dark:text-gray-400">{{ p.observaciones_con_fallo_count ?? 0 }}</td>
                            <td class="px-6 py-3.5">
                                <Badge :variant="p.activo ? 'emerald' : 'slate'">{{ p.activo ? 'Activo' : 'Inactivo' }}</Badge>
                            </td>
                            <td class="px-6 py-3.5">
                                <button
                                    v-if="hasPermission('users.edit')"
                                    type="button"
                                    class="cursor-pointer rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                    title="Editar"
                                    @click="abrirEdicion(p)"
                                >
                                    <Icon name="pencil" class="h-4.5 w-4.5" />
                                    <span class="sr-only">Editar a {{ p.nombre }}</span>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="filtradas.length === 0">
                            <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-400">
                                {{ personas.length === 0 ? 'Todavía no se cargó a nadie.' : 'Nadie coincide con los filtros.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="filtradas.length" class="space-y-3 p-4 md:hidden">
                <TableCard v-for="p in filtradas" :key="p.id">
                    <template #header>
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ p.nombre }}</p>
                            <Badge :variant="p.activo ? 'emerald' : 'slate'">{{ p.activo ? 'Activo' : 'Inactivo' }}</Badge>
                        </div>
                    </template>
                    <template #actions>
                        <button
                            v-if="hasPermission('users.edit')"
                            type="button"
                            class="cursor-pointer rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                            title="Editar"
                            @click="abrirEdicion(p)"
                        >
                            <Icon name="pencil" class="h-4.5 w-4.5" />
                            <span class="sr-only">Editar a {{ p.nombre }}</span>
                        </button>
                    </template>
                    <template #body>
                        <DataRow label="Sector">{{ p.sector?.nombre ?? '—' }}</DataRow>
                        <DataRow label="Puesto">{{ p.puesto ?? '—' }}</DataRow>
                        <DataRow label="Usuario">{{ nombreUsuario(p.usuario) ?? 'sin usuario' }}</DataRow>
                    </template>
                </TableCard>
            </div>
            <p v-else class="p-4 text-center text-sm text-gray-400 md:hidden">
                {{ personas.length === 0 ? 'Todavía no se cargó a nadie.' : 'Nadie coincide con los filtros.' }}
            </p>
        </div>

        <Modal :show="creando || editando !== null" :title="creando ? 'Nueva persona' : 'Editar persona'" @close="cerrar">
            <form class="space-y-4" @submit.prevent="guardar">
                <Input v-model="form.nombre" label="Nombre" required :error="form.errors.nombre" />

                <Select v-model="form.sector_id" label="Sector" required :error="form.errors.sector_id">
                    <option :value="null" disabled>— Seleccionar —</option>
                    <option v-for="s in sectores" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                </Select>

                <Input v-model="form.puesto" label="Puesto" hint="Ej.: chofer, operario de depósito, analista." :error="form.errors.puesto" />

                <Select
                    v-model="form.user_id"
                    label="Usuario del sistema"
                    hint="Solo si ya tiene usuario: así no queda cargado dos veces."
                    :error="form.errors.user_id"
                >
                    <option :value="null">— Sin usuario —</option>
                    <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ [u.name, u.apellido].filter(Boolean).join(' ') }}</option>
                </Select>

                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                    <input type="checkbox" v-model="form.activo" class="h-4 w-4 rounded accent-brand-500 dark:accent-brand-400" />
                    Activo
                </label>
                <p class="-mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Desmarcá «Activo» cuando deje la empresa: no se ofrece más, pero se conserva en lo que ya tenía.
                </p>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Guardar</Button>
                    <Button variant="outline" type="button" @click="cerrar">Cancelar</Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
