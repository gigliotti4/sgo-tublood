<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import CampoDinamico, { type CampoDef } from '@/Components/CampoDinamico.vue'
import FormSection from '@/Components/FormSection.vue'
import Input from '@/Components/Input.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'

interface SectorOption { id: number; nombre: string; slug: string }
interface AreaOption { id: number; nombre: string }
interface UsuarioOption {
    id: number
    name: string
    apellido: string | null
    area_id: number | null
    area: { nombre: string; dias_gestion: number | null } | null
}
interface TipoDef { codigo: string; label: string; campos?: CampoDef[]; especial?: boolean }
type Taxonomia = Record<string, Record<string, TipoDef>>

interface ProductoForm {
    producto: string
    codigo: string
    cantidad_afectada: number | null
    lote: string
    fecha_vencimiento: string
    numero_remito: string
    tipo_comprobante: string
}

const props = defineProps<{
    sectores: SectorOption[]
    taxonomia: Taxonomia
    provincias: string[]
    prioridades: Record<string, string>
    tiposCaso: string[]
    prioridadSugerida: Record<string, string>
    usuarios: UsuarioOption[]
    areas: AreaOption[]
}>()

const nuevoProducto = (): ProductoForm => ({
    producto: '',
    codigo: '',
    cantidad_afectada: null,
    lote: '',
    fecha_vencimiento: '',
    numero_remito: '',
    tipo_comprobante: '',
})

const form = useForm({
    origen: 'interna',
    sector_id: null as number | null,
    tipo: '',
    prioridad: '',
    tipo_caso: '',
    titulo: '',
    descripcion: '',
    responsable_id: null as number | null,
    area_id: null as number | null,
    datos_especificos: {} as Record<string, string | number | null>,
    institucion: '',
    provincia: '',
    equipamiento: '',
    ejecutivo_cuenta: '',
    productos: [] as ProductoForm[],
    attachments: [] as File[],
})

const sectorSlug = computed(() => props.sectores.find(s => s.id === form.sector_id)?.slug ?? null)

/**
 * Los tipos del sector, separados en dos grupos: los "especiales" son reclamos
 * de cliente (el canal externo del portal público, que también se puede cargar a
 * mano acá) y el resto son incidencias propias del sector. Se muestran como dos
 * <optgroup> para que se lea qué se está cargando.
 */
const gruposDeTipos = computed(() => {
    const tipos = Object.entries(props.taxonomia[sectorSlug.value ?? ''] ?? {})
        .map(([key, def]) => ({ key, label: `${def.codigo} ${def.label}`, especial: def.especial === true }))

    return [
        { titulo: 'Reclamos de cliente', tipos: tipos.filter(t => t.especial) },
        { titulo: 'Incidencias del sector', tipos: tipos.filter(t => !t.especial) },
    ].filter(g => g.tipos.length > 0)
})

const tipoEspecial = computed(() =>
    props.taxonomia[sectorSlug.value ?? '']?.[form.tipo]?.especial === true
)

const camposDelTipo = computed<CampoDef[]>(() => {
    if (!sectorSlug.value || !form.tipo) return []
    return props.taxonomia[sectorSlug.value]?.[form.tipo]?.campos ?? []
})

const nombreCompleto = (u: UsuarioOption) => [u.name, u.apellido].filter(Boolean).join(' ')

/**
 * Elegir área recorta la lista de responsables a esa área. Se deja pasar
 * igual al responsable ya elegido, para no hacerlo desaparecer del select.
 */
const usuariosFiltrados = computed(() => {
    if (!form.area_id) return props.usuarios

    return props.usuarios.filter(u => u.area_id === form.area_id || u.id === form.responsable_id)
})

/** Al cambiar de área se suelta el responsable si era de otra. */
const alCambiarArea = () => {
    if (!form.area_id) return

    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (elegido && elegido.area_id !== form.area_id) form.responsable_id = null
}

const gentePorArea = computed(() => {
    if (!form.area_id) return null

    const total = props.usuarios.filter(u => u.area_id === form.area_id).length

    return total === 0
        ? 'Esta área no tiene usuarios cargados.'
        : `${total} ${total === 1 ? 'persona' : 'personas'} en esta área.`
})

/** El plazo de gestión sale del área del responsable: sin área no hay alerta. */
const plazoDelResponsable = computed(() => {
    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (!elegido) return null

    if (!elegido.area) return 'Esta persona no tiene área asignada, así que la observación no va a generar alertas.'
    if (!elegido.area.dias_gestion) return `El área ${elegido.area.nombre} no tiene plazo cargado, así que no va a generar alertas.`

    return `Vence a los ${elegido.area.dias_gestion} días hábiles (plazo de ${elegido.area.nombre}).`
})

