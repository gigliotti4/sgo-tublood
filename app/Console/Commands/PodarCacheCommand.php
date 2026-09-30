<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra de la tabla `cache` las entradas que ya vencieron.
 *
 * ⚠️ **Laravel no trae un comando para esto**, y no es un olvido menor: el
 * driver `database` borra una fila vencida **solo cuando alguien vuelve a leer
 * esa clave**. Una clave que nadie vuelve a pedir queda ocupando lugar para
 * siempre.
 *
 * Acá eso pega fuerte por el tablero de Compras: su clave lleva un hash de los
 * filtros (`compras:reposicion:v2:5afbf`), así que **cada combinación distinta
 * genera una entrada de ~2 MB que no se vuelve a leer nunca**. Medido en
 * producción el 29/9/2026: 36,5 MB en 72 filas, de las cuales 66 estaban
 * vencidas.
 *
 * ⚠️ **No es `cache:clear`.** Ese borra todo, incluido lo vigente, y la
 * siguiente visita al tablero tendría que rehacer el dataset entero para nada.
 * Acá solo se va lo que ya no sirve.
 */
class PodarCacheCommand extends Command
{
    protected $signature = 'cache:podar';

    protected $description = 'Borra las entradas vencidas de la tabla de caché, que el driver database no limpia solo';

    public function handle(): int
    {
        $store = config('cache.default');

        // Solo tiene sentido con el driver `database`. Con redis o file, el
        // propio store maneja el vencimiento y esto no tendría qué borrar.
        if (config("cache.stores.{$store}.driver") !== 'database') {
            $this->info("El store `{$store}` no es `database`: no hay nada que podar.");

            return self::SUCCESS;
        }

        // La tabla y la conexión salen de la config, no hardcodeadas: si alguien
        // mueve la caché a otra conexión, esto la sigue.
        $conexion = config("cache.stores.{$store}.connection");
        $tabla = config("cache.stores.{$store}.table", 'cache');

        $borradas = DB::connection($conexion)
            ->table($tabla)
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();

        $this->info("Entradas de caché vencidas borradas: {$borradas}");

        return self::SUCCESS;
    }
}
