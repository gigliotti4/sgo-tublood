<script lang="ts">
/** Un renglón de producto afectado tal como lo arma el formulario. */
export interface ProductoForm {
    producto: string
    codigo: string
    cantidad_afectada: number | null
    tipo_presentacion: string
    lote: string
    fecha_vencimiento: string
    numero_remito: string
    tipo_comprobante: string
}

export const nuevoProducto = (): ProductoForm => ({
    producto: '',
    codigo: '',
    cantidad_afectada: null,
    tipo_presentacion: '',
    lote: '',
    fecha_vencimiento: '',
    numero_remito: '',
    tipo_comprobante: '',
})
</script>

<script setup lang="ts">
/**
 * La lista repetible de productos afectados.
 *
 * Nació adentro de `CrearInterna.vue` para la Falla de producto; el 21/10/2026
 * la necesitaron también 6.2 "Falla de proveedor" y los afectados de una No
 * Conformidad, y se sacó acá para no tener tres copias del mismo bloque.
 *
 * - `obligatorios`: true en la Falla de producto (asteriscos en código,
 *   nombre, cantidad, presentación, lote y vencimiento, y nunca menos de un
 *   renglón); false en los opcionales, donde alcanza con código o nombre y la
 *   cantidad, y la lista puede quedar vacía. Los asteriscos son solo la pista:
 *   la regla de verdad la valida el backend (`ReglasObservacion`).
 * - `conComprobante`: el remito y el tipo de comprobante, que en una NC no
 *   aplican.
 */
import Input from '@/Components/Input.vue'
import InputFecha from '@/Components/InputFecha.vue'
import RadioGroup from '@/Components/RadioGroup.vue'
import Select from '@/Components/Select.vue'
import SelectorArticulo from '@/Components/SelectorArticulo.vue'

const productos = defineModel<ProductoForm[]>({ required: true })

const props = withDefaults(defineProps<{
    presentaciones: Record<string, string>
    errors: Record<string, string | undefined>
    obligatorios?: boolean
    conComprobante?: boolean
    /** Prefijo de los errores del backend (`productos.0.lote`). */
    campo?: string
    disabled?: boolean
}>(), {
    obligatorios: true,
    conComprobante: true,
    campo: 'productos',
    disabled: false,
})

const error = (index: number, campo: keyof ProductoForm) => props.errors[`${props.campo}.${index}.${campo}`]

const agregar = () => productos.value.push(nuevoProducto())
const quitar = (index: number) => productos.value.splice(index, 1)

// Con obligatorios no se puede quedar en cero: el tipo exige al menos uno.
const puedeQuitar = () => !props.disabled && (!props.obligatorios || productos.value.length > 1)
</script>

<template>
    <div class="space-y-4">
        <p v-if="!productos.length" class="text-theme-sm text-gray-400">
            No hay productos cargados.
        </p>

        <div
            v-for="(producto, index) in productos"
            :key="index"
            class="space-y-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/[0.02]"
        >
            <div class="flex items-center justify-between">
                <span class="text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Producto {{ index + 1 }}
                </span>
                <button
                    v-if="puedeQuitar()"
                    type="button"
                    class="inline-flex min-h-11 -mx-2 items-center px-2 cursor-pointer text-theme-xs font-medium text-error-500 hover:text-error-600 dark:text-error-400 dark:hover:text-error-300"
                    @click="quitar(index)"
                >
                    Quitar
                </button>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <SelectorArticulo
                    v-model="producto.codigo"
                    :required="obligatorios"
                    label="Código de producto"
                    hint="Buscá por código o nombre. Si no está en el catálogo, escribilo igual."
                    :error="error(index, 'codigo')"
                    @seleccionar="a => producto.producto = a.descripcion"
                />
                <Input
                    v-model="producto.producto"
                    :required="obligatorios"
                    label="Nombre de producto"
                    :error="error(index, 'producto')"
                />
                <Input
                    v-model.number="producto.cantidad_afectada"
                    required
                    type="number"
                    min="1"
                    label="Cantidad afectada"
                    :error="error(index, 'cantidad_afectada')"
                />
                <Select
                    v-model="producto.tipo_presentacion"
                    :required="obligatorios"
                    label="Presentación"
                    hint="A qué corresponde la cantidad afectada."
                    :error="error(index, 'tipo_presentacion')"
                >
                    <option value="" :disabled="obligatorios">— Seleccionar —</option>
                    <option v-for="(label, key) in presentaciones" :key="key" :value="key">{{ label }}</option>
                </Select>
                <Input
                    v-model="producto.lote"
                    :required="obligatorios"
                    label="Lote"
                    :error="error(index, 'lote')"
                />
                <InputFecha
                    v-model="producto.fecha_vencimiento"
                    :required="obligatorios"
                    label="Fecha de vencimiento"
                    :error="error(index, 'fecha_vencimiento')"
                />
                <template v-if="conComprobante">
                    <Input
                        v-model="producto.numero_remito"
                        label="N° de remito"
                        :error="error(index, 'numero_remito')"
                    />
                    <RadioGroup
                        v-model="producto.tipo_comprobante"
                        full
                        label="Tipo de comprobante"
                        :opciones="[
                            { value: 'factura', label: 'Factura' },
                            { value: 'remito', label: 'Remito' },
                        ]"
                        :error="error(index, 'tipo_comprobante')"
                    />
                </template>
            </div>
        </div>

        <button
            v-if="!disabled"
            type="button"
            class="inline-flex min-h-11 -mx-2 items-center px-2 cursor-pointer text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
            @click="agregar"
        >
            + Agregar producto
        </button>
    </div>
</template>
