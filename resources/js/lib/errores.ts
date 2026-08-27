/**
 * Lectura de los errores de validación que devuelve Laravel para los campos
 * que son listas (los archivos adjuntos y los productos de una observación).
 *
 * Existe por un bug concreto: las reglas de archivos son `attachments.*`, así
 * que los errores vuelven en `attachments.0`, `attachments.1`… y las pantallas
 * mostraban únicamente `form.errors.attachments` — la clave del array, que solo
 * falla con la regla `array` y por lo tanto nunca. Resultado: alguien adjuntaba
 * un archivo pesado o de un formato no permitido, el formulario se negaba a
 * enviarse y **no aparecía un solo mensaje en pantalla**.
 *
 * Vive acá y no en cada pantalla porque lo usan los dos formularios de alta
 * (portal e interna) y los dos componentes de adjuntos del panel. Mismo criterio
 * que `lib/productos.ts` y `lib/bitacora.ts`.
 */

/** Los errores tal como los expone `useForm` de Inertia. */
export type ErroresDeFormulario = Record<string, string>

/**
 * Los errores de una lista de archivos, indexados por su posición, para poder
 * mostrar cada mensaje al lado del archivo que lo causó.
 *
 * @param campo `attachments` en las altas, `archivos` en la bitácora y en la
 *              subida suelta de un caso.
 */
export function erroresDeArchivos(
    errors: ErroresDeFormulario,
    campo = 'attachments',
): Record<number, string> {
    const salida: Record<number, string> = {}

    for (const [clave, mensaje] of Object.entries(errors ?? {})) {
        const match = clave.match(new RegExp(`^${campo}\\.(\\d+)$`))

        if (match) salida[Number(match[1])] = mensaje
    }

    return salida
}

/**
 * Todos los mensajes de error de un formulario, para el resumen de arriba.
 *
 * Se deduplican: cuando fallan tres productos por el mismo motivo, repetir el
 * mismo texto tres veces no agrega nada y alarga una caja que se quiere leer de
 * un vistazo. El detalle por campo ya está abajo, en cada input.
 */
export function mensajesDeError(errors: ErroresDeFormulario): string[] {
    return [...new Set(Object.values(errors ?? {}).filter(Boolean))]
}
