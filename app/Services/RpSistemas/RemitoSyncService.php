<?php

namespace App\Services\RpSistemas;

use App\Models\Remito;
use App\Models\VentaPartida;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja los remitos de venta del ERP en `remitos`, una fila por comprobante.
 *
 * Sale de `powerbi_mov_stock_vista` (vista con contrato, la misma familia que
 * `powerbi_ventas_vista`) filtrada por las series de remito. La vista viene por
 * renglón —68.000 en 18.000 remitos—: se agrupa en SQL Server por `compro_nro`.
 *
 * ⚠️ **Las series son `VentaPartida::SERIES_DE_REMITO`, y nada más.** El maestro
 * de comprobantes del cliente (`Codigos_Comprobantes.xlsx`, 6/11/2026) lista
 * cuatro remitos de venta: `VRM`, `VR6`, `VR8` y `VNN`, que es "Remito Prueba".
 * Contar `VNN` sumaría remitos de prueba a la tasa. Hoy solo corre `VR8`; `VRM`
 * y `VR6` son históricas y la vista no las trae.
 *
 * ⚠️ **No es lo mismo que `proformas`.** Una proforma (`DDD`) es el pedido que
 * carga el vendedor; el remito es lo que sale del depósito. La base de la planilla
 * de objetivos para el armado ("1 % de los pedidos armados") coincide con los
 * remitos, no con las proformas.
 */
class RemitoSyncService
{
    private const VISTA = 'powerbi_mov_stock_vista';

    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;
        $vista = self::VISTA;
        $series = VentaPartida::SERIES_DE_REMITO;
        $marcas = implode(',', array_fill(0, count($series), '?'));

        $filas = DB::connection('erp')->select(<<<SQL
            SELECT LTRIM(RTRIM(compro_nro)) compro_nro,
                   MAX(cod_comprobante) tipo,
                   MIN(fecha) fecha,
                   MAX(codigo_cli_prove) cliente
            FROM {$vista}
            WHERE cod_comprobante IN ({$marcas})
              AND NULLIF(LTRIM(RTRIM(compro_nro)), '') IS NOT NULL
            GROUP BY LTRIM(RTRIM(compro_nro))
            SQL, $series);

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            Remito::query()->delete();

            foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                Remito::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: remitos sincronizados — {$total}");

        return $total;
    }

    /**
     * Fila agrupada de la vista => columnas locales. Público para testearlo sin SQL Server.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();
        $cliente = trim((string) ($fila['cliente'] ?? ''));

        return [
            'compro_nro' => trim((string) ($fila['compro_nro'] ?? '')),
            'tipo' => trim((string) ($fila['tipo'] ?? '')),
            'fecha' => Carbon::parse((string) $fila['fecha'])->toDateString(),
            'cliente_numero' => $cliente === '' ? null : $cliente,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
