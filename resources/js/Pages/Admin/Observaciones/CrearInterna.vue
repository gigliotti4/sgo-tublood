<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import CampoDinamico, { type CampoDef } from '@/Components/CampoDinamico.vue'
import FormSection from '@/Components/FormSection.vue'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'

interface SectorOption { id: number; nombre: string; slug: string }
interface UsuarioOption {
    id: number
    name: string
    apellido: string | null
    sector_id: number | null
    sector: { nombre: string; dias_gestion: number | null } | null
}
interface TipoDef { codigo: string; label: string; campos?: CampoDef[]; especial?: boolean }
type Taxonomia = Record<string, Record<string, TipoDef>>

interface ProductoForm {
    producto: string
    codigo: string
    cantidad_afectada: number | null
    tipo_presentacion: string
    lote: string
    fecha_vencimiento: string
    numero_remito: string
    tipo_comprobante: string
}

const props = defineProps<{
    sectores: SectorOption[]
    taxonomia: Taxonomia
    provincias: string[]
    presentaciones: Record<string, string>
    prioridades: Record<string, string>
    tiposCaso: string[]
    prioridadSugerida: Record<string, string>
    usuarios: UsuarioOption[]
}>()

const nuevoProducto = (): ProductoForm => ({
    producto: '',
    codigo: '',
    cantidad_afectada: null,
    tipo_presentacion: '',
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
    contacto_numero_cliente: '',
    contacto_nombre: '',
    contacto_email: '',
    responsable_id: null as number | null,
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
 * El sector elegido en Clasificación recorta la lista de responsables a ese
 * mismo sector. Se deja pasar igual al responsable ya elegido, para no
 * hacerlo desaparecer del select.
 */
const usuariosFiltrados = computed(() => {
    if (!form.sector_id) return props.usuarios

    return props.usuarios.filter(u => u.sector_id === form.sector_id || u.id === form.responsable_id)
})

const gentePorSector = computed(() => {
    if (!form.sector_id) return null

    const total = props.usuarios.filter(u => u.sector_id === form.sector_id).length

    return total === 0
        ? 'Este sector no tiene usuarios cargados.'
        : `${total} ${total === 1 ? 'persona' : 'personas'} en este sector.`
})

/** El plazo de gestión sale del sector del responsable: sin sector no hay alerta. */
const plazoDelResponsable = computed(() => {
    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (!elegido) return null

    if (!elegido.sector) return 'Esta persona no tiene sector asignado, así que la observación no va a generar alertas.'
    if (!elegido.sector.dias_gestion) return `El sector ${elegido.sector.nombre} no tiene plazo cargado, así que no va a generar alertas.`

    return `Vence a los ${elegido.sector.dias_gestion} días hábiles (plazo de ${elegido.sector.nombre}).`
})

const agregarProducto = () => form.productos.push(nuevoProducto())
const quitarProducto = (index: number) => form.productos.splice(index, 1)

const errorProducto = (index: number, campo: keyof ProductoForm) =>
    (form.errors as Record<string, string>)[`productos.${index}.${campo}`]

// Al cambiar de sector: reseteá el tipo y los datos específicos, y soltá el
// responsable si era de otro sector (el mismo sector filtra ambas cosas).
watch(() => form.sector_id, () => {
    form.tipo = ''
    form.datos_especificos = {}
    form.productos = []

    const elegido = props.usuarios.find(u => u.id === form.responsable_id)
    if (elegido && elegido.sector_id !== form.sector_id) form.responsable_id = null
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
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Nueva observación interna</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">Registro por sector</p>
            </div>

            <form
                @submit.prevent="submit"
                class="space-y-8 rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03] sm:p-8"
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

                <FormSection
                    title="Cliente"
                    description="Opcional: si la incidencia involucra a un cliente, cargalo acá."
                >
                    <Input
                        v-model="form.contacto_numero_cliente"
                        label="N° de cliente"
                        hint="Si coincide con un cliente sincronizado de RP Sistemas, la observación queda vinculada."
                        :error="form.errors.contacto_numero_cliente"
                    />
                    <Input
                        v-model="form.contacto_nombre"
                        label="Razón social"
                        :error="form.errors.contacto_nombre"
                    />
                    <Input
                        v-model="form.contacto_email"
                        type="email"
                        label="Mail"
                        :error="form.errors.contacto_email"
                    />
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
                            class="space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Producto {{ index + 1 }}
                                </span>
                                <button
                                    v-if="form.productos.length > 1"
                                    type="button"
                                    class="cursor-pointer text-theme-xs font-medium text-error-500 hover:text-error-600 dark:text-error-400 dark:hover:text-error-300"
                                    @click="quitarProducto(index)"
                                >
                                    Quitar
                                </button>
                            </div>

                            <div class="grid sm:grid-cols-2 gap-4">
                                <Input
                                    v-model="producto.codigo"
                                    required
                                    label="Código de producto"
                                    :error="errorProducto(index, 'codigo')"
                                />
                                <Input
                                    v-model="producto.producto"
                                    required
                                    label="Nombre de producto"
                                    :error="errorProducto(index, 'producto')"
                                />
                                <Input
                                    v-model.number="producto.cantidad_afectada"
                                    required
                                    type="number"
                                    min="1"
                                    label="Cantidad afectada"
                                    :error="errorProducto(index, 'cantidad_afectada')"
                                />
                                <Select
                                    v-model="producto.tipo_presentacion"
                                    required
                                    label="Presentación"
                                    hint="A qué corresponde la cantidad afectada."
                                    :error="errorProducto(index, 'tipo_presentacion')"
                                >
                                    <option value="" disabled>— Seleccionar —</option>
                                    <option v-for="(label, key) in props.presentaciones" :key="key" :value="key">{{ label }}</option>
                                </Select>
                                <Input
                                    v-model="producto.lote"
                                    required
                                    label="Lote"
                                    :error="errorProducto(index, 'lote')"
                                />
                                <InputFecha
                                    v-model="producto.fecha_vencimiento"
                                    required
                                    label="Fecha de vencimiento"
                                    :error="errorProducto(index, 'fecha_vencimiento')"
                                />
                                <Input
                                    v-model="producto.numero_remito"
                                    label="N° de remito"
                                    :error="errorProducto(index, 'numero_remito')"
                                />
                                <RadioGroup
                                    v-model="producto.tipo_comprobante"
                                    full
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
                            class="cursor-pointer text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                            @click="agregarProducto"
                        >
                            + Agregar producto
                        </button>
                    </div>
                </FormSection>

                <FormSection title="Asignación">
                    <Select
                        v-model="form.responsable_id"
                        label="Responsable"
                        :hint="plazoDelResponsable ?? undefined"
                        :error="form.errors.responsable_id"
                    >
                        <option :value="null">— Sin asignar —</option>
                        <option v-for="u in usuariosFiltrados" :key="u.id" :value="u.id">{{ nombreCompleto(u) }}</option>
                    </Select>
                    <p v-if="gentePorSector" class="-mt-2 text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">
                        {{ gentePorSector }}
                    </p>
                </FormSection>

                <FormSection title="Adjuntos" :columns="1">
                    <div
                        class="rounded-xl border-2 border-dashed p-8 text-center transition"
                        :class="isDragging
                            ? 'border-brand-300 bg-brand-50/60 dark:border-brand-500/50 dark:bg-brand-500/10'
                            : 'border-gray-200 dark:border-gray-700'"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="onDrop"
                    >
                        <svg class="mx-auto h-6 w-6 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.485 8.486L20.5 13"/>
                        </svg>
                        <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">Adjuntar archivos</p>
                        <p class="mt-1 text-xs text-gray-400">JPG, PNG, PDF. Máx 3 MB</p>
                        <button
                            type="button"
                            class="mt-3 cursor-pointer text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
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
                            class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/[0.05]"
                        >
                            <span class="truncate text-gray-700 dark:text-gray-200">{{ file.name }}</span>
                            <button
                                type="button"
                                class="cursor-pointer text-gray-400 hover:text-error-500 dark:hover:text-error-400"
                                @click="removeFile(index)"
                            >
                                ✕
                            </button>
                        </li>
                    </ul>
                    <p v-if="form.errors.attachments" class="text-xs text-error-500 dark:text-error-400">
                        {{ form.errors.attachments }}
                    </p>
                </FormSection>

                <div class="flex justify-end gap-3 border-t border-gray-100 pt-5 dark:border-gray-800">
                    <Link
                        :href="route('observaciones.nuevo')"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
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
