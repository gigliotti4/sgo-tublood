<?php

namespace App\Services;

use App\Models\Articulo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quién le pone el proveedor a cada artículo, y quién puede pisar a quién.
 *
 * Hay tres fuentes automáticas escribiendo `articulos.proveedor_id` y hasta que
 * existió `proveedor_origen` ninguna sabía de la otra: la única regla era "no
 * pisar nada ya cargado". Eso protegía las correcciones a mano pero también
 * congelaba una adivinanza del Excel aunque después llegara el dato bueno del
 * ERP. Con el origen guardado la precedencia se decide de verdad, y se decide
 * **en un solo lugar** para que las tres fuentes no puedan discrepar.
 */
class VinculacionProveedores
{
    public const ORIGEN_KARDEX = 'kardex';

    public const ORIGEN_EXCEL = 'excel';

    public const ORIGEN_ERP = 'erp';

    public const ORIGEN_MANUAL = 'manual';

    /**
     * Precedencia, de menor a mayor: la posición en el array es la autoridad.
     *
     * - `kardex`: de quién compramos las partidas de ese artículo. Es el más
     *   flojo — dice a quién le compramos un lote, no quién es el proveedor del
     *   artículo — y por eso solo llena vacíos.
     * - `excel`: la planilla de proveedores de Tublood, matcheada por razón
     *   social normalizada. Es una declaración nuestra, pero aproximada.
     * - `erp`: `codigo_proveedor` del feed de RP. Es el número de proveedor del
     *   sistema que factura, así que manda por sobre los dos anteriores.
     * - `manual`: alguien lo corrigió mirando el caso. **Nada automático lo
     *   pisa**, ni siquiera el ERP: si una persona se tomó el trabajo de
     *   corregirlo es porque las fuentes automáticas estaban equivocadas.
     */
    public const PRECEDENCIA = [
        self::ORIGEN_KARDEX,
        self::ORIGEN_EXCEL,
        self::ORIGEN_ERP,
        self::ORIGEN_MANUAL,
    ];

    /**
     * ¿Una fuente `$nuevo` puede escribir sobre lo que puso `$actual`?
     *
     * `null` es "no hay nada cargado": cualquiera puede escribir. Un origen
     * desconocido (una fila vieja, o un valor que dejó de usarse) se trata como
     * vacío a propósito: es mejor que lo complete una fuente conocida a que
     * quede clavado para siempre por un valor que ya nadie escribe.
     */
    public static function puedePisar(?string $actual, string $nuevo): bool
    {
        if ($actual === null) {
            return true;
        }

        $pesoActual = array_search($actual, self::PRECEDENCIA, true);
        $pesoNuevo = array_search($nuevo, self::PRECEDENCIA, true);

        if ($pesoActual === false) {
            return true;
        }

        return $pesoNuevo !== false && $pesoNuevo > $pesoActual;
    }

