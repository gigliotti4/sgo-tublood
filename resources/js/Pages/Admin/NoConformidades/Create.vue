<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import FormSection from '@/Components/FormSection.vue'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'
import SelectorMultipleAsync, { type OpcionAsync } from '@/Components/SelectorMultipleAsync.vue'
import type { Sector } from '@/types'

defineProps<{
    tiposDesvio: Record<string, string>
    sectores: Pick<Sector, 'id' | 'nombre'>[]
    clientes: { id: number; numero: string; razon_social: string }[]
    proveedores: { id: number; numero: string | null; razon_social: string }[]
}>()

const form = useForm({
    tipo_desvio: 'interno',
    fecha_deteccion: new Date().toISOString().slice(0, 10),
    motivo: '',
    sector_id: null as number | null,
    cliente_id: null as number | null,
    proveedor_id: null as number | null,
    descripcion: '',
    observaciones: [] as string[],
    archivos: [] as File[],
})

/**
 * El buscador devuelve `{id, numero, titulo, estado}`; el selector es genérico
 * y trabaja con `{id, label}` en string. El backend castea a entero.
 */
const mapearObservacion = (item: unknown): OpcionAsync => {
    const o = item as { id: number; numero: string; titulo: string }

    return { id: String(o.id), label: `${o.numero} · ${o.titulo}` }
}

const inputArchivos = ref<HTMLInputElement | null>(null)

const agregarArchivos = (lista: FileList | null) => {
    if (!lista) return
    form.archivos = [...form.archivos, ...Array.from(lista)]
}

const quitarArchivo = (i: number) => {
    form.archivos = form.archivos.filter((_, idx) => idx !== i)
}

const enviar = () => {
    form.post(route('no-conformidades.store'), { forceFormData: true })
}
</script>

<template>
    <Head title="Nueva No Conformidad" />
    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-6">
            <div>
                <Link
                    :href="route('no-conformidades.index')"
                    class="text-theme-xs text-brand-500 hover:underline dark:text-brand-300"
                >
                    ← Volver a No Conformidades
                </Link>
                <h1 class="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">Nueva No Conformidad</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                    Se guarda como <strong>borrador</strong>: vas a poder corregirla antes de enviarla a aprobación.
                    El número definitivo se asigna cuando la aprueben.
                </p>
            </div>

            <form
                class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
                @submit.prevent="enviar"
            >
                <FormSection title="Datos generales">
                    <Select v-model="form.tipo_desvio" label="Tipo de desvío" required :error="form.errors.tipo_desvio">
                        <option v-for="(label, valor) in tiposDesvio" :key="valor" :value="valor">{{ label }}</option>
                    </Select>

                    <InputFecha
                        v-model="form.fecha_deteccion"
                        label="Fecha de detección"
                        required
                        :error="form.errors.fecha_deteccion"
                    />

                    <!--
                        Texto libre, como el formulario en papel: ninguna lista
                        cerrada describe un caso concreto ("Mala implementación
                        del código de barras").
                    -->
                    <Input
                        v-model="form.motivo"
                        label="Motivo"
                        required
                        full
                        hint="En pocas palabras, qué originó el desvío."
                        :error="form.errors.motivo"
                    />

                    <Select v-model="form.sector_id" label="Sector involucrado" required :error="form.errors.sector_id">
                        <option :value="null">Elegí un sector…</option>
                        <option v-for="s in sectores" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </Select>
                </FormSection>

                <FormSection
                    title="Cliente o proveedor"
                    description="Solo si corresponde. Una No Conformidad puede ser interna y no involucrar a ninguno."
                >
                    <Select v-model="form.cliente_id" label="Cliente" :error="form.errors.cliente_id">
                        <option :value="null">Ninguno</option>
                        <option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.numero }} — {{ c.razon_social }}</option>
                    </Select>

                    <Select v-model="form.proveedor_id" label="Proveedor" :error="form.errors.proveedor_id">
                        <option :value="null">Ninguno</option>
                        <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.razon_social }}</option>
                    </Select>
                </FormSection>

                <FormSection title="El problema" :columns="1">
                    <Textarea
                        v-model="form.descripcion"
                        label="Descripción"
                        required
                        :rows="5"
                        hint="Qué pasó, dónde y cómo se detectó."
                        :error="form.errors.descripcion"
                    />
                </FormSection>

                <FormSection
                    title="Observaciones relacionadas"
                    :columns="1"
                    description="Los reclamos que originaron este desvío. Se pueden agregar o quitar después, desde la ficha."
                >
                    <SelectorMultipleAsync
                        v-model="form.observaciones"
                        route="observaciones.buscar"
                        label="Buscar por número o título"
                        placeholder="Ninguna"
                        :mapear="mapearObservacion"
                    />
                </FormSection>

                <FormSection title="Evidencias" :columns="1" description="Fotos, informes, mails. Podés sumar más después.">
                    <div>
                        <input
                            ref="inputArchivos"
                            type="file"
                            multiple
                            class="hidden"
                            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                            @change="agregarArchivos(($event.target as HTMLInputElement).files)"
                        >
                        <button
                            type="button"
                            class="w-full cursor-pointer rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-theme-sm text-gray-500 transition hover:border-brand-500 hover:text-brand-500 dark:border-gray-700 dark:text-gray-400"
                            @click="inputArchivos?.click()"
                        >
                            Elegir archivos
                        </button>

                        <ul v-if="form.archivos.length" class="mt-3 space-y-2">
                            <li
                                v-for="(a, i) in form.archivos"
                                :key="i"
                                class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-theme-xs dark:border-gray-700"
                            >
                                <span class="truncate text-gray-600 dark:text-gray-300">{{ a.name }}</span>
                                <button
                                    type="button"
                                    class="cursor-pointer text-error-500 hover:underline"
                                    @click="quitarArchivo(i)"
                                >
                                    Quitar
                                </button>
                            </li>
                        </ul>
                    </div>
                </FormSection>

                <div class="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                    <Link :href="route('no-conformidades.index')">
                        <Button variant="outline" type="button">Cancelar</Button>
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Guardando…' : 'Guardar borrador' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
