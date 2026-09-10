<?php

namespace App\Services\RpSistemas;

use App\Models\ComprasArticulo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja el catálogo maestro `ARTICULOS` del ERP en `compras_articulos`.
 *
 * ⚠️ Va por la conexión `erp_compras`, no `erp`: el usuario `api_lucas` NO tiene
 * SELECT sobre `ARTICULOS`. Ver config/database.php.
 *
 * ⚠️ `ARTICULOS` es una TABLA BASE del ERP, no una vista habilitada para
 * nosotros. Las vistas `powerbi_*` son un contrato: RP las expuso a propósito.
 * Ésta es interna de su sistema y la leemos porque tenemos SELECT, sin garantía
 * de que no le cambien una columna. Si el sync se rompe de golpe, empezar por
 * ahí; lo correcto a futuro es pedirles una vista.
 *
 * Se traen 8 de las 142 columnas. Refresh completo: no hay campos propios.
 */
class ComprasArticuloSyncService
{
    private const TABLA = 'ARTICULOS';

    private const CHUNK = 500;

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        Log::info('RpSistemas: iniciando sincronización del catálogo de compras');

        $filas = DB::connection('erp_compras')->select($this->sql());

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            ComprasArticulo::query()->delete();

            foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                ComprasArticulo::insert($lote);
                $total += count($lote);
            }
        });

        Log::info("RpSistemas: catálogo de compras sincronizado — {$total} artículos");

        return $total;
    }

    /**
     * El código se normaliza en el SELECT y no en PHP porque es la clave por la
     * que se cruzan ventas, OC y pedidos: si acá entrara ' RE-1573' y en ventas
     * 'RE-1573', el artículo quedaría sin ventas y nadie se enteraría.
     */
    private function sql(): string
    {
        $tabla = self::TABLA;

        return <<<SQL
            SELECT UPPER(LTRIM(RTRIM(COD_ARTICULO))) COD_ARTICULO,
                   DESCRIP_ARTI,
                   CANT_STOCK,
                   AGRU_1,
                   GTIN,
                   SIN_STOCK,
                   ACTIVO,
                   CODIGO_REFERENCIA
            FROM {$tabla}
            WHERE NULLIF(LTRIM(RTRIM(COD_ARTICULO)), '') IS NOT NULL
            SQL;
    }

    /**
     * Fila de `ARTICULOS` => columnas locales.
     *
     * Público para poder testearlo sin un SQL Server.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'codigo' => mb_strtoupper(trim((string) ($fila['COD_ARTICULO'] ?? ''))),
            'descripcion' => $this->texto($fila['DESCRIP_ARTI'] ?? null),
            'cant_stock' => (float) ($fila['CANT_STOCK'] ?? 0),
            'agru_1' => $this->texto($fila['AGRU_1'] ?? null),
            // Crudo: los comodines los filtra el dataset, no la sync.
            'gtin' => $this->texto($fila['GTIN'] ?? null),
            'sin_stock' => $this->esSi($fila['SIN_STOCK'] ?? null),
            'activo' => $this->esSi($fila['ACTIVO'] ?? null),
            'unidades_por_envase' => $this->envase($fila['CODIGO_REFERENCIA'] ?? null),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** El ERP usa char(1) 'S'/'N', y rellena con espacios. */
    private function esSi(mixed $valor): bool
    {
        return mb_strtoupper(trim((string) $valor)) === 'S';
    }

    /**
     * `CODIGO_REFERENCIA` es varchar y trae las unidades por envase.
     *
     * ⚠️ Hoy está cargado en 13 de 5.227 artículos. Vacío, 0 o no numérico
     * significan "se cuenta de a uno", y se guardan como null para que la
     * pantalla pueda mostrar "–" en vez de "1".
     */
    private function envase(mixed $valor): ?int
    {
        $valor = trim((string) $valor);

        if ($valor === '' || ! is_numeric($valor)) {
            return null;
        }

        $envase = (int) $valor;

        return $envase > 1 ? $envase : null;
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
