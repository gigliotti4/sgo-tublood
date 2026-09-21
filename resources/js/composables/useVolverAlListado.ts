import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Transporte de los filtros del listado hasta la ficha de edición y de vuelta.
 *
 * Dos mitades del mismo mecanismo, y por eso comparten archivo: separadas, el
 * formato de `volver` quedaría escrito en dos lados y se desincronizaría. La
 * contraparte de backend es el trait
 * `App\Http\Controllers\Concerns\VuelveAlListado`.
 *
 * `volver` es una **cadena opaca**: la query de la URL del listado, tal cual.
 * Cada capa la codifica o decodifica **exactamente una vez** (Ziggy al meterla
 * en `?volver=`, Laravel al leerla con `input()`), así que un filtro que
 * contenga `&` o `#` sobrevive el viaje de ida y vuelta.
 */

/** La query de una URL de Inertia: `page.url` es path + query, sin el origen. */
const queryDe = (url: string): string => {
    const pregunta = url.indexOf('?')

    return pregunta === -1 ? '' : url.slice(pregunta + 1)
}

/** Agrega `volver` a los parámetros de una ruta, salvo que no haya nada que llevar. */
const conVolver = (
    ruta: string,
    parametros: Record<string, string | number>,
    volver: string,
): string => route(ruta, volver === '' ? parametros : { ...parametros, volver })

/**
 * La mitad del **listado**: arma el link a la ficha llevándose la URL vigente.
 *
 * Lee `usePage().url` y no `window.location` para seguir a las navegaciones de
 * Inertia — filtrar y ordenar hacen `router.get` con `replace: true`, que
 * cambian la URL sin recargar la página.
 */
export function useIrALaFicha() {
    const page = usePage()

    const volver = computed(() => queryDe(page.url))

    return {
        aLaFicha: (ruta: string, parametros: Record<string, string | number>): string =>
            conVolver(ruta, parametros, volver.value),
    }
}

/**
 * La mitad de la **ficha**: de dónde vino y a dónde vuelve.
 *
 * `volver` se lee de la propia URL de la ficha y no de una prop del controller:
 * así sobrevive a las acciones internas de la ficha (subir o borrar un adjunto,
 * que vuelven con `back()`) y a un F5, sin que `edit()` tenga que saber nada.
 */
export function useVolverAlListado(rutaListado: string) {
    const page = usePage()

    const volver = computed(() => new URLSearchParams(queryDe(page.url)).get('volver') ?? '')

    /**
     * Se concatena en vez de rearmarse con `route(ruta, params)`: `volver` es
     * una query **ya codificada** que salió de nuestra propia URL, y
     * reparsearla acá perdería las claves repetidas (`anio[]=25&anio[]=26`).
     */
    const urlListado = computed(() =>
        volver.value === '' ? route(rutaListado) : `${route(rutaListado)}?${volver.value}`)

    return {
        volver,
        urlListado,
        /** La URL del `form.put()`: es la que le dice al controller a dónde devolver. */
        aGuardar: (ruta: string, parametros: Record<string, string | number>): string =>
            conVolver(ruta, parametros, volver.value),
    }
}
