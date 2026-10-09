<?php

namespace App\Services\RpSistemas;

use App\Models\CompraStockDeposito;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja `powerbi_stock_vista` en `compras_stock_depositos`: el stock de cada
 * artículo partido por depósito.
 *
 * Es una VISTA habilitada para nosotros (con contrato), a diferencia de
 * `ARTICULOS.CANT_STOCK`, que es el total sin partir.
 *
 * ⚠️ La suma de los depósitos NO siempre da `CANT_STOCK`: medido el 8/10/2026,
 * coincide en 639 de los 671 artículos con stock. Por eso el tablero, con
 * "todos los depósitos", sigue usando `CANT_STOCK` (los números de siempre), y
 * solo suma los de esta tabla cuando se eligen depósitos puntuales. Ver
 * `stockDe()` en resources/js/lib/compras.ts.
 *
 * Los depósitos se cargan a mano en el ERP y algunos quedan en negativo
 * (PRODUCCION sumaba −5,47 M el 8/10/2026): se guardan crudos, igual que el
 * stock del catálogo. La pantalla decide qué hacer con un negativo.
 */
class StockDepositoSyncService
{
    private const VISTA = 'powerbi_stock_vista';

    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        Log::info('RpSistemas: iniciando sincronización del stock por depósito');

        // Agregado en el ERP por (artículo, depósito): la vista puede traer el
        // mismo par más de una vez, y el código se normaliza igual que en el
        // catálogo porque es la clave del cruce. Las filas sin depósito (todas
        // en 0 al medir) no aportan nada.
        $vista = self::VISTA;

        $filas = DB::connection('erp')->select(<<<SQL
            SELECT UPPER(LTRIM(RTRIM(COD_ARTICULO))) COD_ARTICULO,
                   LTRIM(RTRIM(deposito)) deposito,
                   MAX(descrip_deposito) descrip_deposito,
                   SUM(cant_stock) cant_stock
            FROM {$vista}
            WHERE NULLIF(LTRIM(RTRIM(deposito)), '') IS NOT NULL
              AND NULLIF(LTRIM(RTRIM(COD_ARTICULO)), '') IS NOT NULL
            GROUP BY UPPER(LTRIM(RTRIM(COD_ARTICULO))), LTRIM(RTRIM(deposito))
            SQL);

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            CompraStockDeposito::query()->delete();

            foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                CompraStockDeposito::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: stock por depósito sincronizado — {$total} filas");

        return $total;
    }

    /**
     * Fila de la vista => columnas locales. Público para testearlo sin SQL Server.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();
        $nombre = trim((string) ($fila['descrip_deposito'] ?? ''));

        return [
            'articulo' => mb_strtoupper(trim((string) ($fila['COD_ARTICULO'] ?? ''))),
            'deposito' => mb_strtoupper(trim((string) ($fila['deposito'] ?? ''))),
            'nombre' => $nombre === '' ? null : $nombre,
            'cant_stock' => (float) ($fila['cant_stock'] ?? 0),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
