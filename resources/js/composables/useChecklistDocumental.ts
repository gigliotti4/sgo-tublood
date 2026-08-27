import { computed, watch, type Ref } from 'vue'
import type { ChecklistFormulario, DocumentoChecklist, DocumentosCargados } from '@/types'

/**
 * Mantiene el checklist de documentación en sincronía con el tipo elegido en
 * el select, dentro del formulario de la ficha.
 *
 * Existe porque el bloque de documentación dejó de tener su propio formulario:
 * el tipo y los documentos se guardan juntos, así que el estado del checklist
 * es un campo más del `useForm` de la página y hay que resembrarlo cada vez que
 * cambia el tipo (cambia el tipo, cambian los documentos que se exigen).
 *
 * Lo comparten la ficha de cliente y la de proveedor: es la misma mecánica y
 * solo cambia de qué columna sale el tipo.
 */
export function useChecklistDocumental(opciones: {
    /** `[slug del tipo => documentos que exige]`, de Documentacion::checklistPorTipo(). */
    catalogo: Record<string, DocumentoChecklist[]>
    /** Lo que el registro ya tiene guardado, por clave de documento. */
    cargados: DocumentosCargados
    /** El tipo elegido en el select (no el guardado). */
    tipo: Ref<string>
    /** El `useForm` de la página. Este composable escribe su campo `documentos`. */
    form: { documentos: ChecklistFormulario }
}) {
    const documentos = computed<DocumentoChecklist[]>(
        () => opciones.catalogo[opciones.tipo.value] ?? [],
    )

    /**
     * Al cambiar el tipo se rearma el checklist con las claves del tipo nuevo.
     *
     * Para cada documento gana, en este orden:
     *   1. lo que la persona ya venía tildando en esta pantalla,
     *   2. lo que el registro tiene guardado,
     *   3. "no presentado".
     *
     * El punto 1 es lo que hace que probar un tipo y volver al anterior no
     * pierda lo que se venía cargando. Y como las claves de documento **se
     * repiten entre tipos a propósito** (`constancia_arca` es el mismo papel en
     * todos), reclasificar tampoco pierde lo que los dos tipos comparten.
     */
    watch(documentos, (defs) => {
        const enPantalla = opciones.form.documentos

        opciones.form.documentos = Object.fromEntries(defs.map((def) => {
            const yaTocado = enPantalla?.[def.documento]

            if (yaTocado) return [def.documento, yaTocado]

            const guardado = opciones.cargados[def.documento]

            return [def.documento, {
                presentado: guardado?.presentado ? '1' : '0',
                fecha_vencimiento: guardado?.fecha_vencimiento ?? '',
            }]
        }))
    }, { immediate: true })

    return { documentos }
}
