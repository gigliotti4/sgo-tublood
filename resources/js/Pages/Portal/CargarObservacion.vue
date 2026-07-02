<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

interface TipoOption { value: string; label: string }

const props = defineProps<{
    provincias: string[]
    tipoOptions: TipoOption[]
}>()

const form = useForm({
    tipo: '',
    contacto_nombre: '',
    contacto_email: '',
    contacto_numero_cliente: '',
    contacto_telefono: '',
    titulo: '',
    descripcion: '',
    cantidad_afectada: null as number | null,
    lote: '',
    fecha_vencimiento: '',
    numero_remito: '',
    tipo_comprobante: '',
    institucion: '',
    provincia: '',
    producto: '',
    equipamiento: '',
    ejecutivo_cuenta: '',
    attachments: [] as File[],
})

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

    <div class="min-h-screen bg-slate-50 px-4 py-10">
        <div class="max-w-2xl mx-auto">
            <!-- Encabezado -->
            <div class="bg-linear-to-br from-indigo-950 via-indigo-900 to-slate-900 rounded-2xl p-8 mb-6 text-center shadow-lg shadow-indigo-900/20">
                <h1 class="text-white font-bold text-2xl">📋 Cargar observación</h1>
                <p class="text-indigo-300 text-sm mt-1">Espacio exclusivo para clientes</p>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-2xl shadow p-6 sm:p-8 space-y-8">
                <!-- Tipo -->
                <section class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Tipo</h2>
                    <div class="space-y-1.5">
                        <label for="tipo" class="block text-sm font-medium text-slate-700">
                            Tipo <span class="text-red-500">*</span>
                        </label>
                        <select
                            id="tipo"
                            v-model="form.tipo"
                            class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            :class="form.errors.tipo ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                        >
                            <option value="" disabled>— Seleccionar —</option>
                            <option v-for="opt in props.tipoOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <p v-if="form.errors.tipo" class="text-red-500 text-xs">{{ form.errors.tipo }}</p>
                    </div>
                </section>

                <!-- Tus datos -->
                <section class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Tus datos</h2>
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div class="space-y-1.5 sm:col-span-1">
                            <label for="contacto_nombre" class="block text-sm font-medium text-slate-700">
                                Nombre / Razón social <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="contacto_nombre"
                                v-model="form.contacto_nombre"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.contacto_nombre ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.contacto_nombre" class="text-red-500 text-xs">{{ form.errors.contacto_nombre }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="contacto_email" class="block text-sm font-medium text-slate-700">
                                Email <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="contacto_email"
                                v-model="form.contacto_email"
                                type="email"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.contacto_email ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.contacto_email" class="text-red-500 text-xs">{{ form.errors.contacto_email }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="contacto_numero_cliente" class="block text-sm font-medium text-slate-700">N° cliente</label>
                            <input
                                id="contacto_numero_cliente"
                                v-model="form.contacto_numero_cliente"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border border-slate-200 bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            />
                        </div>
                    </div>

                    <div class="space-y-1.5 sm:w-1/3">
                        <label for="contacto_telefono" class="block text-sm font-medium text-slate-700">Teléfono</label>
                        <input
                            id="contacto_telefono"
                            v-model="form.contacto_telefono"
                            type="text"
                            class="w-full px-3 py-2.5 text-sm rounded-lg border border-slate-200 bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        />
                    </div>
                </section>

                <!-- Detalle -->
                <section class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Detalle</h2>

                    <div class="space-y-1.5">
                        <label for="titulo" class="block text-sm font-medium text-slate-700">
                            Título <span class="text-red-500">*</span>
                        </label>
                        <input
                            id="titulo"
                            v-model="form.titulo"
                            type="text"
                            class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            :class="form.errors.titulo ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                        />
                        <p v-if="form.errors.titulo" class="text-red-500 text-xs">{{ form.errors.titulo }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label for="descripcion" class="block text-sm font-medium text-slate-700">
                            Descripción <span class="text-red-500">*</span>
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

                <!-- Falla de Producto -->
                <section v-if="form.tipo === 'falla_producto'" class="space-y-4">
                    <h2 class="text-sm font-semibold text-slate-800 border-b border-slate-100 pb-2">Falla de Producto</h2>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="cantidad_afectada" class="block text-sm font-medium text-slate-700">
                                Cantidad afectada <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="cantidad_afectada"
                                v-model.number="form.cantidad_afectada"
                                type="number"
                                min="1"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.cantidad_afectada ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.cantidad_afectada" class="text-red-500 text-xs">{{ form.errors.cantidad_afectada }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="lote" class="block text-sm font-medium text-slate-700">
                                Lote <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="lote"
                                v-model="form.lote"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.lote ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.lote" class="text-red-500 text-xs">{{ form.errors.lote }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="fecha_vencimiento" class="block text-sm font-medium text-slate-700">
                                Fecha de vencimiento <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="fecha_vencimiento"
                                v-model="form.fecha_vencimiento"
                                type="date"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.fecha_vencimiento ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.fecha_vencimiento" class="text-red-500 text-xs">{{ form.errors.fecha_vencimiento }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="numero_remito" class="block text-sm font-medium text-slate-700">
                                N° de remito <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="numero_remito"
                                v-model="form.numero_remito"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.numero_remito ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.numero_remito" class="text-red-500 text-xs">{{ form.errors.numero_remito }}</p>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <span class="block text-sm font-medium text-slate-700">
                            Tipo de comprobante <span class="text-red-500">*</span>
                        </span>
                        <div class="flex gap-6">
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input v-model="form.tipo_comprobante" type="radio" value="factura" class="text-indigo-600 focus:ring-indigo-500" />
                                Factura
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input v-model="form.tipo_comprobante" type="radio" value="remito" class="text-indigo-600 focus:ring-indigo-500" />
                                Remito
                            </label>
                        </div>
                        <p v-if="form.errors.tipo_comprobante" class="text-red-500 text-xs">{{ form.errors.tipo_comprobante }}</p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label for="institucion" class="block text-sm font-medium text-slate-700">
                                Institución <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="institucion"
                                v-model="form.institucion"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.institucion ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.institucion" class="text-red-500 text-xs">{{ form.errors.institucion }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="provincia" class="block text-sm font-medium text-slate-700">
                                Provincia <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="provincia"
                                v-model="form.provincia"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.provincia ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            >
                                <option value="" disabled>— Seleccionar —</option>
                                <option v-for="p in props.provincias" :key="p" :value="p">{{ p }}</option>
                            </select>
                            <p v-if="form.errors.provincia" class="text-red-500 text-xs">{{ form.errors.provincia }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="producto" class="block text-sm font-medium text-slate-700">
                                Producto <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="producto"
                                v-model="form.producto"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                :class="form.errors.producto ? 'border-red-400 ring-1 ring-red-300' : 'border-slate-200'"
                            />
                            <p v-if="form.errors.producto" class="text-red-500 text-xs">{{ form.errors.producto }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="equipamiento" class="block text-sm font-medium text-slate-700">Equipamiento utilizado</label>
                            <input
                                id="equipamiento"
                                v-model="form.equipamiento"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border border-slate-200 bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <label for="ejecutivo_cuenta" class="block text-sm font-medium text-slate-700">Ejecutivo de cuenta a cargo</label>
                            <input
                                id="ejecutivo_cuenta"
                                v-model="form.ejecutivo_cuenta"
                                type="text"
                                class="w-full px-3 py-2.5 text-sm rounded-lg border border-slate-200 bg-white text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            />
                        </div>
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
                        :href="route('login')"
                        class="px-4 py-2.5 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition"
                    >
                        ← Volver
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed transition"
                    >
                        {{ form.processing ? 'Enviando...' : 'Enviar' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
