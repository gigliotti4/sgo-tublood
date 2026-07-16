<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import CampoDinamico, { type CampoDef } from '@/Components/CampoDinamico.vue'

interface SectorOption { id: number; nombre: string; slug: string }
interface UsuarioOption { id: number; name: string }
interface TipoDef { codigo: string; label: string; campos: CampoDef[] }
type Taxonomia = Record<string, Record<string, TipoDef>>

const props = defineProps<{
    sectores: SectorOption[]
    taxonomia: Taxonomia
    prioridades: Record<string, string>
    tiposCaso: string[]
    prioridadSugerida: Record<string, string>
    usuarios: UsuarioOption[]
}>()

const form = useForm({
    origen: 'interna',
    sector_id: null as number | null,
    tipo: '',
    prioridad: '',
    tipo_caso: '',
    titulo: '',
    descripcion: '',
    responsable_id: null as number | null,
    datos_especificos: {} as Record<string, string | number | null>,
    attachments: [] as File[],
})

const sectorSlug = computed(() => props.sectores.find(s => s.id === form.sector_id)?.slug ?? null)

const tiposDelSector = computed(() => {
    if (!sectorSlug.value) return []
    const tipos = props.taxonomia[sectorSlug.value] ?? {}
    return Object.entries(tipos).map(([key, def]) => ({ key, label: `${def.codigo} ${def.label}` }))
})

const camposDelTipo = computed<CampoDef[]>(() => {
    if (!sectorSlug.value || !form.tipo) return []
    return props.taxonomia[sectorSlug.value]?.[form.tipo]?.campos ?? []
})

// Al cambiar de sector: reseteá el tipo y los datos específicos.
watch(() => form.sector_id, () => {
    form.tipo = ''
    form.datos_especificos = {}
})

// Al cambiar de tipo: reconstruí las claves de datos específicos.
watch(() => form.tipo, () => {
    const nuevos: Record<string, string | number | null> = {}
    for (const c of camposDelTipo.value) nuevos[c.id] = ''
    form.datos_especificos = nuevos
})

// Sugerencia de prioridad según tipo de caso.
const sugerirPrioridad = () => {
    const sug = props.prioridadSugerida[form.tipo_caso]
    if (sug) form.prioridad = sug
}

const errorCampo = (id: string) =>
    (form.errors as Record<string, string>)[`datos_especificos.${id}`]

const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const addFiles = (files: FileList | null) => {
    if (!files) return
    form.attachments = [...form.attachments, ...Array.from(files)]
}
const onDrop = (e: DragEvent) => {
    isDragging.value = false
    addFiles(e.dataTransfer?.files ?? null)
}
const removeFile = (index: number) => {
    form.attachments = form.attachments.filter((_, i) => i !== index)
}

const submit = () => form.post(route('observaciones.store'), { forceFormData: true })
</script>

