<script setup lang="ts">
import { computed } from 'vue'
import type { InertiaForm } from '@inertiajs/vue3'
import FormSection from '@/Components/FormSection.vue'
import Input from '@/Components/Input.vue'
import Select from '@/Components/Select.vue'

export interface UserFormData {
    name: string
    apellido: string
    email: string
    password: string
    sector_id: number | null
    supervisor_id: number | null
    gerente_id: number | null
    es_gerente: boolean
    roles: string[]
}

export interface RoleOption { id: number; name: string }
export interface SectorOption { id: number; nombre: string; dias_gestion: number | null }
export interface UsuarioOption { id: number; name: string; apellido: string | null; es_gerente: boolean }

/**
 * Campos compartidos por el alta y la edición de usuarios: son los mismos, y
 * lo único que cambia es si la contraseña es obligatoria.
 */
const props = defineProps<{
    form: InertiaForm<UserFormData>
    roles: RoleOption[]
    sectores: SectorOption[]
    usuarios: UsuarioOption[]
    modo: 'crear' | 'editar'
}>()

const nombreCompleto = (u: UsuarioOption) => [u.name, u.apellido].filter(Boolean).join(' ')

// El gerente casi siempre es uno de los tres marcados como tales; se muestran
// primero para no tener que buscarlos entre los veinte usuarios.
const gerentes = computed(() => props.usuarios.filter(u => u.es_gerente))
const noGerentes = computed(() => props.usuarios.filter(u => !u.es_gerente))

/** El plazo de gestión es del sector, y de él dependen las alertas del usuario. */
const plazoDelSector = computed(() => {
    const sector = props.sectores.find(s => s.id === props.form.sector_id)
    if (!sector) return 'Sin sector, las observaciones de esta persona no generan alertas.'

    return sector.dias_gestion
        ? `Sus observaciones vencen a los ${sector.dias_gestion} días hábiles.`
        : `El sector ${sector.nombre} no tiene plazo cargado: no va a generar alertas.`
})
</script>

<template>
    <FormSection title="Datos personales">
        <Input v-model="form.name" required label="Nombre" :error="form.errors.name" />
        <Input v-model="form.apellido" label="Apellido" :error="form.errors.apellido" />
        <Input v-model="form.email" required type="email" label="Email" :error="form.errors.email" />

        <Input
            v-model="form.password"
            type="password"
            :required="modo === 'crear'"
            :error="form.errors.password"
            :hint="modo === 'editar' ? 'Dejala vacía para no cambiarla.' : undefined"
        >
            <template #label>{{ modo === 'crear' ? 'Contraseña' : 'Nueva contraseña' }}</template>
        </Input>
    </FormSection>

    <FormSection
        title="Organización"
        description="Define el plazo de gestión y a quién se le escala si una observación se vence."
    >
        <Select v-model="form.sector_id" label="Sector" :hint="plazoDelSector" :error="form.errors.sector_id">
            <option :value="null">— Sin sector —</option>
            <option v-for="sector in sectores" :key="sector.id" :value="sector.id">{{ sector.nombre }}</option>
        </Select>

        <Select
            v-model="form.supervisor_id"
            label="Supervisor"
            hint="Recibe el aviso si esta persona no gestiona a tiempo."
            :error="form.errors.supervisor_id"
        >
            <option :value="null">— Sin supervisor —</option>
            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ nombreCompleto(u) }}</option>
        </Select>

        <Select
            v-model="form.gerente_id"
            label="Gerente"
            hint="Recibe el aviso al cerrarse la observación y el último escalamiento."
            :error="form.errors.gerente_id"
        >
            <option :value="null">— Sin gerente —</option>
            <optgroup v-if="gerentes.length" label="Gerentes">
                <option v-for="u in gerentes" :key="u.id" :value="u.id">{{ nombreCompleto(u) }}</option>
            </optgroup>
            <optgroup v-if="noGerentes.length" label="Otros usuarios">
                <option v-for="u in noGerentes" :key="u.id" :value="u.id">{{ nombreCompleto(u) }}</option>
            </optgroup>
        </Select>

        <label class="flex items-start gap-2 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 sm:pt-7">
            <input type="checkbox" v-model="form.es_gerente" class="mt-0.5 h-4 w-4 rounded accent-brand-500 dark:accent-brand-400" />
            <span>
                Es gerente
                <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">
                    Aparece primero en la lista de gerentes de los demás usuarios.
                </span>
            </span>
        </label>
    </FormSection>

    <FormSection title="Acceso" :columns="1">
        <div>
            <div class="flex flex-wrap gap-x-6 gap-y-2">
                <label
                    v-for="role in roles"
                    :key="role.id"
                    class="flex cursor-pointer items-center gap-2 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    <input type="checkbox" :value="role.name" v-model="form.roles" class="h-4 w-4 rounded accent-brand-500 dark:accent-brand-400" />
                    {{ role.name }}
                </label>
            </div>
            <p v-if="form.errors.roles" class="mt-1.5 text-xs text-error-500 dark:text-error-400">{{ form.errors.roles }}</p>
        </div>
    </FormSection>
</template>
