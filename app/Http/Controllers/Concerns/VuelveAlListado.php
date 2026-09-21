<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vuelta al listado filtrado después de guardar una ficha de edición.
 *
 * Los filtros, el orden y la página viven en la URL del listado, pero la ficha
 * es otra URL: sin transportarlos, el `redirect()->route('x.index')` del
 * `update()` devuelve a la página 1 sin filtros, y el "← Volver" de la ficha
 * hace lo mismo. Es la otra mitad del problema que `back()` resuelve para las
 * acciones que se disparan desde el propio listado (modales, import, sync).
 *
 * El transporte es un parámetro opaco, `volver`, que lleva la query del listado
 * tal cual estaba: lo pone el link "Editar" del listado y lo devuelve el
 * formulario de la ficha. La mitad de frontend está en
 * [useVolverAlListado.ts](resources/js/composables/useVolverAlListado.ts).
 *
 * ⚠️ **No hay open redirect posible**: la URL base sale siempre de `route()`, y
 * lo que venga en `volver` se **reparsea y se vuelve a armar** como query —
 * nunca se concatena crudo. Concatenar dejaría pasar saltos de línea al header
 * `Location` y un `#` que corte la URL. Con `parse_str()` + `route()` lo peor
 * que se puede lograr es ensuciar la query de nuestro propio listado, que la
 * ignora: los filtros que no entiende no existen, y un `sort` desconocido cae
 * en silencio al orden por defecto (ver el trait `OrdenaListados`).
 *
 * ⚠️ Sirve solo para rutas **sin parámetros de ruta** — los `x.index` del
 * panel. Si la ruta destino tuviera uno, una clave homónima dentro de `volver`
 * se lo comería como segmento del path.
 *
 * ⚠️ La alternativa descartada fue guardar la URL del listado en la sesión
 * desde `edit()`: es menos código, pero dos pestañas comparten la misma clave y
 * la segunda ficha que se abre le pisa la vuelta a la primera **sin que nada lo
 * avise**. Además la ficha necesita el dato igual para dibujar su "← Volver",
 * así que el frontend había que tocarlo de todos modos.
 */
trait VuelveAlListado
{
    /**
     * Más que esto no es la query de un listado, es alguien probando cosas.
     *
     * Se ignora **entero** en vez de recortarlo: un recorte a la mitad de un
     * `%XX` deja basura, y caer en el listado pelado es una degradación
     * perfectamente usable.
     */
    private const LARGO_MAXIMO_VOLVER = 1000;

    /** Vuelve al listado conservando los filtros con los que el usuario llegó. */
    protected function alListado(Request $request, string $ruta): RedirectResponse
    {
        return redirect()->route($ruta, $this->parametrosDeVuelta($request));
    }

    /**
     * La query del listado del que vino el usuario, ya reparseada.
     *
     * @return array<string, mixed> vacío si no vino nada usable
     */
    protected function parametrosDeVuelta(Request $request): array
    {
        $volver = trim((string) $request->input('volver', ''));

        if ($volver === '' || strlen($volver) > self::LARGO_MAXIMO_VOLVER) {
            return [];
        }

        // `ltrim` por si alguien arma el link a mano con el '?' adentro.
        parse_str(ltrim($volver, '?&'), $parametros);

        return $parametros;
    }
}
