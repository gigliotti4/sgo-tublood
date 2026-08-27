<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import SelectorArticulo from '@/Components/SelectorArticulo.vue'
import ResumenErrores from '@/Components/ResumenErrores.vue'
import { erroresDeArchivos } from '@/lib/errores'
import type { PageProps } from '@/types'

const page = usePage<PageProps>()

// Marca y textos administrables desde /configuracion.
const marca = computed(() => page.props.configuracion)

// Lo deja el handler de 419 de bootstrap/app.php si el form expiró.
const flashError = computed(() => page.props.flash?.error)

interface TipoOption { value: string; label: string }

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
    provincias: string[]
    presentaciones: Record<string, string>
    tipoOptions: TipoOption[]
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
    tipo: '',
    contacto_nombre: '',
    contacto_email: '',
    contacto_numero_cliente: '',
    contacto_telefono: '',
    titulo: '',
    descripcion: '',
    institucion: '',
    provincia: '',
    equipamiento: '',
    ejecutivo_cuenta: '',
    productos: [nuevoProducto()] as ProductoForm[],
    attachments: [] as File[],
})

const agregarProducto = () => form.productos.push(nuevoProducto())
const quitarProducto = (index: number) => form.productos.splice(index, 1)

// Solo "Falla de Producto" lleva productos. Sin vaciar la lista al cambiar de
// tipo, la fila que se muestra por defecto seguía viajando en el post desde el
// bloque ya oculto y el backend la rechazaba campo por campo, con los errores
// cayendo en inputs que no están en pantalla.
watch(() => form.tipo, () => {
    form.productos = form.tipo === 'falla_producto' ? [nuevoProducto()] : []
})

/**
 * La fecha de vencimiento se tipea como dd/mm/aaaa (un input de texto, no el
 * date picker del navegador). Mientras se escribe se van intercalando las
 * barras, y al enviar se convierte a aaaa-mm-dd, que es lo que valida el
 * backend (regla `date`). Si lo tipeado no forma una fecha completa se manda
 * tal cual y el backend la rechaza con su mensaje de validación.
 */
const tipearFecha = (producto: ProductoForm, e: Event) => {
    const input = e.target as HTMLInputElement
    const digitos = input.value.replace(/\D/g, '').slice(0, 8)

    let out = digitos
    if (digitos.length > 4) out = `${digitos.slice(0, 2)}/${digitos.slice(2, 4)}/${digitos.slice(4)}`
    else if (digitos.length > 2) out = `${digitos.slice(0, 2)}/${digitos.slice(2)}`

    producto.fecha_vencimiento = out
    input.value = out
}

const aFechaISO = (valor: string) => {
    const m = valor.match(/^(\d{2})\/(\d{2})\/(\d{4})$/)
    return m ? `${m[3]}-${m[2]}-${m[1]}` : valor
}

form.transform(data => ({
    ...data,
    productos: data.productos.map(p => ({ ...p, fecha_vencimiento: aFechaISO(p.fecha_vencimiento) })),
}))

const errorProducto = (index: number, campo: keyof ProductoForm) =>
    (form.errors as Record<string, string>)[`productos.${index}.${campo}`]

// Los errores de archivo vuelven en `attachments.0`, `attachments.1`… y no en
// `attachments`, así que sin esto el cliente adjuntaba algo que el servidor
// rechazaba y el formulario se negaba a enviarse sin decir por qué.
const erroresArchivos = computed(() => erroresDeArchivos(form.errors as Record<string, string>))

// El portal es siempre claro (sin dark:), por eso no reusa los componentes del panel.
const inputClass = (error?: string) => [
    'h-11 w-full rounded-lg border bg-white px-4 py-2.5 text-[16px] sm:text-sm text-gray-800 shadow-theme-xs transition placeholder:text-gray-400 focus:outline-none focus:ring-3',
    error
        ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10'
        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10',
]

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

const submit = () => form.post(route('observaciones.public.store'), { forceFormData: true })
</script>