<template>
    <Head title="Nueva observación interna" />

    <AppLayout>
        <div class="max-w-xxl mx-auto">
            <div class="mb-6">
                <h1 class="text-xl font-bold text-slate-800">Nueva observación interna</h1>
                <p class="text-sm text-slate-500 mt-0.5">Registro por sector</p>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-2xl shadow p-6 sm:p-8 space-y-8">
                <!-- Clasificación -->
                <section class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Clasificación</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="sector_id" class="block text-sm font-medium text-slate-700">
                                Sector <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="sector_id"
                                v-model="form.sector_id"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.sector_id ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            >
                                <option :value="null" disabled>— Seleccionar —</option>
                                <option v-for="s in props.sectores" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                            </select>
                            <p v-if="form.errors.sector_id" class="text-red-500 text-xs">{{ form.errors.sector_id }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="tipo" class="block text-sm font-medium text-slate-700">
                                Tipo de incidencia <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="tipo"
                                v-model="form.tipo"
                                :disabled="!form.sector_id"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent disabled:bg-slate-50 disabled:text-slate-400"
                                :class="form.errors.tipo ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            >
                                <option value="" disabled>{{ form.sector_id ? '— Seleccionar —' : 'Elegí un sector primero' }}</option>
                                <option v-for="t in tiposDelSector" :key="t.key" :value="t.key">{{ t.label }}</option>
                            </select>
                            <p v-if="form.errors.tipo" class="text-red-500 text-xs">{{ form.errors.tipo }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="prioridad" class="block text-sm font-medium text-slate-700">
                                Prioridad <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="prioridad"
                                v-model="form.prioridad"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.prioridad ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            >
                                <option value="" disabled>— Seleccionar —</option>
                                <option v-for="(label, key) in props.prioridades" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <p v-if="form.errors.prioridad" class="text-red-500 text-xs">{{ form.errors.prioridad }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="tipo_caso" class="block text-sm font-medium text-slate-700">
                                Tipo de caso <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="tipo_caso"
                                v-model="form.tipo_caso"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.tipo_caso ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                                @change="sugerirPrioridad"
                            >
                                <option value="" disabled>— Seleccionar —</option>
                                <option v-for="tc in props.tiposCaso" :key="tc" :value="tc">{{ tc }}</option>
                            </select>
                            <p v-if="form.errors.tipo_caso" class="text-red-500 text-xs">{{ form.errors.tipo_caso }}</p>
                        </div>
                    </div>
                </section>

                <!-- Datos del reporte -->
                <section class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Datos del reporte</h2>
                    <div class="space-y-1.5">
                        <label for="titulo" class="block text-sm font-medium text-slate-700">
                            Título breve <span class="text-red-500">*</span>
                        </label>
                        <input
                            id="titulo"
                            v-model="form.titulo"
                            type="text"
                            maxlength="120"
                            class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            :class="form.errors.titulo ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                        />
                        <p v-if="form.errors.titulo" class="text-red-500 text-xs">{{ form.errors.titulo }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <label for="descripcion" class="block text-sm font-medium text-slate-700">
                            Descripción detallada <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            id="descripcion"
                            v-model="form.descripcion"
                            rows="4"
                            class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            :class="form.errors.descripcion ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                        />
                        <p v-if="form.errors.descripcion" class="text-red-500 text-xs">{{ form.errors.descripcion }}</p>
                    </div>
                </section>

                <!-- Datos específicos (dinámico según tipo) -->
                <section v-if="camposDelTipo.length" class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Datos específicos</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <CampoDinamico
                            v-for="campo in camposDelTipo"
                            :key="campo.id"
                            v-model="form.datos_especificos[campo.id]"
                            :campo="campo"
                            :error="errorCampo(campo.id)"
                        />
                    </div>
                </section>

                <!-- Asignación -->
                <section class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Asignación</h2>
                    <div class="space-y-1.5 sm:w-1/2">
                        <label for="responsable_id" class="block text-sm font-medium text-slate-700">Responsable</label>
                        <select
                            id="responsable_id"
                            v-model="form.responsable_id"
                            class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            :class="form.errors.responsable_id ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                        >
                            <option :value="null">— Sin asignar —</option>
                            <option v-for="u in props.usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <p v-if="form.errors.responsable_id" class="text-red-500 text-xs">{{ form.errors.responsable_id }}</p>
                    </div>
                </section>

                <!-- Adjuntos -->
                <section class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Adjuntos</h2>
                    <div
                        class="border-2 border-dashed rounded-xl p-8 text-center transition"
                        :class="isDragging ? 'border-indigo-400 bg-indigo-50/50' : 'border-slate-200'"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="onDrop"
                    >
                        <svg class="w-6 h-6 mx-auto text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.485 8.486L20.5 13"/>
                        </svg>
                        <p class="text-sm font-medium text-slate-700 mt-2">Adjuntar archivos</p>
                        <p class="text-xs text-slate-400 mt-1">JPG, PNG, PDF. Máx 3 MB</p>
                        <button
                            type="button"
                            class="mt-3 text-sm text-indigo-600 hover:text-indigo-700 font-medium"
                            @click="fileInput?.click()"
                        >
                            Seleccionar archivos
                        </button>
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf"
                            class="hidden"
                            @change="addFiles(($event.target as HTMLInputElement).files)"
                        />
                    </div>

                    <ul v-if="form.attachments.length" class="space-y-1.5">
                        <li
                            v-for="(file, index) in form.attachments"
                            :key="index"
                            class="flex items-center justify-between text-sm bg-slate-50 rounded-lg px-3 py-2"
                        >
                            <span class="text-slate-700 truncate">{{ file.name }}</span>
                            <button type="button" class="text-slate-400 hover:text-red-500" @click="removeFile(index)">✕</button>
                        </li>
                    </ul>
                    <p v-if="form.errors.attachments" class="text-red-500 text-xs">{{ form.errors.attachments }}</p>
                </section>

                <!-- Acciones -->
                <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                    <Link
                        :href="route('observaciones.nuevo')"
                        class="px-4 py-2.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition"
                    >
                        ← Volver
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed transition"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar observación' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
