<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un proveedor que vende un artículo, según la pestaña "Proveedores" de la ficha del artículo en el ERP.
 *
 * Espejo de solo lectura de `PROVE_ARTI` (refresh completo en cada sync de artículos). Un artículo puede
 * tener varios; `principal` es la marca que Compras pone en el ERP.
 */
class ProveedorArticulo extends Model
{
    protected $table = 'proveedor_articulos';

    protected $guarded = [];

    protected $casts = [
        'principal' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * El proveedor principal de cada artículo: código de artículo => número de proveedor.
     *
     * La regla, escrita una sola vez porque la usan la vinculación del padrón y el tablero de Compras
     * (si cada uno la resolviera por su lado, el mismo artículo podría tener un proveedor en la ficha y
     * otro en el tablero):
     *
     * - el único renglón marcado principal;
     * - si el artículo tiene un solo proveedor, ese, aunque no esté marcado (es el caso común: 4.257 de
     *   4.467 artículos al 14/10/2026, y no tiene sentido exigir la tilde cuando no hay a quién elegir);
     * - ⚠️ con **más de un principal** o **varios sin principal**, ninguno. Medido el 14/10/2026: 54
     *   artículos con dos o más marcados y 4 sin ninguno. Elegir sería adivinar, y un proveedor
     *   equivocado se arrastra hasta el ranking de fallas del Dashboard — mismo criterio que el kardex
     *   con varios proveedores históricos.
     *
     * @return array<string, string>
     */
    public static function principalPorArticulo(): array
    {
        $renglones = [];

        foreach (self::query()->toBase()->select('codigo_articulo', 'proveedor_numero', 'principal')->cursor() as $fila) {
            $renglones[(string) $fila->codigo_articulo][] = $fila;
        }

        $principales = [];

        foreach ($renglones as $codigo => $filas) {
            $marcados = array_values(array_filter($filas, fn ($f) => (bool) $f->principal));

            if (count($marcados) === 1) {
                $principales[$codigo] = (string) $marcados[0]->proveedor_numero;
            } elseif (count($filas) === 1) {
                $principales[$codigo] = (string) $filas[0]->proveedor_numero;
            }
        }

        return $principales;
    }

    /**
     * Todos los proveedores de cada artículo: código => lista de números, el principal primero.
     *
     * @return array<string, list<string>>
     */
    public static function proveedoresPorArticulo(): array
    {
        $porArticulo = [];

        $filas = self::query()->toBase()
            ->select('codigo_articulo', 'proveedor_numero')
            ->orderBy('codigo_articulo')
            ->orderByDesc('principal')
            ->orderBy('proveedor_numero')
            ->cursor();

        foreach ($filas as $fila) {
            $porArticulo[(string) $fila->codigo_articulo][] = (string) $fila->proveedor_numero;
        }

        return $porArticulo;
    }
}