<template>
    <Head title="Cargar observación" />

    <div class="min-h-screen bg-gray-50 px-4 py-10 font-outfit">
        <div class="mx-auto max-w-2xl">
            <!-- Encabezado -->
            <div class="mb-6 rounded-2xl bg-linear-to-br from-brand-900 via-brand-700 to-brand-500 p-8 text-center shadow-theme-lg">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-white/15">
                    <svg class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white">{{ marca.portal_titulo }}</h1>
                <p class="mt-1 text-sm text-brand-200">{{ marca.portal_bajada }}</p>
            </div>

            <!-- Aviso de formulario expirado (419) -->
            <div
                v-if="flashError"
                class="mb-5 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm text-amber-800"
            >
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                {{ flashError }}
            </div>

            <ResumenErrores
                :errors="form.errors as Record<string, string>"
                titulo="No pudimos enviar tu observación"
                class="mb-5"
            />

            <form @submit.prevent="submit" class="space-y-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm sm:p-8">
                <!-- Tipo -->
                <section class="space-y-5">
                    <h2 class="border-b border-gray-100 pb-3 text-base font-semibold text-gray-800">Tipo</h2>
                    <div>
                        <label for="tipo" class="mb-1.5 block text-sm font-medium text-gray-700">
                            Tipo <span class="text-error-500">*</span>
                        </label>
                        <select
                            id="tipo"
                            v-model="form.tipo"
                            :class="inputClass(form.errors.tipo)"
                        >
                            <option value="" disabled>— Seleccionar —</option>
                            <option v-for="opt in props.tipoOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <p v-if="form.errors.tipo" class="mt-1.5 text-xs text-error-500">{{ form.errors.tipo }}</p>
                    </div>
                </section>

                <!-- Tus datos -->
                <section class="space-y-5">
                    <h2 class="border-b border-gray-100 pb-3 text-base font-semibold text-gray-800">Tus datos</h2>
                    <div class="grid gap-5 sm:grid-cols-3">
                        <div class="sm:col-span-1">
                            <label for="contacto_nombre" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Nombre / Razón social <span class="text-error-500">*</span>
                            </label>
                            <input
                                id="contacto_nombre"
                                v-model="form.contacto_nombre"
                                type="text"
                                :class="inputClass(form.errors.contacto_nombre)"
                            />
                            <p v-if="form.errors.contacto_nombre" class="mt-1.5 text-xs text-error-500">{{ form.errors.contacto_nombre }}</p>
                        </div>

                        <div>
                            <label for="contacto_email" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Email <span class="text-error-500">*</span>
                            </label>
                            <input
                                id="contacto_email"
                                v-model="form.contacto_email"
                                type="email"
                                :class="inputClass(form.errors.contacto_email)"
                            />
                            <p v-if="form.errors.contacto_email" class="mt-1.5 text-xs text-error-500">{{ form.errors.contacto_email }}</p>
                        </div>

                        <div>
                            <label for="contacto_numero_cliente" class="mb-1.5 block text-sm font-medium text-gray-700">N° cliente</label>
                            <input
                                id="contacto_numero_cliente"
                                v-model="form.contacto_numero_cliente"
                                type="text"
                                :class="inputClass()"
                            />
                        </div>
                    </div>

                    <div class="sm:w-1/3">
                        <label for="contacto_telefono" class="mb-1.5 block text-sm font-medium text-gray-700">Teléfono</label>
                        <input
                            id="contacto_telefono"
                            v-model="form.contacto_telefono"
                            type="text"
                            :class="inputClass()"
                        />
                    </div>
                </section>

                <!-- Detalle -->
                <section class="space-y-5">
                    <h2 class="border-b border-gray-100 pb-3 text-base font-semibold text-gray-800">Detalle</h2>

                    <div>
                        <label for="titulo" class="mb-1.5 block text-sm font-medium text-gray-700">
                            Título <span class="text-error-500">*</span>
                        </label>
                        <input
                            id="titulo"
                            v-model="form.titulo"
                            type="text"
                            :class="inputClass(form.errors.titulo)"
                        />
                        <p v-if="form.errors.titulo" class="mt-1.5 text-xs text-error-500">{{ form.errors.titulo }}</p>
                    </div>

                    <div>
                        <label for="descripcion" class="mb-1.5 block text-sm font-medium text-gray-700">
                            Descripción <span class="text-error-500">*</span>
                        </label>
                        <textarea
                            id="descripcion"
                            v-model="form.descripcion"
                            rows="4"
                            class="w-full rounded-lg border bg-white px-4 py-2.5 text-[16px] sm:text-sm text-gray-800 shadow-theme-xs transition placeholder:text-gray-400 focus:outline-none focus:ring-3"
                            :class="form.errors.descripcion
                                ? 'border-error-300 focus:border-error-300 focus:ring-error-500/10'
                                : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/10'"
                        />
                        <p v-if="form.errors.descripcion" class="mt-1.5 text-xs text-error-500">{{ form.errors.descripcion }}</p>
                    </div>
                </section>

                <!-- Falla de Producto -->
                <section v-if="form.tipo === 'falla_producto'" class="space-y-5">
                    <h2 class="border-b border-gray-100 pb-3 text-base font-semibold text-gray-800">Falla de Producto</h2>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="institucion" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Institución <span class="text-error-500">*</span>
                            </label>
                            <input
                                id="institucion"
                                v-model="form.institucion"
                                type="text"
                                :class="inputClass(form.errors.institucion)"
                            />
                            <p v-if="form.errors.institucion" class="mt-1.5 text-xs text-error-500">{{ form.errors.institucion }}</p>
                        </div>

                        <div>
                            <label for="provincia" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Provincia <span class="text-error-500">*</span>
                            </label>
                            <select
                                id="provincia"
                                v-model="form.provincia"
                                :class="inputClass(form.errors.provincia)"
                            >
                                <option value="" disabled>— Seleccionar —</option>
                                <option v-for="p in props.provincias" :key="p" :value="p">{{ p }}</option>
                            </select>
                            <p v-if="form.errors.provincia" class="mt-1.5 text-xs text-error-500">{{ form.errors.provincia }}</p>
                        </div>

                        <div>
                            <label for="equipamiento" class="mb-1.5 block text-sm font-medium text-gray-700">Equipamiento utilizado</label>
                            <input
                                id="equipamiento"
                                v-model="form.equipamiento"
                                type="text"
                                :class="inputClass()"
                            />
                        </div>

                        <div>
                            <label for="ejecutivo_cuenta" class="mb-1.5 block text-sm font-medium text-gray-700">Ejecutivo de cuenta a cargo</label>
                            <input
                                id="ejecutivo_cuenta"
                                v-model="form.ejecutivo_cuenta"
                                type="text"
                                :class="inputClass()"
                            />
                        </div>
                    </div>

                    <!-- Productos -->
                    <div class="space-y-4 pt-2">
                        <div
                            v-for="(producto, index) in form.productos"
                            :key="index"
                            class="space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-theme-xs font-medium uppercase tracking-wide text-gray-500">Producto {{ index + 1 }}</span>
                                <button
                                    v-if="form.productos.length > 1"
                                    type="button"
                                    class="inline-flex min-h-11 -mx-2 items-center px-2 cursor-pointer text-theme-xs font-medium text-error-500 hover:text-error-600"
                                    @click="quitarProducto(index)"
                                >
                                    Quitar
                                </button>
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <SelectorArticulo
                                    v-model="producto.codigo"
                                    portal
                                    required
                                    label="Código de producto"
                                    hint="Buscá por código o nombre. Si no lo encontrás, escribilo igual."
                                    :id="`producto-${index}-codigo`"
                                    :error="errorProducto(index, 'codigo')"
                                    @seleccionar="a => producto.producto = a.descripcion"
                                />

                                <div>
                                    <label :for="`producto-${index}-producto`" class="mb-1.5 block text-sm font-medium text-gray-700">
                                        Nombre de producto <span class="text-error-500">*</span>
                                    </label>
                                    <input
                                        :id="`producto-${index}-producto`"
                                        v-model="producto.producto"
                                        type="text"
                                        :class="inputClass(errorProducto(index, 'producto'))"
                                    />
                                    <p v-if="errorProducto(index, 'producto')" class="mt-1.5 text-xs text-error-500">{{ errorProducto(index, 'producto') }}</p>
                                </div>

                                <div>
                                    <label :for="`producto-${index}-cantidad`" class="mb-1.5 block text-sm font-medium text-gray-700">
                                        Cantidad afectada <span class="text-error-500">*</span>
                                    </label>
                                    <input
                                        :id="`producto-${index}-cantidad`"
                                        v-model.number="producto.cantidad_afectada"
                                        type="number"
                                        min="1"
                                        :class="inputClass(errorProducto(index, 'cantidad_afectada'))"
                                    />
                                    <p v-if="errorProducto(index, 'cantidad_afectada')" class="mt-1.5 text-xs text-error-500">{{ errorProducto(index, 'cantidad_afectada') }}</p>
                                </div>

                                <div>
                                    <label :for="`producto-${index}-presentacion`" class="mb-1.5 block text-sm font-medium text-gray-700">
                                        Presentación <span class="text-error-500">*</span>
                                    </label>
                                    <select
                                        :id="`producto-${index}-presentacion`"
                                        v-model="producto.tipo_presentacion"
                                        :class="inputClass(errorProducto(index, 'tipo_presentacion'))"
                                    >
                                        <option value="" disabled>— Seleccionar —</option>
                                        <option v-for="(label, key) in props.presentaciones" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                    <p v-if="errorProducto(index, 'tipo_presentacion')" class="mt-1.5 text-xs text-error-500">{{ errorProducto(index, 'tipo_presentacion') }}</p>
                                    <p v-else class="mt-1.5 text-xs text-gray-400">A qué corresponde la cantidad afectada.</p>
                                </div>

                                <div>
                                    <label :for="`producto-${index}-lote`" class="mb-1.5 block text-sm font-medium text-gray-700">
                                        Lote <span class="text-error-500">*</span>
                                    </label>
                                    <input
                                        :id="`producto-${index}-lote`"
                                        v-model="producto.lote"
                                        type="text"
                                        :class="inputClass(errorProducto(index, 'lote'))"
                                    />
                                    <p v-if="errorProducto(index, 'lote')" class="mt-1.5 text-xs text-error-500">{{ errorProducto(index, 'lote') }}</p>
                                </div>

                                <div>
                                    <label :for="`producto-${index}-vencimiento`" class="mb-1.5 block text-sm font-medium text-gray-700">
                                        Fecha de vencimiento <span class="text-error-500">*</span>
                                    </label>
                                    <input
                                        :id="`producto-${index}-vencimiento`"
                                        :value="producto.fecha_vencimiento"
                                        type="text"
                                        inputmode="numeric"
                                        placeholder="dd/mm/aaaa"
                                        maxlength="10"
                                        :class="inputClass(errorProducto(index, 'fecha_vencimiento'))"
                                        @input="tipearFecha(producto, $event)"
                                    />
                                    <p v-if="errorProducto(index, 'fecha_vencimiento')" class="mt-1.5 text-xs text-error-500">{{ errorProducto(index, 'fecha_vencimiento') }}</p>
                                </div>

                                <div>
                                    <label :for="`producto-${index}-remito`" class="mb-1.5 block text-sm font-medium text-gray-700">
                                        N° de remito
                                    </label>
                                    <input
                                        :id="`producto-${index}-remito`"
                                        v-model="producto.numero_remito"
                                        type="text"
                                        :class="inputClass(errorProducto(index, 'numero_remito'))"
                                    />
                                    <p v-if="errorProducto(index, 'numero_remito')" class="mt-1.5 text-xs text-error-500">{{ errorProducto(index, 'numero_remito') }}</p>
                                </div>
                            </div>

                            <div>
                                <span class="mb-1.5 block text-sm font-medium text-gray-700">
                                    Tipo de comprobante
                                </span>
                                <div class="flex gap-6 pt-1">
                                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700">
                                        <input v-model="producto.tipo_comprobante" type="radio" value="factura" class="h-4 w-4 accent-brand-500" />
                                        Factura
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700">
                                        <input v-model="producto.tipo_comprobante" type="radio" value="remito" class="h-4 w-4 accent-brand-500" />
                                        Remito
                                    </label>
                                </div>
                                <p v-if="errorProducto(index, 'tipo_comprobante')" class="mt-1.5 text-xs text-error-500">{{ errorProducto(index, 'tipo_comprobante') }}</p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex min-h-11 -mx-2 items-center px-2 cursor-pointer text-sm font-medium text-brand-500 hover:text-brand-600"
                            @click="agregarProducto"
                        >
                            + Agregar producto
                        </button>
                    </div>
                </section>

                <!-- Adjuntos -->
                <section class="space-y-5">
                    <h2 class="border-b border-gray-100 pb-3 text-base font-semibold text-gray-800">Adjuntos</h2>

                    <div
                        class="rounded-xl border-2 border-dashed p-8 text-center transition"
                        :class="isDragging ? 'border-brand-300 bg-brand-50/60' : 'border-gray-200'"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="onDrop"
                    >
                        <svg class="mx-auto h-6 w-6 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.485 8.486L20.5 13"/>
                        </svg>
                        <p class="mt-2 text-sm font-medium text-gray-700">Adjuntar archivos</p>
                        <p class="mt-1 text-xs text-gray-400">JPG, PNG, PDF. Máx 3 MB</p>
                        <button
                            type="button"
                            class="mt-3 inline-flex min-h-11 -mx-2 items-center px-2 cursor-pointer text-sm font-medium text-brand-500 hover:text-brand-600"
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
                            class="rounded-lg px-3 py-2 text-sm"
                            :class="erroresArchivos[index] ? 'bg-error-50 ring-1 ring-error-200' : 'bg-gray-50'"
                        >
                            <div class="flex items-center justify-between">
                                <span class="truncate text-gray-700">{{ file.name }}</span>
                                <button type="button" class="cursor-pointer rounded-full p-2 -m-2 text-gray-400 hover:text-error-500" @click="removeFile(index)">✕</button>
                            </div>
                            <!-- El error va pegado a su archivo: con varios adjuntos,
                                 un mensaje suelto al pie no dice cuál hay que sacar. -->
                            <p v-if="erroresArchivos[index]" class="mt-1 text-xs text-error-600">
                                {{ erroresArchivos[index] }}
                            </p>
                        </li>
                    </ul>
                    <p v-if="form.errors.attachments" class="text-xs text-error-500">{{ form.errors.attachments }}</p>
                </section>

                <!-- Acciones -->
                <div class="flex flex-col-reverse gap-3 border-t border-gray-100 pt-5 sm:flex-row sm:justify-end">
                    <Link
                        :href="route('login')"
                        class="inline-flex w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 sm:w-auto"
                    >
                        ← Volver
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full cursor-pointer rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                    >
                        {{ form.processing ? 'Enviando...' : 'Enviar' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
