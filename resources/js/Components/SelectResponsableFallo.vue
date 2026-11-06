<script setup lang="ts">
import { computed } from 'vue'
import Select from '@/Components/Select.vue'
import type { Persona } from '@/types'

/**
 * "Responsable del fallo": quién, del sector que falló, cometió el error. No es
 * el responsable del caso (ése lo gestiona): es el que lo causó.
 *
 * ⚠️ Solo se monta para quien lo puede ver (jefes y Calidad): el backend ni
 * manda la lista a los demás.
 *
 * Las personas salen del personal por sector, **con o sin usuario del sistema**
 * (choferes, operarios, personal de laboratorio), agrupadas por sector. El
 * sector del caso va primero: es donde casi siempre está quien falló.
 */
const props = defineProps<{
    personas: Persona[]
    /** Sector de la observación, para ofrecer primero a su gente. */
    sectorId?: number | null
    error?: string
    hint?: string
}>()

const model = defineModel<number | null>()

const grupos = computed(() => {
    const porSector = new Map<string, { sectorId: number | null; personas: Persona[] }>()

    for (const p of props.personas) {
        const nombre = p.sector?.nombre ?? 'Sin sector'

        if (!porSector.has(nombre)) porSector.set(nombre, { sectorId: p.sector_id, personas: [] })
        porSector.get(nombre)!.personas.push(p)
    }

    return [...porSector.entries()]
        .map(([nombre, g]) => ({ nombre, ...g }))
        // El sector del caso primero; el resto, A→Z.
        .sort((a, b) => Number(b.sectorId === props.sectorId) - Number(a.sectorId === props.sectorId) || a.nombre.localeCompare(b.nombre, 'es'))
})

/** Una persona ya elegida que después pasó a inactiva no está en la lista: se mantiene visible. */
const faltante = computed(() =>
    model.value !== null && model.value !== undefined && !props.personas.some(p => p.id === model.value),
)
</script>

<template>
    <Select
        v-model="model"
        label="Responsable del fallo"
        :hint="hint ?? 'Quién, del sector que falló, cometió el error. Solo lo ven los jefes y Calidad.'"
        :error="error"
    >
        <option :value="null">— Sin cargar —</option>
        <option v-if="faltante" :value="model">(persona inactiva)</option>
        <optgroup v-for="g in grupos" :key="g.nombre" :label="g.nombre">
            <option v-for="p in g.personas" :key="p.id" :value="p.id">
                {{ p.nombre }}{{ p.puesto ? ` · ${p.puesto}` : '' }}
            </option>
        </optgroup>
    </Select>
</template>
