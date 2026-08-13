<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Input from '@/Components/Input.vue'
import Textarea from '@/Components/Textarea.vue'
import Button from '@/Components/Button.vue'
import type { Proveedor } from '@/types'

const props = defineProps<{ proveedor: Proveedor }>()

const form = useForm({
    razon_social: props.proveedor.razon_social ?? '',
    domicilio: props.proveedor.domicilio ?? '',
    cuit: props.proveedor.cuit ?? '',
    telefono: props.proveedor.telefono ?? '',
    mail: props.proveedor.mail ?? '',
    localidad: props.proveedor.localidad ?? '',
    observaciones: props.proveedor.observaciones ?? '',
})

const submit = () => form.put(route('proveedores.update', props.proveedor.id))
</script>

<template>
    <Head title="Editar proveedor" />

    <AppLayout>
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('proveedores.index')" class="text-sm text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">← Volver</Link>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Editar proveedor</h1>
        </div>

        <div class="max-w-2xl space-y-6">
            <!-- El número es la clave del import: no se edita. -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">
                    Número de proveedor (solo lectura — es la clave con la que se identifica en el Excel)
                </p>
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                    <dt class="text-gray-400">N°</dt>
                    <dd v-if="proveedor.numero" class="font-mono text-gray-800 dark:text-white/90">{{ proveedor.numero }}</dd>
                    <dd v-else class="italic text-gray-400">
                        Sin número — lo creó el import de artículos. Se completa solo al importar el padrón.
                    </dd>
                </dl>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Datos del proveedor</p>
                <form @submit.prevent="submit" class="space-y-4">
                    <Input
                        v-model="form.razon_social"
                        label="Razón social"
                        required
                        hint="Se actualiza con cada importación del Excel."
                        :error="form.errors.razon_social"
                    />
                    <Input
                        v-model="form.domicilio"
                        label="Domicilio"
                        hint="Se actualiza con cada importación del Excel."
                        :error="form.errors.domicilio"
                    />
                    <Input
                        v-model="form.localidad"
                        label="Localidad"
                        :error="form.errors.localidad"
                    />
                    <Input
                        v-model="form.cuit"
                        label="CUIT"
                        :error="form.errors.cuit"
                    />
                    <Input
                        v-model="form.telefono"
                        label="Teléfono"
                        :error="form.errors.telefono"
                    />
                    <Input
                        v-model="form.mail"
                        type="email"
                        label="Mail"
                        :error="form.errors.mail"
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
