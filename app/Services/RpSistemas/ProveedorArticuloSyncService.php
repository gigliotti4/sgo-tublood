<?php

namespace App\Services\RpSistemas;

use App\Models\ProveedorArticulo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja `PROVE_ARTI` en `proveedor_articulos`: qué proveedores venden cada artículo y cuál es el
 * principal. Es la pestaña "Proveedores" de la ficha del artículo en RP.
 *
 * ⚠️ **Es una tabla base del ERP, no una vista `powerbi_*`.** Mismo riesgo que `ARTICULOS` y
 * `COMPRO_PARTIDAS`: la leemos porque tenemos SELECT, sin garantía de que no le cambien una columna.
 *
 * Medido el 14/10/2026: 4.701 renglones de 4.467 artículos; 944 de los 949 artículos activos tienen al
 * menos uno, y los números de proveedor matchean `proveedores.numero` en el 100% de los principales.
 * Contra los vínculos que ya había en el padrón coincide en 663 de 673.
 */
class ProveedorArticuloSyncService
{
    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        // ⚠️ El `GROUP BY` normaliza el artículo, igual que en `PartidaSyncService`: SQL Server distingue
        // mayúsculas y espacios a la izquierda, y lo que para él son dos grupos chocaría contra el índice
        // único de MySQL. Hoy no hay ninguna colisión, pero el día que alguien cargue " RE-1521" la sync
        // no puede caerse por eso. Si un mismo par quedara repetido, gana la marca de principal.
        $filas = DB::connection('erp')->select(<<<'SQL'
            SELECT UPPER(LTRIM(RTRIM(ARTICULO))) ARTICULO,
                   PROVE,
                   MAX(CASE WHEN UPPER(LTRIM(RTRIM(PROVE_PRINCIPAL))) = 'S' THEN 1 ELSE 0 END) PRINCIPAL,
                   MAX(NULLIF(LTRIM(RTRIM(CODI_PROVE)), '')) CODI_PROVE
            FROM PROVE_ARTI
            WHERE NULLIF(LTRIM(RTRIM(ARTICULO)), '') IS NOT NULL
              AND PROVE IS NOT NULL
            GROUP BY UPPER(LTRIM(RTRIM(ARTICULO))), PROVE
            SQL);

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            ProveedorArticulo::query()->delete();

            foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                ProveedorArticulo::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: proveedores por artículo sincronizados — {$total} renglones");

        return $total;
    }

    /**
     * Fila de `PROVE_ARTI` => columnas locales. Público para testearlo sin SQL Server.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();
        $codigoProveedor = trim((string) ($fila['CODI_PROVE'] ?? ''));
        $principal = $fila['PRINCIPAL'] ?? 0;

        return [
            'codigo_articulo' => mb_strtoupper(trim((string) ($fila['ARTICULO'] ?? ''))),
            'proveedor_numero' => trim((string) ($fila['PROVE'] ?? '')),
            // Llega como 1/0 de la consulta agregada, o como 'S'/'N' si alguien lo pasa crudo.
            'principal' => in_array(strtoupper(trim((string) $principal)), ['1', 'S'], true),
            'codigo_proveedor' => $codigoProveedor === '' ? null : $codigoProveedor,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