const agregarProducto = () => form.productos.push(nuevoProducto())
const quitarProducto = (index: number) => form.productos.splice(index, 1)

const errorProducto = (index: number, campo: keyof ProductoForm) =>
    (form.errors as Record<string, string>)[`productos.${index}.${campo}`]

// Al cambiar de sector: reseteá el tipo y los datos específicos.
watch(() => form.sector_id, () => {
    form.tipo = ''
    form.datos_especificos = {}
    form.productos = []
})

// Al cambiar de tipo: reconstruí las claves de datos específicos, o inicializá el
// bloque de productos si es el tipo especial "Falla de Producto".
watch(() => form.tipo, () => {
    const nuevos: Record<string, string | number | null> = {}
    for (const c of camposDelTipo.value) nuevos[c.id] = ''
    form.datos_especificos = nuevos

    form.productos = form.tipo === 'falla_producto' ? [nuevoProducto()] : []
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
        <div class="max-w-4xl mx-auto">
            <div class="mb-6">
                <h1 class="text-xl font-bold text-slate-800 dark:text-slate-100">Nueva observación interna</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Registro por sector</p>
            </div>

            <form
                @submit.prevent="submit"
                class="bg-white dark:bg-slate-800 rounded-2xl shadow p-6 sm:p-8 space-y-8"
            >
                <FormSection title="Clasificación">
                    <Select v-model="form.sector_id" required label="Sector" :error="form.errors.sector_id">
                        <option :value="null" disabled>— Seleccionar —</option>
                        <option v-for="s in props.sectores" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </Select>

                    <Select
                        v-model="form.tipo"
                        required
                        label="Tipo de incidencia"
                        :disabled="!form.sector_id"
                        :error="form.errors.tipo"
                    >
                        <option value="" disabled>
                            {{ form.sector_id ? '— Seleccionar —' : 'Elegí un sector primero' }}
                        </option>
                        <optgroup v-for="grupo in gruposDeTipos" :key="grupo.titulo" :label="grupo.titulo">
                            <option v-for="t in grupo.tipos" :key="t.key" :value="t.key">{{ t.label }}</option>
                        </optgroup>
                    </Select>

                    <Select
                        v-model="form.tipo_caso"
                        required
                        label="Tipo de caso"
                        :error="form.errors.tipo_caso"
                        @change="sugerirPrioridad"
                    >
                        <option value="" disabled>— Seleccionar —</option>
                        <option v-for="tc in props.tiposCaso" :key="tc" :value="tc">{{ tc }}</option>
                    </Select>

                    <Select
                        v-model="form.prioridad"
                        required
                        label="Prioridad"
                        hint="Se sugiere sola según el tipo de caso; podés cambiarla."
                        :error="form.errors.prioridad"
                    >
                        <option value="" disabled>— Seleccionar —</option>
                        <option v-for="(label, key) in props.prioridades" :key="key" :value="key">{{ label }}</option>
                    </Select>
                </FormSection>

                <FormSection title="Datos del reporte" :columns="1">
                    <Input
                        v-model="form.titulo"
                        required
                        label="Título breve"
                        maxlength="120"
                        :error="form.errors.titulo"
                    />
                    <Textarea
                        v-model="form.descripcion"
                        required
                        label="Descripción detallada"
                        :rows="4"
                        :error="form.errors.descripcion"
                    />
                </FormSection>

                <!-- Datos específicos (dinámico según tipo) -->
                <FormSection v-if="camposDelTipo.length" title="Datos específicos">
                    <CampoDinamico
                        v-for="campo in camposDelTipo"
                        :key="campo.id"
                        v-model="form.datos_especificos[campo.id]"
                        :campo="campo"
                        :error="errorCampo(campo.id)"
                    />
                </FormSection>

                <!-- Falla de Producto (tipo especial, hoy solo Garantía de Calidad) -->
                <FormSection v-if="tipoEspecial && form.tipo === 'falla_producto'" title="Falla de Producto">
                    <Input v-model="form.institucion" required label="Institución" :error="form.errors.institucion" />

                    <Select v-model="form.provincia" required label="Provincia" :error="form.errors.provincia">
                        <option value="" disabled>— Seleccionar —</option>
                        <option v-for="p in props.provincias" :key="p" :value="p">{{ p }}</option>
                    </Select>

                    <Input v-model="form.equipamiento" label="Equipamiento utilizado" />
                    <Input v-model="form.ejecutivo_cuenta" label="Ejecutivo de cuenta a cargo" />

                    <!-- Productos afectados: lista repetible -->
                    <div class="sm:col-span-2 space-y-4 pt-2">
                        <div
                            v-for="(producto, index) in form.productos"
                            :key="index"
                            class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-700/20 p-4 space-y-4"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                                    Producto {{ index + 1 }}
                                </span>
                                <button
                                    v-if="form.productos.length > 1"
                                    type="button"
                                    class="text-xs text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 cursor-pointer"
                                    @click="quitarProducto(index)"
                                >
                                    Quitar
                                </button>
                            </div>

                            <div class="grid sm:grid-cols-2 gap-4">
                                <Input
                                    v-model="producto.producto"
                                    required
                                    label="Producto"
                                    :error="errorProducto(index, 'producto')"
                                />
                                <Input
                                    v-model="producto.codigo"
                                    required
                                    label="Código"
                                    :error="errorProducto(index, 'codigo')"
                                />
                                <Input
                                    v-model.number="producto.cantidad_afectada"
                                    required
                                    type="number"
                                    min="1"
                                    label="Cantidad afectada"
                                    :error="errorProducto(index, 'cantidad_afectada')"
                                />
                                <Input
                                    v-model="producto.lote"
                                    required
                                    label="Lote"
                                    :error="errorProducto(index, 'lote')"
                                />
                                <Input
                                    v-model="producto.fecha_vencimiento"
                                    required
                                    type="date"
                                    label="Fecha de vencimiento"
                                    :error="errorProducto(index, 'fecha_vencimiento')"
                                />
                                <Input
                                    v-model="producto.numero_remito"
                                    required
                                    label="N° de remito"
                                    :error="errorProducto(index, 'numero_remito')"
                                />
                                <RadioGroup
                                    v-model="producto.tipo_comprobante"
                                    full
                                    required
                                    label="Tipo de comprobante"
                                    :opciones="[
                                        { value: 'factura', label: 'Factura' },
                                        { value: 'remito', label: 'Remito' },
                                    ]"
                                    :error="errorProducto(index, 'tipo_comprobante')"
                                />
                            </div>
                        </div>

                        <button
                            type="button"
                            class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-medium cursor-pointer"
                            @click="agregarProducto"
                        >
                            + Agregar producto
                        </button>
                    </div>
                </FormSection>

                <FormSection title="Asignación">
                    <Select
                        v-model="form.area_id"
                        label="Área"
                        :hint="gentePorArea ?? undefined"
                        :error="form.errors.area_id"
                        @change="alCambiarArea"
                    >
                        <option :value="null">— Sin asignar —</option>
                        <option v-for="a in props.areas" :key="a.id" :value="a.id">{{ a.nombre }}</option>
                    </Select>

                    <Select
                        v-model="form.responsable_id"
                        label="Responsable"
                        :hint="plazoDelResponsable ?? undefined"
                        :error="form.errors.responsable_id"
                    >
                        <option :value="null">— Sin asignar —</option>
                        <option v-for="u in usuariosFiltrados" :key="u.id" :value="u.id">{{ nombreCompleto(u) }}</option>
                    </Select>
                </FormSection>

                <FormSection title="Adjuntos" :columns="1">
                    <div
                        class="border-2 border-dashed rounded-xl p-8 text-center transition"
                        :class="isDragging
                            ? 'border-indigo-400 bg-indigo-50/50 dark:bg-indigo-900/20'
                            : 'border-slate-200 dark:border-slate-600'"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="onDrop"
                    >
                        <svg class="w-6 h-6 mx-auto text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.485 8.486L20.5 13"/>
                        </svg>
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200 mt-2">Adjuntar archivos</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">JPG, PNG, PDF. Máx 3 MB</p>
                        <button
                            type="button"
                            class="mt-3 text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 font-medium cursor-pointer"
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
                            class="flex items-center justify-between text-sm bg-slate-50 dark:bg-slate-700/40 rounded-lg px-3 py-2"
                        >
                            <span class="text-slate-700 dark:text-slate-200 truncate">{{ file.name }}</span>
                            <button
                                type="button"
                                class="text-slate-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 cursor-pointer"
                                @click="removeFile(index)"
                            >
                                ✕
                            </button>
                        </li>
                    </ul>
                    <p v-if="form.errors.attachments" class="text-red-500 dark:text-red-400 text-xs">
                        {{ form.errors.attachments }}
                    </p>
                </FormSection>

                <div class="flex justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-700">
                    <Link
                        :href="route('observaciones.nuevo')"
                        class="px-4 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-600 transition"
                    >
                        ← Volver
                    </Link>
                    <Button type="submit" variant="primary" :disabled="form.processing">
                        {{ form.processing ? 'Guardando...' : 'Guardar observación' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
