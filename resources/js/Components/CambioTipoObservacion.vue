<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Button from '@/Components/Button.vue'
import CampoDinamico, { campoVisible, type CampoDef } from '@/Components/CampoDinamico.vue'
import Select from '@/Components/Select.vue'
import type { Observacion } from '@/types'

/**
 * Cambiar el tipo de incidencia de una observación (y con él su sector).
 *
 * Lo comparten el modal de edición del listado (lo abre el responsable) y la
 * ficha de detalle (lo abre también Calidad, que no gestiona el caso y por eso
 * no llega al modal). La habilidad real la chequea el backend
 * (`ObservacionPolicy::cambiarTipo`); acá solo se decide si se muestra.
 *
 * Al elegir el tipo nuevo se piden sus "Datos específicos", precargados con lo
 * que coincida por clave. Lo que no tenga lugar en el tipo nuevo se avisa antes
 * de guardar, y el cambio deja lo anterior entero en la bitácora.
 */
interface TipoDef { codigo: string; label: string; campos?: CampoDef[]; especial?: boolean; grupo?: string; obsoleto?: boolean }
interface SectorOption { id: number; nombre: string; slug: string }

const props = defineProps<{
    observacion: Observacion
    taxonomia: Record<string, Record<string, TipoDef>>
    sectores: SectorOption[]
}>()

const emit = defineEmits<{ cerrar: [] }>()

const form = useForm({
    sector_id: props.observacion.sector_id as number | null,
    tipo: props.observacion.tipo,
    datos_especificos: { ...(props.observacion.datos_especificos ?? {}) } as Record<string, string | number | null>,
})

const slugDeSector = (id: number | null) => props.sectores.find(s => s.id === id)?.slug ?? ''

const defDelTipo = (slug: string, tipo: string): TipoDef | undefined => props.taxonomia[slug]?.[tipo]

/** El tipo actual, con su sector: no se sabe por la observación, hay que buscarlo en la taxonomía. */
const tipoActual = computed<TipoDef | undefined>(() =>
    Object.values(props.taxonomia).map(tipos => tipos[props.observacion.tipo]).find(Boolean),
)

// Cualquier tipo de cualquier sector: el cliente puede cargar un reclamo en el
// tipo equivocado y el que falló fue otro sector. Los retirados (`obsoleto`) no
// se ofrecen, salvo el que la observación ya tiene.
const tiposDisponibles = computed(() =>
    Object.entries(props.taxonomia[slugDeSector(form.sector_id)] ?? {})
        .filter(([key, def]) => def.obsoleto !== true || key === props.observacion.tipo)
        .map(([key, def]) => ({ key, label: `${def.codigo} ${def.label}`, grupo: def.grupo })),
)

const camposDelTipoNuevo = computed<CampoDef[]>(() => {
    const def = defDelTipo(slugDeSector(form.sector_id), form.tipo)

    // Los tipos especiales (1.1, 1.2) no se completan acá: sus campos los pide la
    // carga interna, y este formulario no los guarda.
    return def?.especial ? [] : (def?.campos ?? [])
})

/** Los que corresponde mostrar ahora (algunos aparecen según lo elegido en otro). */
const camposVisibles = computed(() => camposDelTipoNuevo.value.filter(c => campoVisible(c, form.datos_especificos)))

// Al cambiar de sector el tipo deja de valer; al cambiar de tipo se rearman sus
// campos conservando los valores que tengan la misma clave.
watch(() => form.sector_id, (nuevo, viejo) => {
    if (nuevo !== viejo && !tiposDisponibles.value.some(t => t.key === form.tipo)) form.tipo = ''
})
watch(() => form.tipo, () => {
    const previos = form.datos_especificos
    form.datos_especificos = Object.fromEntries(camposDelTipoNuevo.value.map(c => [c.id, previos[c.id] ?? '']))
})

/**
 * Datos cargados que el tipo nuevo no tiene dónde mostrar.
 *
 * Entre dos tipos especiales se conservan tal cual; al entrar a uno desde una
 * incidencia interna se vacían (esos tipos no tienen "Datos específicos").
 */
const perdidos = computed<string[]>(() => {
    if (!form.tipo || form.tipo === props.observacion.tipo) return []

    const original = props.observacion.datos_especificos ?? {}
    const destino = defDelTipo(slugDeSector(form.sector_id), form.tipo)
    const eraEspecial = tipoActual.value?.especial === true

    const conservados = destino?.especial
        ? (eraEspecial ? Object.keys(original) : [])
        : (destino?.campos ?? []).map(c => c.id)

    const etiqueta = (clave: string) => tipoActual.value?.campos?.find(c => c.id === clave)?.label ?? clave

    return Object.entries(original)
        .filter(([clave, valor]) => valor !== null && valor !== '' && !conservados.includes(clave))
        .map(([clave]) => etiqueta(clave))
})

const guardar = () => {
    form.put(route('observaciones.tipo', { observacion: props.observacion.id }), {
        preserveScroll: true,
        onSuccess: () => emit('cerrar'),
    })
}
</script>

<template>
    <!-- Cambio de tipo: los datos que se reemplazan quedan en la bitácora. -->
    <form class="space-y-3 rounded-xl border border-warning-200 p-4 dark:border-warning-500/30" @submit.prevent="guardar">
        <p class="rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            Cambiar el tipo reencuadra el caso y reemplaza sus datos específicos. Los anteriores quedan en la bitácora.
            Sigue figurando como {{ observacion.origen === 'externa' ? 'reclamo de cliente' : 'incidencia interna' }}.
        </p>
        <div class="grid gap-3 sm:grid-cols-2">
            <Select v-model="form.sector_id" label="Sector" required :error="form.errors.sector_id">
                <option v-for="s in sectores" :key="s.id" :value="s.id">{{ s.nombre }}</option>
            </Select>
            <Select v-model="form.tipo" label="Tipo de incidencia" required :error="form.errors.tipo">
                <option value="" disabled>— Seleccionar —</option>
                <option v-for="t in tiposDisponibles" :key="t.key" :value="t.key">
                    {{ t.label }}{{ t.grupo ? ` (${t.grupo})` : '' }}
                </option>
            </Select>
            <CampoDinamico
                v-for="campo in camposVisibles"
                :key="campo.id"
                v-model="form.datos_especificos[campo.id]"
                :campo="campo"
                :error="(form.errors as Record<string, string>)[`datos_especificos.${campo.id}`]"
            />
        </div>

        <p
            v-if="perdidos.length"
            class="rounded-lg bg-error-50 px-3 py-2 text-theme-xs text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
            El tipo nuevo no tiene lugar para: <span class="font-medium">{{ perdidos.join(', ') }}</span>.
            Se van a sacar de la ficha (quedan guardados en la bitácora).
        </p>

        <div class="flex justify-end gap-2">
            <Button variant="outline" type="button" @click="emit('cerrar')">Cancelar</Button>
            <Button type="submit" :disabled="form.processing || !form.tipo">
                {{ form.processing ? 'Guardando...' : 'Cambiar tipo' }}
            </Button>
        </div>
    </form>
</template>
