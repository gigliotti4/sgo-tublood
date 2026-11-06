<script setup lang="ts">
import { computed, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { usePermissions } from '@/composables/usePermissions'
import Badge from '@/Components/Badge.vue'
import Button from '@/Components/Button.vue'
import Icon from '@/Components/Icon.vue'
import Input from '@/Components/Input.vue'
import Modal from '@/Components/Modal.vue'
import Select from '@/Components/Select.vue'
import Textarea from '@/Components/Textarea.vue'

interface Valor { mes: string; valor: number }

interface Criterios {
    titulo_contiene?: string
    titulo_no_contiene?: string
    motivo?: string[]
    motivo_no?: string[]
}

interface Indicador {
    id: number
    sector: string
    fallo: string
    origen_dato: 'sgo' | 'manual'
    fuente: string | null
    tipos: string[] | null
    criterios: Criterios | null
    cant_pedidos: number | null
    objetivo: number
    tolerancia: number
    tipo_meta: 'maximo' | 'minimo'
    descripcion: string | null
    estado: string
    importancia: number | null
    notas: string | null
    activo: boolean
    valores: Valor[]
}

interface TipoIncidencia { clave: string; codigo: string; label: string; obsoleto: boolean }

interface FilaPrevia {
    fila: number
    sector: string
    fallo: string
    estado: 'nuevo' | 'cambiado' | 'igual' | 'error'
    tipo_meta: string
    origen_dato: string
    objetivo: number | null
    tolerancia: number | null
    cambios: { campo: string; antes: unknown; despues: unknown }[]
    errores: string[]
    avisos: string[]
}

interface Previa {
    token: string
    archivo: string
    hoja: string
    errores: string[]
    resumen: Record<string, number>
    filas: FilaPrevia[]
    aplicable: boolean
}

interface Carga {
    id: number
    archivo: string
    created_at: string
    resumen: Record<string, number> | null
    usuario: { name: string; apellido: string | null } | null
}

const props = defineProps<{
    indicadores: Indicador[]
    cargas: Carga[]
    previa: Previa | null
    tipos: TipoIncidencia[]
    sectores: { id: number; nombre: string }[]
    tiposMeta: Record<string, string>
    estados: Record<string, string>
}>()

const { hasPermission } = usePermissions()
const puedeEditar = computed(() => hasPermission('objetivos.edit'))

// ── Agrupado por sector ─────────────────────────────────────────────
const porSector = computed(() => {
    const grupos = new Map<string, Indicador[]>()
    for (const i of props.indicadores) {
        if (!grupos.has(i.sector)) grupos.set(i.sector, [])
        grupos.get(i.sector)!.push(i)
    }

    return [...grupos.entries()].map(([sector, items]) => ({
        sector,
        items,
        // La importancia de un sector tiene que sumar 100 si se cargó en todos.
        importancia: items.every(i => i.importancia !== null)
            ? Math.round(items.reduce((s, i) => s + (i.importancia ?? 0), 0) * 100) / 100
            : null,
    }))
})

const fmt = (n: number | null | undefined) =>
    n === null || n === undefined ? '–' : new Intl.NumberFormat('es-AR', { maximumFractionDigits: 2 }).format(n)

const etiquetaTipo = (clave: string) => {
    const t = props.tipos.find(x => x.clave === clave)

    return t ? `${t.codigo} ${t.label}` : clave
}

// ── Subir planilla (paso 1: vista previa) ───────────────────────────
const subida = useForm<{ archivo: File | null }>({ archivo: null })
const inputArchivo = ref<HTMLInputElement | null>(null)

const elegirArchivo = (e: Event) => {
    subida.archivo = (e.target as HTMLInputElement).files?.[0] ?? null

    if (subida.archivo) {
        subida.post(route('objetivos.previsualizar'), {
            forceFormData: true,
            onFinish: () => {
                subida.reset()
                if (inputArchivo.value) inputArchivo.value.value = ''
            },
        })
    }
}

// ── Confirmar (paso 2) ──────────────────────────────────────────────
const aplicando = ref(false)

const aplicar = () => {
    if (!props.previa) return

    router.post(route('objetivos.aplicar'), { token: props.previa.token, archivo: props.previa.archivo }, {
        preserveScroll: true,
        onStart: () => { aplicando.value = true },
        onFinish: () => { aplicando.value = false },
    })
}

const descartar = () => router.get(route('objetivos.index'), {}, { preserveScroll: true })

const estadoPrevia: Record<string, { texto: string; variante: 'emerald' | 'blue' | 'slate' | 'red' }> = {
    nuevo: { texto: 'Nuevo', variante: 'emerald' },
    cambiado: { texto: 'Cambia', variante: 'blue' },
    igual: { texto: 'Sin cambios', variante: 'slate' },
    error: { texto: 'Con error', variante: 'red' },
}

// ── Alta / edición ──────────────────────────────────────────────────
const editando = ref<Indicador | null>(null)
const creando = ref(false)

const form = useForm({
    sector: '',
    fallo: '',
    tipo_meta: 'maximo',
    objetivo: null as number | null,
    tolerancia: null as number | null,
    estado: 'aprobado',
    importancia: null as number | null,
    cant_pedidos: null as number | null,
    tipos: [] as string[],
    titulo_contiene: '',
    titulo_no_contiene: '',
    motivo: '',
    motivo_no: '',
    descripcion: '',
    notas: '',
    activo: true,
})

const lista = (s: string) => s.split(',').map(x => x.trim()).filter(Boolean)

const abrirNuevo = () => {
    form.reset()
    form.clearErrors()
    editando.value = null
    creando.value = true
}

const abrirEdicion = (i: Indicador) => {
    form.clearErrors()
    form.sector = i.sector
    form.fallo = i.fallo
    form.tipo_meta = i.tipo_meta
    form.objetivo = i.objetivo
    form.tolerancia = i.tolerancia
    form.estado = i.estado
    form.importancia = i.importancia
    form.cant_pedidos = i.cant_pedidos
    form.tipos = [...(i.tipos ?? [])]
    form.titulo_contiene = i.criterios?.titulo_contiene ?? ''
    form.titulo_no_contiene = i.criterios?.titulo_no_contiene ?? ''
    form.motivo = (i.criterios?.motivo ?? []).join(', ')
    form.motivo_no = (i.criterios?.motivo_no ?? []).join(', ')
    form.descripcion = i.descripcion ?? ''
    form.notas = i.notas ?? ''
    form.activo = i.activo
    creando.value = false
    editando.value = i
}

const cerrar = () => {
    editando.value = null
    creando.value = false
}

const guardar = () => {
    form.transform(d => ({
        sector: d.sector,
        fallo: d.fallo,
        tipo_meta: d.tipo_meta,
        objetivo: d.objetivo,
        tolerancia: d.tolerancia,
        estado: d.estado,
        importancia: d.importancia,
        cant_pedidos: d.cant_pedidos,
        tipos: d.tipos,
        criterios: {
            titulo_contiene: d.titulo_contiene || null,
            titulo_no_contiene: d.titulo_no_contiene || null,
            motivo: lista(d.motivo),
            motivo_no: lista(d.motivo_no),
        },
        descripcion: d.descripcion || null,
        notas: d.notas || null,
        activo: d.activo,
    }))

    if (creando.value) {
        form.post(route('objetivos.store'), { preserveScroll: true, onSuccess: cerrar })
    } else if (editando.value) {
        form.put(route('objetivos.update', editando.value.id), { preserveScroll: true, onSuccess: cerrar })
    }
}

// ── Valores mensuales (indicadores que no salen del SGO) ────────────
const cargando = ref<Indicador | null>(null)
const valoresForm = useForm<{ valores: Record<string, number | string | null> }>({ valores: {} })

const mesesDe = (i: Indicador): string[] => {
    const meses = new Set(i.valores.map(v => v.mes))
    const hoy = new Date()

    // Siempre se ofrecen los últimos 12 meses además de los ya cargados.
    for (let k = 0; k < 12; k++) {
        const d = new Date(hoy.getFullYear(), hoy.getMonth() - k, 1)
        meses.add(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`)
    }

    return [...meses].sort().reverse()
}

const etiquetaMes = (mes: string) => {
    const [a, m] = mes.split('-')

    return new Date(Number(a), Number(m) - 1, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' })
}

const abrirValores = (i: Indicador) => {
    const actuales = Object.fromEntries(i.valores.map(v => [v.mes, v.valor]))
    valoresForm.valores = Object.fromEntries(mesesDe(i).map(m => [m, actuales[m] ?? '']))
    valoresForm.clearErrors()
    cargando.value = i
}

const guardarValores = () => {
    if (!cargando.value) return

    valoresForm.put(route('objetivos.valores', cargando.value.id), {
        preserveScroll: true,
        onSuccess: () => { cargando.value = null },
    })
}
</script>

<template>
    <Head title="Objetivos" />

    <AppLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Objetivos de gestión</h1>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                    Cuántos fallos de cada tipo tolera cada sector por mes. El tablero los compara con lo
                    que se cargó: verde hasta el objetivo, amarillo hasta la tolerancia, rojo por encima.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a
                    :href="route('objetivos.export')"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                >
                    Descargar vigentes
                </a>
                <template v-if="puedeEditar">
                    <input ref="inputArchivo" type="file" accept=".xlsx,.xls" class="hidden" @change="elegirArchivo" />
                    <Button variant="outline" :disabled="subida.processing" @click="inputArchivo?.click()">
                        {{ subida.processing ? 'Leyendo…' : 'Subir planilla' }}
                    </Button>
                    <Button variant="primary" @click="abrirNuevo">Nuevo indicador</Button>
                </template>
            </div>
        </div>

        <p v-if="subida.errors.archivo" class="mb-4 text-sm text-error-600 dark:text-error-400">{{ subida.errors.archivo }}</p>

        <!-- Vista previa de la planilla subida: no se escribió nada todavía -->
        <section
            v-if="previa"
            class="mb-6 rounded-2xl border border-brand-200 bg-brand-50/40 p-5 dark:border-brand-500/30 dark:bg-brand-500/[0.06]"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">
                        Vista previa: {{ previa.archivo }}
                    </h2>
                    <p class="mt-1 text-theme-sm text-gray-600 dark:text-gray-400">
                        Hoja «{{ previa.hoja }}». Todavía no se guardó nada: revisá qué cambia y confirmá.
                    </p>
                </div>
                <div class="flex gap-3">
                    <Button variant="primary" :disabled="!previa.aplicable || aplicando" @click="aplicar">
                        {{ aplicando ? 'Aplicando…' : 'Confirmar y aplicar' }}
                    </Button>
                    <Button variant="outline" @click="descartar">Descartar</Button>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <Badge variant="emerald">{{ previa.resumen.nuevos }} nuevos</Badge>
                <Badge variant="blue">{{ previa.resumen.cambiados }} con cambios</Badge>
                <Badge variant="slate">{{ previa.resumen.sin_cambios }} sin cambios</Badge>
                <Badge v-if="previa.resumen.con_errores" variant="red">{{ previa.resumen.con_errores }} con errores</Badge>
                <Badge v-if="previa.resumen.valores" variant="indigo">{{ previa.resumen.valores }} valores mensuales</Badge>
            </div>

            <ul v-if="previa.errores.length" class="mt-3 list-disc space-y-1 pl-5 text-sm text-error-600 dark:text-error-400">
                <li v-for="e in previa.errores" :key="e">{{ e }}</li>
            </ul>

            <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-gray-100 text-theme-xs uppercase text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="px-4 py-2.5">Fila</th>
                            <th class="px-4 py-2.5">Sector</th>
                            <th class="px-4 py-2.5">Fallo</th>
                            <th class="px-4 py-2.5">Objetivo / tolerancia</th>
                            <th class="px-4 py-2.5">Estado</th>
                            <th class="px-4 py-2.5">Detalle</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="f in previa.filas" :key="f.fila" class="align-top">
                            <td class="px-4 py-2.5 text-theme-sm text-gray-500">{{ f.fila }}</td>
                            <td class="px-4 py-2.5 text-theme-sm text-gray-800 dark:text-white/90">{{ f.sector }}</td>
                            <td class="px-4 py-2.5 text-theme-sm text-gray-800 dark:text-white/90">{{ f.fallo }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ fmt(f.objetivo) }} / {{ fmt(f.tolerancia) }}
                            </td>
                            <td class="px-4 py-2.5">
                                <Badge :variant="estadoPrevia[f.estado].variante">{{ estadoPrevia[f.estado].texto }}</Badge>
                            </td>
                            <td class="px-4 py-2.5 text-theme-xs text-gray-600 dark:text-gray-400">
                                <p v-for="e in f.errores" :key="e" class="text-error-600 dark:text-error-400">{{ e }}</p>
                                <p v-for="c in f.cambios" :key="c.campo">
                                    {{ c.campo }}: {{ c.antes ?? '–' }} → <strong>{{ c.despues }}</strong>
                                </p>
                                <p v-for="a in f.avisos" :key="a" class="text-warning-600 dark:text-warning-400">{{ a }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Indicadores vigentes, agrupados por sector -->
        <div v-if="!porSector.length" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-400 dark:border-gray-800 dark:bg-white/[0.03]">
            Todavía no hay objetivos cargados. Subí la planilla de objetivos para empezar.
        </div>

        <section
            v-for="g in porSector"
            :key="g.sector"
            class="mb-5 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
        >
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ g.sector }}</h2>
                <span
                    v-if="g.importancia !== null"
                    class="text-theme-xs"
                    :class="Math.abs(g.importancia - 100) > 0.5 ? 'text-error-600 dark:text-error-400' : 'text-gray-500 dark:text-gray-400'"
                >
                    Importancia: {{ fmt(g.importancia) }} %
                </span>
            </header>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-theme-xs uppercase text-gray-500 dark:text-gray-400">
                            <th class="px-5 py-2.5">Fallo</th>
                            <th class="px-5 py-2.5">Meta</th>
                            <th class="px-5 py-2.5">Objetivo</th>
                            <th class="px-5 py-2.5">Tolerancia</th>
                            <th class="px-5 py-2.5">Origen del dato</th>
                            <th class="px-5 py-2.5">Estado</th>
                            <th v-if="puedeEditar" class="px-5 py-2.5"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr v-for="i in g.items" :key="i.id" :class="{ 'opacity-50': !i.activo }">
                            <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                {{ i.fallo }}
                                <p v-if="i.descripcion" class="text-theme-xs text-gray-500 dark:text-gray-400">{{ i.descripcion }}</p>
                            </td>
                            <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ tiposMeta[i.tipo_meta] }}</td>
                            <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">{{ fmt(i.objetivo) }}</td>
                            <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">{{ fmt(i.tolerancia) }}</td>
                            <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                <template v-if="i.origen_dato === 'sgo'">
                                    <Badge variant="indigo">SGO</Badge>
                                    <span class="text-theme-xs">{{ (i.tipos ?? []).map(etiquetaTipo).join(' · ') || 'sin tipos' }}</span>
                                </template>
                                <template v-else>
                                    <Badge variant="amber">Manual</Badge>
                                    <span class="text-theme-xs">{{ i.valores.length }} meses cargados</span>
                                </template>
                            </td>
                            <td class="px-5 py-3">
                                <Badge :variant="i.estado === 'aprobado' ? 'emerald' : 'amber'">{{ estados[i.estado] ?? i.estado }}</Badge>
                            </td>
                            <td v-if="puedeEditar" class="whitespace-nowrap px-5 py-3 text-right">
                                <button
                                    v-if="i.origen_dato === 'manual'"
                                    type="button"
                                    class="mr-2 cursor-pointer rounded-lg px-2 py-1 text-theme-xs font-medium text-brand-500 hover:bg-gray-100 dark:text-brand-300 dark:hover:bg-white/[0.05]"
                                    @click="abrirValores(i)"
                                >
                                    Cargar valores
                                </button>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-brand-500 dark:hover:bg-white/[0.05] dark:hover:text-brand-300"
                                    title="Editar"
                                    @click="abrirEdicion(i)"
                                >
                                    <Icon name="pencil" class="h-4.5 w-4.5" />
                                    <span class="sr-only">Editar {{ i.fallo }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Historial de planillas -->
        <section v-if="cargas.length" class="mt-8">
            <h2 class="mb-2 text-sm font-semibold text-gray-800 dark:text-white/90">Planillas aplicadas</h2>
            <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-white/[0.03]">
                <li v-for="c in cargas" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-theme-sm">
                    <span class="text-gray-800 dark:text-white/90">{{ c.archivo }}</span>
                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                        {{ new Date(c.created_at).toLocaleString('es-AR') }}
                        · {{ c.usuario ? `${c.usuario.name} ${c.usuario.apellido ?? ''}`.trim() : 'sistema' }}
                        <template v-if="c.resumen">
                            · {{ c.resumen.nuevos ?? 0 }} nuevos, {{ c.resumen.actualizados ?? 0 }} actualizados
                        </template>
                    </span>
                </li>
            </ul>
        </section>

        <!-- Alta / edición de un indicador -->
        <Modal :show="creando || editando !== null" :title="creando ? 'Nuevo indicador' : 'Editar indicador'" size="lg" @close="cerrar">
            <form class="space-y-4" @submit.prevent="guardar">
                <div class="grid gap-4 sm:grid-cols-2">
                    <Input v-model="form.sector" label="Sector" :error="form.errors.sector" required />
                    <Input v-model="form.fallo" label="Fallo" :error="form.errors.fallo" required />
                    <Select v-model="form.tipo_meta" label="Tipo de meta" :error="form.errors.tipo_meta">
                        <option v-for="(label, clave) in tiposMeta" :key="clave" :value="clave">{{ label }}</option>
                    </Select>
                    <Select v-model="form.estado" label="Estado" :error="form.errors.estado">
                        <option v-for="(label, clave) in estados" :key="clave" :value="clave">{{ label }}</option>
                    </Select>
                    <Input v-model="form.objetivo" type="number" step="any" label="Objetivo mensual" :error="form.errors.objetivo" required />
                    <Input v-model="form.tolerancia" type="number" step="any" label="Tolerancia" :error="form.errors.tolerancia" required />
                    <Input v-model="form.importancia" type="number" step="any" label="% de importancia" hint="Opcional. Dentro de un sector tiene que sumar 100." :error="form.errors.importancia" />
                    <Input v-model="form.cant_pedidos" type="number" step="any" label="Pedidos base" hint="Solo si el objetivo escala con los pedidos del mes." :error="form.errors.cant_pedidos" />
                </div>

                <fieldset class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <legend class="px-1 text-sm font-medium text-gray-700 dark:text-gray-300">Qué observaciones cuenta</legend>
                    <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                        Sin ningún tipo tildado, el indicador es manual y sus valores se cargan mes a mes.
                    </p>
                    <div class="grid max-h-48 gap-1 overflow-y-auto sm:grid-cols-2">
                        <label
                            v-for="t in tipos"
                            :key="t.clave"
                            class="flex cursor-pointer items-start gap-2 text-theme-xs text-gray-700 dark:text-gray-300"
                        >
                            <input v-model="form.tipos" type="checkbox" :value="t.clave" class="mt-0.5 h-4 w-4 rounded accent-brand-500" />
                            <span>
                                {{ t.codigo }} {{ t.label }}
                                <em v-if="t.obsoleto" class="text-gray-400">(retirado)</em>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.tipos" class="mt-1 text-xs text-error-500">{{ form.errors.tipos }}</p>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Input v-model="form.titulo_contiene" label="El título contiene" hint="Para partir un tipo en dos indicadores." />
                    <Input v-model="form.titulo_no_contiene" label="El título NO contiene" />
                    <Input v-model="form.motivo" label="Motivo es alguno de" hint="Separados por coma." />
                    <Input v-model="form.motivo_no" label="Motivo no es ninguno de" hint="Separados por coma. Exige que el caso tenga motivo." />
                </div>

                <Input v-model="form.descripcion" label="Indicador (cómo se mide)" />
                <Textarea v-model="form.notas" label="Notas" :error="form.errors.notas" />

                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                    <input v-model="form.activo" type="checkbox" class="h-4 w-4 rounded accent-brand-500 dark:accent-brand-400" />
                    Activo
                </label>

                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="form.processing">Guardar</Button>
                    <Button variant="outline" @click="cerrar">Cancelar</Button>
                </div>
            </form>
        </Modal>

        <!-- Valores mensuales de un indicador manual -->
        <Modal :show="cargando !== null" :title="cargando ? `Valores: ${cargando.fallo}` : ''" @close="cargando = null">
            <form class="space-y-3" @submit.prevent="guardarValores">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Cuántos fallos hubo en cada mes. Un mes vacío se considera sin dato.
                </p>
                <div class="max-h-80 space-y-2 overflow-y-auto pr-1">
                    <div v-for="(_, mes) in valoresForm.valores" :key="mes" class="flex items-center gap-3">
                        <span class="w-40 text-sm capitalize text-gray-700 dark:text-gray-300">{{ etiquetaMes(String(mes)) }}</span>
                        <Input v-model="valoresForm.valores[mes]" type="number" step="any" class="flex-1" />
                    </div>
                </div>
                <div class="flex gap-3 pt-2">
                    <Button type="submit" variant="primary" :disabled="valoresForm.processing">Guardar</Button>
                    <Button variant="outline" @click="cargando = null">Cancelar</Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
