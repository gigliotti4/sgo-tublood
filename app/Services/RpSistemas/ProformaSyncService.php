<?php

namespace App\Services\RpSistemas;

use App\Models\Proforma;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja las facturas proforma del ERP en `proformas`, una fila por comprobante.
 *
 * Sale de `powerbi_pedidos_vista`, la misma vista con contrato que ya usa
 * Compras, filtrada por el tipo de la proforma (`config('urgencias.comprobante')`,
 * `DDD`). La vista viene por renglón: se agrupa en SQL Server por `compro_nro`.
 * Medido el 22/10/2026: 14.234 proformas (55.717 renglones) desde el 01/10/2024,
 * y vendedor, cliente y observación son únicos por comprobante, así que el MAX()
 * no elige entre valores distintos — solo colapsa renglones iguales.
 */
class ProformaSyncService
{
    private const VISTA = 'powerbi_pedidos_vista';

    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;
        $vista = self::VISTA;

        $filas = DB::connection('erp')->select(<<<SQL
            SELECT LTRIM(RTRIM(compro_nro)) compro_nro,
                   MIN(fecha) fecha,
                   MAX(CLIENTE) cliente,
                   MAX(razon_social) razon_social,
                   MAX(vendedor) vendedor,
                   MAX(codi_vende) codi_vende,
                   MAX(obser_cabe) observacion,
                   MAX(estado) estado
            FROM {$vista}
            WHERE cod_comprobante = ?
              AND NULLIF(LTRIM(RTRIM(compro_nro)), '') IS NOT NULL
            GROUP BY LTRIM(RTRIM(compro_nro))
            SQL, [config('urgencias.comprobante', 'DDD')]);

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            Proforma::query()->delete();

            foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                Proforma::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: proformas sincronizadas — {$total}");

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
        $texto = function (string $clave) use ($fila): ?string {
            $valor = trim((string) ($fila[$clave] ?? ''));

            return $valor === '' ? null : $valor;
        };

        return [
            'compro_nro' => trim((string) ($fila['compro_nro'] ?? '')),
            'fecha' => Carbon::parse((string) $fila['fecha'])->toDateString(),
            'cliente_numero' => $texto('cliente'),
            'razon_social' => $texto('razon_social'),
            'vendedor' => $texto('vendedor'),
            'codi_vende' => $texto('codi_vende'),
            'observacion' => $texto('observacion'),
            'estado' => $texto('estado'),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
