<?php

namespace App\Services\RpSistemas;

use App\Models\VentaPartida;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja de `COMPRO_PARTIDAS` los movimientos de comprobante de venta, para
 * saber qué lote salió en cada renglón.
 *
 * Lee la **misma tabla** que `PartidaSyncService` pero a otro grano: aquél
 * agrega a una fila por artículo+lote (el padrón de partidas), éste conserva el
 * comprobante. Valen las mismas advertencias: es una tabla base del ERP y no
 * una vista habilitada para nosotros, y la agregación la hace SQL Server porque
 * traer los 390.000 movimientos por la red tarda minutos.
 *
 * ⚠️ **Refresh completo, no upsert**, igual que ventas y partidas.
 */
class VentaPartidaSyncService
{
    private const TABLA = 'COMPRO_PARTIDAS';

    private const CHUNK = 500;

    /**
     * Tipos de comprobante de venta.
     *
     * Son los mismos valores que aparecen en `ventas.cod_comprobante`
     * (verificado contra la tabla local): facturas A/B/E, notas de crédito,
     * comprobantes internos y boletas. Los tipos de stock (`STO`, `STI`) y los
     * de remito (`VR8`, `VRM`, `VR6`) quedan afuera a propósito: no son ventas,
     * y el remito ya viaja en `PRECE_*` del movimiento de la factura.
     */
    public const TIPOS_VENTA = [
        'FEA', 'FEB', 'FEE', 'F-A', 'FCA', 'FCN',
        'CEA', 'CEB', 'CEE', 'CNA', 'CNN', 'CA1',
        'BA', 'BEA', 'BN',
    ];

    /**
     * Un renglón puede tener varios movimientos del mismo lote (distinto
     * `SECUEN`), así que se agrupa por (comprobante, artículo, lote) y se suman
     * las cantidades. En los comprobantes de venta `CANTI` viene **negativa**
     * porque es una salida de stock: se guarda el valor absoluto, que es lo que
     * se despachó.
     *
     * ⚠️ **El `GROUP BY` normaliza artículo y lote** (`UPPER(LTRIM(RTRIM(...)))`)
     * y no agrupa por la columna cruda. SQL Server ignora los espacios a la
     * derecha pero **no los de la izquierda**, y su collation acá distingue
     * mayúsculas: sin normalizar salen dos grupos que después colisionan contra
     * el índice único de MySQL, que sí los considera el mismo. Es exactamente
     * el error que ya apareció en `PartidaSyncService`.
     *
     * `remito_tipo` / `remito_numero` salen de `PRECE_*` con `MAX()`: dentro de
     * un mismo (comprobante, artículo, lote) el remito de origen es uno solo.
     */
    private function sql(): string
    {
        $tabla = self::TABLA;
        $tipos = "'".implode("','", self::TIPOS_VENTA)."'";

        return <<<SQL
            SELECT TIPO,
                   NUM,
                   UPPER(LTRIM(RTRIM(COD_ARTICULO))) COD_ARTICULO,
                   UPPER(LTRIM(RTRIM(COD_PARTIDA))) COD_PARTIDA,
                   SUM(ABS(CANTI)) cantidad,
                   MAX(FECHA) fecha,
                   MAX(PRECE_TIPO) remito_tipo,
                   MAX(PRECE_NUM) remito_numero
            FROM {$tabla}
            WHERE TIPO IN ({$tipos})
              AND COD_ARTICULO IS NOT NULL
              AND NULLIF(LTRIM(RTRIM(COD_PARTIDA)), '') IS NOT NULL
            GROUP BY TIPO, NUM,
                     UPPER(LTRIM(RTRIM(COD_ARTICULO))),
                     UPPER(LTRIM(RTRIM(COD_PARTIDA)))
            SQL;
    }

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        Log::info('RpSistemas: iniciando sincronización de lotes despachados');

        $filas = DB::connection('erp')->select($this->sql());

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            VentaPartida::query()->delete();

            foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                VentaPartida::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: lotes despachados sincronizados — {$total} filas");

        return $total;
    }

    /**
     * Fila agregada del ERP => columnas locales.
     *
     * Público para poder testearlo sin un SQL Server.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();
        $tipo = $this->texto($fila['TIPO'] ?? null);
        $numero = isset($fila['NUM']) ? (int) $fila['NUM'] : 0;

        return [
            'compro_nro' => $this->comproNro($tipo, $numero),
            'cod_comprobante' => $tipo,
            'numero' => $numero,
            'codigo_articulo' => $this->texto($fila['COD_ARTICULO'] ?? null),
            'codigo_partida' => $this->texto($fila['COD_PARTIDA'] ?? null),
            'cantidad' => isset($fila['cantidad']) ? (float) $fila['cantidad'] : null,
            'remito_tipo' => $this->texto($fila['remito_tipo'] ?? null),
            'remito_numero' => ! empty($fila['remito_numero']) ? (int) $fila['remito_numero'] : null,
            'fecha' => $this->fecha($fila['fecha'] ?? null),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Reconstruye el `compro_nro` con el formato de `ventas.compro_nro`.
     *
     * ⚠️ Siempre **8 dígitos** con ceros a la izquierda: verificado sobre los
     * 60.781 registros de `ventas`, todos tienen exactamente 8 después del
     * último guion. Es lo que permite joinear las dos tablas por una sola
     * columna en vez de partir el string en cada query.
     *
     * Ojo con los tipos que llevan guion adentro (`F-A` → `F-A-00001857`): por
     * eso el join usa `cod_comprobante` y no el prefijo de `compro_nro`
     * partido por `-`, que devolvería `F`.
     */
    private function comproNro(?string $tipo, int $numero): ?string
    {
        return $tipo === null ? null : sprintf('%s-%08d', $tipo, $numero);
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    private function fecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