    /**
     * Por qué un `codigo_proveedor` no sirve para vincular.
     *
     * ⚠️ **El único criterio de "sirve" es estar en el padrón.** La forma del
     * valor no decide nada: si el ERP usara códigos alfanuméricos y el padrón
     * los tuviera, vincularían igual. Hacer que el reporte use un criterio
     * propio (por ejemplo "tiene que ser entero") lo dejaría marcando como
     * inválido algo que la vinculación sí usa.
     *
     * La forma sirve para otra cosa: **explicar** el problema en el reporte que
     * se le manda a RP. De los 90 artículos que hoy tienen el campo cargado, 69
     * traen cosas que no son códigos de proveedor — 32 son decimales que
     * parecen costos (`941.825` en un artículo cuyo precio de venta es
     * `1668.58`) y el resto códigos de artículo (`24001IC04141225X`,
     * `V-OBTU-p/JER100`). Decirle a RP "esto parece un costo" es más accionable
     * que "no matchea".
     */
    public static function motivoDeInvalidez(?string $codigo, array $numerosDelPadron): ?string
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '' || in_array($codigo, $numerosDelPadron, true)) {
            return null;
        }

        // `is_numeric()` sin `ctype_digit()`: son los decimales, que es el
        // patrón de "alguien tipeó el costo acá".
        if (is_numeric($codigo) && ! ctype_digit($codigo)) {
            return 'Parece un costo, no un número de proveedor';
        }

        return 'No existe en el padrón de proveedores';
    }

    /**
     * Vincula por `codigo_proveedor` (fuente `erp`).
     *
     * ⚠️ **Subconsulta escalar y no `UPDATE...JOIN`**: SQLite (lo que usan los
     * tests) no soporta join en un UPDATE, pero sí una subconsulta, y rinde
     * igual de bien en MySQL. `proveedores.numero` tiene índice único, así que
     * la subconsulta nunca puede devolver más de una fila.
     *
     * Recorrer los artículos en PHP para resolver cada uno contra ~1900
     * proveedores sería lento y no aporta nada que SQL no resuelva mejor.
     *
     * El `whereIn` contra `proveedores.numero` **es** el criterio de validez, el
     * mismo que reporta `motivoDeInvalidez()`: un valor como `941.825` no está
     * en el padrón y queda afuera solo, sin necesidad de filtrarlo por forma.
     */
    public static function desdeCodigoProveedor(): int
    {
        return DB::table('articulos')
            ->where(fn ($q) => self::filtroDePrecedencia($q, self::ORIGEN_ERP))
            ->whereNotNull('codigo_proveedor')
            ->whereIn('codigo_proveedor', function ($query) {
                $query->select('numero')->from('proveedores')->whereNotNull('numero');
            })
            ->update([
                'proveedor_id' => DB::raw(
                    '(select id from proveedores where proveedores.numero = articulos.codigo_proveedor)'
                ),
                'proveedor_origen' => self::ORIGEN_ERP,
            ]);
    }

    /**
     * Vincula desde el kardex de compras (fuente `kardex`).
     *
     * `partidas.proveedor_numero` sale de `COMPRO_PARTIDAS.PROVE_COMPRA`: a
     * quién le compramos cada partida. Matchea `proveedores.numero` 97/97, y
     * contrastado contra los artículos que ya tenían proveedor coincide en 225
     * de 231 (97%), así que es un respaldo confiable mientras RP completa
     * `codigo_proveedor`.
     *
     * ⚠️ **Solo cuando el artículo tiene un único proveedor histórico.** 103 de
     * 823 artículos con partidas le compraron a más de uno a lo largo del
     * tiempo; elegir uno sería adivinar, y ponerle el proveedor equivocado a un
     * artículo es peor que dejarlo vacío — se arrastra hasta el ranking de
     * fallas por proveedor del Dashboard.
     */
    public static function desdeKardex(): int
    {
        $unicos = DB::table('partidas')
            ->select('codigo_articulo', DB::raw('MIN(proveedor_numero) as numero'))
            ->whereNotNull('proveedor_numero')
            ->groupBy('codigo_articulo')
            ->havingRaw('COUNT(DISTINCT proveedor_numero) = 1')
            ->pluck('numero', 'codigo_articulo');

        if ($unicos->isEmpty()) {
            return 0;
        }

        $proveedores = DB::table('proveedores')->whereNotNull('numero')->pluck('id', 'numero');

        // Agrupar por proveedor deja un puñado de UPDATEs en vez de uno por
        // artículo: son ~800 claves que caen en ~100 proveedores distintos.
        $porProveedor = [];

        foreach ($unicos as $codigoArticulo => $numero) {
            if (! isset($proveedores[$numero])) {
                continue;
            }

            // ⚠️ Casteo explícito a string: `pluck()` devuelve las claves que
            // parecen números como int, y un `whereIn` de ints contra la
            // columna varchar `codigo` hace que MySQL castee la columna entera
            // a número — revienta con códigos como `SBS23` y, peor, haría
            // matchear `0123` con `123`.
            $porProveedor[$proveedores[$numero]][] = (string) $codigoArticulo;
        }

        $vinculados = 0;

        foreach ($porProveedor as $proveedorId => $codigos) {
            foreach (array_chunk($codigos, 500) as $bloque) {
                $vinculados += DB::table('articulos')
                    ->where(fn ($q) => self::filtroDePrecedencia($q, self::ORIGEN_KARDEX))
                    ->whereIn('codigo', $bloque)
                    ->update([
                        'proveedor_id' => $proveedorId,
                        'proveedor_origen' => self::ORIGEN_KARDEX,
                    ]);
            }
        }

        return $vinculados;
    }

    /**
     * Los `codigo_proveedor` que el ERP tiene cargados pero no sirven para
     * vincular, agrupados por valor. Es la lista concreta para mandarle a RP.
     *
     * @return Collection<int, object>
     */
    public static function codigosInvalidos(): Collection
    {
        $numeros = DB::table('proveedores')->whereNotNull('numero')->pluck('numero')->all();

        return Articulo::query()
            ->selectRaw('codigo_proveedor, COUNT(*) as articulos')
            ->whereNotNull('codigo_proveedor')
            ->where('codigo_proveedor', '<>', '')
            ->groupBy('codigo_proveedor')
            ->orderByDesc('articulos')
            ->get()
            ->map(function ($fila) use ($numeros) {
                $fila->motivo = self::motivoDeInvalidez($fila->codigo_proveedor, $numeros);

                return $fila;
            })
            // Inválido es exactamente "no vincula", que es lo mismo que decide
            // `desdeCodigoProveedor()` con su `whereIn` contra el padrón.
            ->filter(fn ($fila) => $fila->motivo !== null)
            ->values();
    }

    /** Restringe un update a las filas que `$origen` tiene derecho a escribir. */
    private static function filtroDePrecedencia(QueryBuilder $query, string $origen): void
    {
        $pisables = array_values(array_filter(
            self::PRECEDENCIA,
            fn ($otro) => self::puedePisar($otro, $origen)
        ));

        // El origen más flojo (`kardex`) no puede pisar a nadie, ni a sí mismo:
        // ahí la lista queda vacía y solo se escriben las filas sin proveedor.
        // Sin este corte, el `orWhereIn` con array vacío mete un `0 = 1` inútil
        // en la query.
        if ($pisables === []) {
            $query->whereNull('proveedor_origen');

            return;
        }

        $query->whereNull('proveedor_origen')->orWhereIn('proveedor_origen', $pisables);
    }
}
