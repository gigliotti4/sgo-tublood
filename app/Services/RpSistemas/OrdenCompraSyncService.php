<?php

namespace App\Services\RpSistemas;

use App\Models\CompraOrdenPendiente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja `powerbi_ordenescompra_pend_vista` en `compras_ordenes_pendientes`.
 *
 * Hasta el 29/9/2026 iba por una conexión aparte, porque `api_lucas` no tenía
 * SELECT sobre esta vista. Hoy hay una sola credencial — ver config/database.php.
 *
 * Son ~21.500 renglones. Se guardan crudos y no agregados por artículo: el
 * agregado se hace al armar el dataset, y tener el proveedor y la fecha de
 * entrega permite explicar de dónde sale el número de una fila del tablero.
 *
 * Refresh completo: no hay campos propios del panel.
 *
 * ⚠️ La fecha de entrega sale de `OC_ITEMS`, una TABLA BASE del ERP y no una
 * vista. La vista tiene `FECHA_ENTRE`, pero es la de la cabecera y casi
 * siempre viene vacía: la fecha que Compras carga es la de cada renglón (una OC
 * con tres entregas escalonadas tiene tres fechas distintas). Se cruza por
 * `TIPO + NUM + RENGLON`, que es lo que la vista llama `ITEM` — verificado
 * contra la OC 9996 del 8/10/2026. Mismo riesgo que `ARTICULOS` y
 * `COMPRO_PARTIDAS`: la leemos porque tenemos SELECT, sin contrato. Si se rompe,
 * la sync no se cae por eso: queda la fecha de la cabecera.
 */
class OrdenCompraSyncService
{
    private const VISTA = 'powerbi_ordenescompra_pend_vista';

    private const ITEMS = 'OC_ITEMS';

    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        Log::info('RpSistemas: iniciando sincronización de OC pendientes');

        // Solo lo que todavía debe entrar: una OC con saldo 0 ya se entregó.
        $filas = DB::connection('erp')
            ->table(self::VISTA.' as v')
            ->leftJoin(self::ITEMS.' as i', function ($join) {
                $join->on('i.TIPO', '=', 'v.TIPO')
                    ->on('i.NUM', '=', 'v.NUM')
                    ->on('i.RENGLON', '=', 'v.ITEM');
            })
            ->where('v.CANT_PEND', '>', 0)
            ->select('v.*', 'i.FECHA_ENTRE as FECHA_ENTRE_ITEM')
            ->get();

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            CompraOrdenPendiente::query()->delete();

            foreach ($filas->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                CompraOrdenPendiente::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: OC pendientes sincronizadas — {$total} renglones");

        return $total;
    }

    /**
     * Fila de la vista => columnas locales.
     *
     * Público para poder testearlo sin un SQL Server. Esta vista viene toda en
     * MAYÚSCULAS, a diferencia de `powerbi_pedidos_vista`.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'tipo' => $this->texto($fila['TIPO'] ?? null),
            'numero' => $this->entero($fila['NUM'] ?? null),
            'item' => $this->entero($fila['ITEM'] ?? null),
            'proveedor_numero' => $this->entero($fila['PROVE'] ?? null),
            'razon_social' => $this->texto($fila['RAZON'] ?? null),
            'fecha' => $this->fecha($fila['FECHA'] ?? null),
            // La del renglón, y si no está, la de la cabecera. Ver la clase.
            'fecha_entrega' => $this->fecha($fila['FECHA_ENTRE_ITEM'] ?? $fila['FECHA_ENTRE'] ?? null),
            // Se normaliza igual que en el catálogo: es la clave del cruce.
            'articulo' => mb_strtoupper(trim((string) ($fila['ARTICULO'] ?? ''))),
            'descrip_arti' => $this->texto($fila['DESCRIP_ARTI'] ?? null),
            'cant_pend' => (float) ($fila['CANT_PEND'] ?? 0),
            'um_compra' => $this->texto($fila['UM_COMPRA'] ?? null),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    private function entero(mixed $valor): ?int
    {
        return ($valor === null || $valor === '') ? null : (int) $valor;
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
