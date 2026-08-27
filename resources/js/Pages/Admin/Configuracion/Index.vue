<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormSection from '@/Components/FormSection.vue'
import Input from '@/Components/Input.vue'
import Textarea from '@/Components/Textarea.vue'
import Button from '@/Components/Button.vue'
import type { PageProps } from '@/types'

/** Una clave del catálogo de config/configuracion.php. */
interface ClaveDef {
    grupo: string
    label: string
    tipo: 'texto' | 'textarea' | 'imagen'
    default: string | null
    hint?: string
}

const props = defineProps<{
    catalogo: Record<string, ClaveDef>
    grupos: Record<string, string>
    valores: Record<string, string | null>
}>()

const page = usePage<PageProps>()

// El formulario se arma desde el catálogo, no desde una lista escrita a mano:
// una clave nueva en el config aparece acá sola.
const form = reactive<Record<string, string>>({})
const archivos = reactive<Record<string, File | null>>({})
const borrar = reactive<Record<string, boolean>>({})

for (const [clave, def] of Object.entries(props.catalogo)) {
    if (def.tipo === 'imagen') {
        archivos[clave] = null
        borrar[clave] = false
    } else {
        form[clave] = props.valores[clave] ?? ''
    }
}

const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>)
const procesando = ref(false)

/** Las claves de un grupo, en el orden en que las declara el catálogo. */
const clavesDe = (grupo: string) =>
    Object.entries(props.catalogo).filter(([, def]) => def.grupo === grupo)

const onArchivo = (clave: string, e: Event) => {
    archivos[clave] = (e.target as HTMLInputElement).files?.[0] ?? null
    // Elegir un archivo cancela un borrado pendiente: son acciones opuestas.
    if (archivos[clave]) borrar[clave] = false
}

const submit = () => {
    // FormData a mano y no useForm: hay archivos mezclados con texto y una
    // lista `_borrar` que no es un campo del formulario.
    const data = new FormData()

    for (const [clave, valor] of Object.entries(form)) {
        data.append(clave, valor ?? '')
    }
    for (const [clave, archivo] of Object.entries(archivos)) {
        if (archivo) data.append(clave, archivo)
    }
    for (const [clave, marcado] of Object.entries(borrar)) {
        if (marcado) data.append('_borrar[]', clave)
    }

    procesando.value = true
    router.post(route('configuracion.update'), data, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { procesando.value = false },
        onSuccess: () => {
            for (const clave of Object.keys(archivos)) {
                archivos[clave] = null
                borrar[clave] = false
            }
        },
    })
}
</script>

<template>
    <Head title="Configuración" />
    <AppLayout>
        <div class="space-y-6">

            <div>
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Configuración</h1>
                <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">
                    Logo, favicon y los textos de la marca. Los cambios se ven en el acto, sin volver a publicar el sistema.
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-8">
                <div
                    v-for="(titulo, grupo) in grupos"
                    :key="grupo"
                    class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]"
                >
                    <FormSection :title="titulo">
                        <template v-for="[clave, def] in clavesDe(grupo)" :key="clave">

                            <!-- Imagen: vista previa + subida + quitar -->
                            <div v-if="def.tipo === 'imagen'" class="sm:col-span-2">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ def.label }}
                                </label>
                                <div class="flex flex-wrap items-center gap-4">
                                    <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                                        <img
                                            v-if="valores[clave] && !borrar[clave]"
                                            :src="valores[clave] as string"
                                            :alt="def.label"
                                            class="h-full w-full object-contain"
                                        />
                                        <span v-else class="text-theme-xs text-gray-400">Sin cargar</span>
                                    </div>
                                    <div class="min-w-0 flex-1 space-y-2">
                                        <input
                                            type="file"
                                            accept=".png,.jpg,.jpeg,.svg,.webp,.ico"
                                            class="w-full cursor-pointer rounded-lg border border-gray-300 text-sm text-gray-700 shadow-theme-xs file:mr-4 file:cursor-pointer file:border-0 file:bg-gray-100 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-gray-700 dark:border-gray-700 dark:text-gray-300 dark:file:bg-white/[0.05] dark:file:text-gray-300"
                                            @change="onArchivo(clave, $event)"
                                        />
                                        <label
                                            v-if="valores[clave]"
                                            class="flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-400"
                                        >
                                            <input
                                                v-model="borrar[clave]"
                                                type="checkbox"
                                                class="h-4 w-4 rounded accent-brand-500 dark:accent-brand-400"
                                            />
                                            <!-- Genérico: a qué se vuelve al quitarla depende de la
                                                 clave (el ícono por defecto, la foto que trae el
                                                 sistema) y eso lo dice el hint de cada una. -->
                                            Quitar la imagen cargada
                                        </label>
                                    </div>
                                </div>
                                <p v-if="errors[clave]" class="mt-1.5 text-xs text-error-500 dark:text-error-400">
                                    {{ errors[clave] }}
                                </p>
                                <p v-else-if="def.hint" class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ def.hint }}
                                </p>
                            </div>

                            <Textarea
                                v-else-if="def.tipo === 'textarea'"
                                v-model="form[clave]"
                                :label="def.label"
                                :hint="def.hint"
                                :error="errors[clave]"
                                :rows="4"
                                full
                            />

                            <Input
                                v-else
                                v-model="form[clave]"
                                :label="def.label"
                                :hint="def.hint"
                                :error="errors[clave]"
                            />
                        </template>
                    </FormSection>
                </div>

                <div class="flex gap-3">
                    <Button type="submit" variant="primary" :disabled="procesando">
                        {{ procesando ? 'Guardando...' : 'Guardar cambios' }}
                    </Button>
                </div>
            </form>

        </div>
    </AppLayout>
</template>
