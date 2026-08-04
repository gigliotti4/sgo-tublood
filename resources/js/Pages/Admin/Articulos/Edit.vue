<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import Textarea from '@/Components/Textarea.vue'
import Button from '@/Components/Button.vue'
import type { Articulo } from '@/types'

const props = defineProps<{ articulo: Articulo }>()

const form = useForm({
    fecha_vencimiento: props.articulo.fecha_vencimiento?.slice(0, 10) ?? '',
    pm: props.articulo.pm ?? '',
    legajo: props.articulo.legajo ?? '',
    observaciones: props.articulo.observaciones ?? '',
})

const submit = () => form.put(route('articulos.update', props.articulo.id))
</script>

<template>
    <Head title="Editar artículo" />

    <AppLayout>
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('articulos.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Editar artículo</h1>
        </div>

        <div class="max-w-2xl space-y-6">
            <!-- Datos del ERP (solo lectura) -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                    Datos de RP Sistemas (solo lectura — se actualizan con la sincronización)
                </p>
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                    <dt class="text-gray-400">Código</dt>
                    <dd class="font-mono text-gray-800 dark:text-white/90">{{ articulo.codigo }}</dd>
                    <dt class="text-gray-400">Descripción</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ articulo.descripcion }}</dd>
                    <dt class="text-gray-400">Descripción adicional</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ articulo.descripcion_adicional ?? '—' }}</dd>
                    <dt class="text-gray-400">Código de barras</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ articulo.codigo_barras ?? '—' }}</dd>
                    <dt class="text-gray-400">Unidad de medida</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ articulo.unidad_medida ?? '—' }}</dd>
                    <dt class="text-gray-400">Proveedor</dt>
                    <dd class="text-gray-800 dark:text-white/90">{{ articulo.codigo_proveedor ?? '—' }}</dd>
                </dl>
            </div>

            <!-- Campos propios -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Datos propios</p>
                <form @submit.prevent="submit" class="space-y-4">
                    <InputFecha
                        v-model="form.fecha_vencimiento"
                        label="Fecha de vencimiento"
                        :error="form.errors.fecha_vencimiento"
                    />
                    <Input
                        v-model="form.pm"
                        label="PM"
                        hint="Registro de producto médico."
                        :error="form.errors.pm"
                    />
                    <Input
                        v-model="form.legajo"
                        label="Legajo"
                        :error="form.errors.legajo"
                    />
                    <Textarea
                        v-model="form.observaciones"
                        label="Observaciones"
                        :error="form.errors.observaciones"
                    />
                    <div class="flex gap-3 pt-2">
                        <Button type="submit" variant="primary" :disabled="form.processing">Guardar cambios</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
