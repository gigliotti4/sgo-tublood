<?php

namespace App\Services\RpSistemas;

use App\Models\CompraPedidoPendiente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja `powerbi_pedidos_vista` en `compras_pedidos_pendientes`.
 *
 * Va por la conexión `erp_compras` (el usuario `api_lucas` no tiene SELECT).
 *
 * ⚠️ Se filtra solo por `cant_pend > 0` (35.635 de 122.752 filas) y NO por la
 * regla de reserva. Guardar únicamente las reservadas ahorraría 35.000 filas,
 * pero dejaría la regla congelada en la sync: como no está conciliada con el
 * ERP, tiene que poder ajustarse desde config/compras.php y verse el efecto en
 * el acto. Ver `CompraPedidoPendiente::scopeReservadas()`.
 *
 * Refresh completo: no hay campos propios del panel.
 */
class PedidoPendienteSyncService
{
    private const VISTA = 'powerbi_pedidos_vista';

    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        Log::info('RpSistemas: iniciando sincronización de pedidos pendientes');

        $filas = DB::connection('erp_compras')
            ->table(self::VISTA)
            ->where('cant_pend', '>', 0)
            ->get();

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            CompraPedidoPendiente::query()->delete();

            foreach ($filas->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                CompraPedidoPendiente::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: pedidos pendientes sincronizados — {$total} renglones");

        return $total;
    }

    /**
     * Fila de la vista => columnas locales.
     *
     * Público para poder testearlo sin un SQL Server. Ojo con las mayúsculas:
     * esta vista mezcla `CLIENTE` en mayúscula con `articulo` en minúscula.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'comprobante' => $this->texto($fila['comprobante'] ?? null),
            'compro_nro' => $this->entero($fila['compro_nro'] ?? null),
            'renglon' => $this->entero($fila['renglon'] ?? null),
            'fecha' => $this->fecha($fila['fecha'] ?? null),
            'cliente' => $this->entero($fila['CLIENTE'] ?? null),
            'razon_social' => $this->texto($fila['razon_social'] ?? null),
            // Se normaliza igual que en el catálogo: es la clave del cruce.
            'articulo' => mb_strtoupper(trim((string) ($fila['articulo'] ?? ''))),
            'cant_pend' => (float) ($fila['cant_pend'] ?? 0),
            'reser' => $this->texto($fila['reser'] ?? null),
            'deposito_reserva' => $this->texto($fila['descrip_depo_reserva'] ?? null),
            'estado' => $this->texto($fila['estado'] ?? null),
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
