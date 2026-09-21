<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Ordenamiento por columna (`?sort=&dir=`) para los listados del panel.
 *
 * Un solo lugar, porque el criterio tiene cuatro partes y es fácil olvidarse de
 * alguna en la pantalla número siete:
 *
 * 1. **Whitelist obligatoria.** El valor viene de la URL. Sin una lista
 *    explícita, `?sort=password` es una inyección de nombre de columna, y
 *    `?sort=sub_total` en Ventas filtraría por ordenamiento justo el dato que
 *    el permiso `ventas.montos` esconde. La clave del arreglo es lo que viaja
 *    en la URL y el valor es la expresión SQL: así la URL nunca expone un
 *    nombre de columna, y una clave puede mapear a varias
 *    (`'nombre' => ['name', 'apellido']`) o a una `Closure` cuando lo que se
 *    ordena no es una columna (una subconsulta, un `withCount`, un orden
 *    lógico con `CASE`).
 * 2. **Caída silenciosa al orden por defecto.** Una clave desconocida **no** es
 *    un 422: los listados se comparten por link y un `sort` de una versión
 *    anterior tiene que seguir abriendo la pantalla, no romperla.
 * 3. **Desempate final por `id`.** Sin él, dos filas con el mismo valor pueden
 *    salir en distinto orden en cada página y la paginación repite o saltea
 *    registros. Ventas y Partidas ya lo hacían a mano.
 * 4. **El orden vigente vuelve a la vista ya validado** (`orden()`), para que
 *    la flecha del encabezado no mienta cuando el `sort` de la URL cayó al
 *    orden por defecto.
 *
 * ⚠️ En los controllers que validan sus filtros (Observaciones, Bitácora,
 * Bajas) hay que sumar `sort` y `dir` a las reglas, o `validate()` los descarta
 * en silencio: el click cambia la URL, la tabla no se mueve y no hay ningún
 * error a la vista. Es la trampa más fácil de este mecanismo.
 *
 * ⚠️ Las columnas de relación se ordenan con **subconsulta en el ORDER BY**, no
 * con un `leftJoin`: la query devuelve el modelo entero, así que un join
 * traería el `id` de la otra tabla pisando el del modelo.
 */
trait OrdenaListados
{
    /**
     * El orden pedido, validado contra la whitelist.
     *
     * `sort` es null cuando el pedido no está en la lista (o no vino), que es
     * la señal de "estás viendo el orden por defecto".
     *
     * @param  array<string, string|array<int, string>|Closure>  $permitidas
     * @return array{sort: string|null, dir: string}
     */
    protected function orden(Request $request, array $permitidas): array
    {
        $sort = $request->string('sort')->trim()->value();

        return [
            'sort' => isset($permitidas[$sort]) ? $sort : null,
            // Cualquier cosa que no sea 'asc' es 'desc'. No se valida con una
            // regla: un `dir` roto no puede romper la pantalla.
            'dir' => $request->string('dir')->trim()->value() === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Aplica el orden pedido sobre la consulta.
     *
     * @param  array<string, string|array<int, string>|Closure>  $permitidas
     * @param  Closure(Builder): mixed  $porDefecto  el orden propio de esa pantalla
     * @param  string  $desempate  columna estable para la paginación; calificarla con la tabla si hay join
     */
    protected function aplicarOrden(
        Builder $query,
        Request $request,
        array $permitidas,
        Closure $porDefecto,
        string $desempate = 'id',
    ): Builder {
        ['sort' => $sort, 'dir' => $dir] = $this->orden($request, $permitidas);

        if ($sort === null) {
            $porDefecto($query);

            return $query->orderByDesc($desempate);
        }

        $criterio = $permitidas[$sort];

        if ($criterio instanceof Closure) {
            $criterio($query, $dir);
        } else {
            foreach ((array) $criterio as $columna) {
                $query->orderBy($columna, $dir);
            }
        }

        // El desempate va en el **mismo sentido** que la columna elegida: si no,
        // dos filas empatadas salen en un orden al ordenar A→Z y en el contrario
        // al invertir, y se lee como si los datos hubieran cambiado.
        return $query->orderBy($desempate, $dir);
    }
}
