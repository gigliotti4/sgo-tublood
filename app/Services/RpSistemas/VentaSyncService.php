<?php

namespace App\Services\RpSistemas;

use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja `powerbi_ventas_vista` del ERP en la tabla local `ventas`.
 *
 * ⚠️ **Refresh completo, no upsert.** La vista es un detalle de comprobante y no
 * expone ninguna clave que el ERP garantice única: el mismo `compro_nro` se
 * repite por cada artículo, y `remito_nro` llega en 0 en miles de filas.
 * Inventar una clave compuesta sería apostar a que el ERP no repite una
 * combinación, y si la repitiera perderíamos renglones en silencio. Borrar e
 * insertar es más barato de razonar y perfectamente idempotente.
 *
 * Se hace dentro de una transacción para que la tabla nunca quede vacía si el
 * ERP corta la conexión a mitad de camino.
 *
 * Son ~60.000 filas: se leen del ERP en bloques para no traerlas todas a memoria
 * de una, y se insertan de a 500.
 */
class VentaSyncService
{
    private const VISTA = 'powerbi_ventas_vista';

    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        Log::info('RpSistemas: iniciando sincronización de ventas por SQL');

        $filas = DB::connection('erp')->table(self::VISTA)->get();

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            Venta::query()->delete();

            foreach ($filas->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                Venta::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: ventas sincronizadas — {$total} renglones");

        return $total;
    }

    /**
     * Fila de la vista => columnas locales.
     *
     * Público para poder testearlo sin un SQL Server. Ojo con las mayúsculas:
     * la vista mezcla `CLIENTE` en mayúscula con `fecha` en minúscula, así que
     * los nombres van tal cual los devuelve el ERP.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'compro_nro' => $this->texto($fila['compro_nro'] ?? null),
            'cod_comprobante' => $this->texto($fila['cod_comprobante'] ?? null),
            'grupo_compro_descrip' => $this->texto($fila['grupo_compro_descrip'] ?? null),
            'fecha' => $this->fecha($fila['fecha'] ?? null),
            'anio' => isset($fila['anio']) ? (int) $fila['anio'] : null,
            'cliente' => isset($fila['CLIENTE']) ? (int) $fila['CLIENTE'] : null,
            'razon_social' => $this->texto($fila['razon_social'] ?? null),
            'nombre_fantasia' => $this->texto($fila['nom_fantasia'] ?? null),
            'provincia' => $this->texto($fila['provincia'] ?? null),
            'articulo' => $this->texto($fila['articulo'] ?? null),
            'descrip_arti' => $this->texto($fila['descrip_arti'] ?? null),
            'cantidad' => $this->numero($fila['cantidad'] ?? null),
            'precio_neto' => $this->numero($fila['precio_neto'] ?? null),
            'sub_total' => $this->numero($fila['sub_total'] ?? null),
            'remito_nro' => isset($fila['remito_nro']) ? (int) $fila['remito_nro'] : null,
            'vendedor' => $this->texto($fila['vendedor'] ?? null),
            'codi_vende' => $this->texto($fila['codi_vende'] ?? null),
            'deposito' => $this->texto($fila['deposito'] ?? null),
            'transportista' => $this->texto($fila['transportista'] ?? null),
            'condi_venta' => $this->texto($fila['condi_venta'] ?? null),
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

    private function numero(mixed $valor): ?float
    {
        return ($valor === null || $valor === '') ? null : (float) $valor;
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
