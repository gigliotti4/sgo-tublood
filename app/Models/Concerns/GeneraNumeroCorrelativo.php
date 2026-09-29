<?php

namespace App\Models\Concerns;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Numeración correlativa con reset anual y tolerancia a la concurrencia.
 *
 * Lo comparten `Observacion` (`0001-26`) y `NoConformidad` (`NC-0001-26`). Vive
 * en un trait y no duplicado en cada modelo porque cada una de las tres reglas
 * de abajo se aprendió rompiendo algo, y dos copias se desincronizan.
 *
 * El modelo que lo use tiene que tener una columna `numero` con índice único y
 * una columna `anio`. Si numera con prefijo, redefine `prefijoDeNumero()`.
 */
trait GeneraNumeroCorrelativo
{
    /**
     * Prefijo constante del número, incluido el separador. Vacío = sin prefijo.
     *
     * Tiene que ser **constante entre registros del mismo año**: el correlativo
     * sale del máximo alfabético, y un prefijo variable rompería ese orden.
     */
    protected static function prefijoDeNumero(): string
    {
        return '';
    }

    /**
     * Siguiente número de la serie de ese año.
     *
     * ⚠️ `withTrashed()` es obligatorio: sin él, borrar un registro libera su
     * lugar en el correlativo y la próxima alta repite un `numero` que es
     * `unique()` en el schema — un 500 en el portal público.
     *
     * ⚠️ Sale del **máximo** y no de `count()`: contar da el número correcto
     * solo mientras la serie no tenga huecos, y un borrado definitivo (fuera
     * del soft delete, que `withTrashed()` sí cubre) deja uno. El formato está
     * zero-padded y el prefijo es constante, así que el máximo alfabético es el
     * máximo numérico.
     *
     * Los `numero` en `null` (una NC en borrador todavía no tiene) no molestan:
     * `MAX()` los ignora.
     */
    public static function generarNumero(int $anio): string
    {
        $prefijo = static::prefijoDeNumero();

        $ultimo = static::withTrashed()->where('anio', $anio)->max('numero');

        $correlativo = $ultimo ? ((int) substr($ultimo, strlen($prefijo), 4)) + 1 : 1;

        return sprintf('%s%04d-%02d', $prefijo, $correlativo, $anio % 100);
    }

    /**
     * Corre dentro de una transacción la operación que asigna el número,
     * reintentando si dos simultáneas se pelean el mismo.
     *
     * Entre que `generarNumero()` lee el máximo y el INSERT/UPDATE lo escribe
     * hay una ventana en la que otra request puede quedarse con ese número, y
     * `numero` es único: la segunda se cae con violación de integridad. Es raro
     * pero no imposible, y en el portal público el costo es perder un reclamo.
     *
     * ⚠️ El reintento va acá y no en `DB::transaction($cb, $intentos)` porque
     * ese segundo argumento solo reintenta ante errores de concurrencia
     * (deadlocks), y un choque de clave única no lo es: lo relanzaría en el
     * primer intento.
     *
     * @template T
     *
     * @param  Closure(string): T  $alta  Recibe el número asignado.
     * @return T
     */
    public static function altaConNumero(int $anio, Closure $alta, int $intentos = 3)
    {
        for ($intento = 1; ; $intento++) {
            try {
                return DB::transaction(fn () => $alta(static::generarNumero($anio)));
            } catch (QueryException $e) {
                if ($intento >= $intentos || ! static::esChoqueDeNumero($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Si la excepción es el choque del índice único de `numero`.
     *
     * 23000 es "integrity constraint violation" en general (también una FK
     * inválida), así que además se mira que el mensaje nombre la columna. Los
     * dos motores del proyecto la nombran: MySQL en el nombre del índice
     * (`observations_numero_unique`) y SQLite en el de la columna
     * (`observations.numero`).
     */
    private static function esChoqueDeNumero(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && stripos($e->getMessage(), 'numero') !== false;
    }
}
