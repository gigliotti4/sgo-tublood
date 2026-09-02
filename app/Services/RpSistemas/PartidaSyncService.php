<?php

namespace App\Services\RpSistemas;

use App\Models\Partida;
use App\Services\VinculacionProveedores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja `COMPRO_PARTIDAS` del ERP en la tabla local `partidas`.
 *
 * ⚠️ **Es una tabla base del ERP, no una de las vistas que RP habilitó.**
 * `powerbi_proveedores_vista` y `powerbi_ventas_vista` son un contrato: las
 * expusieron para nosotros. Ésta es interna de su sistema y la leemos porque
 * tenemos SELECT, sin garantía de que no le cambien una columna. Si algún día
 * se rompe de golpe, empezar por ahí — y lo correcto a futuro es pedirles una
 * vista.
 *
 * ⚠️ **La agregación la hace el ERP, no nosotros.** El kardex tiene 390.000
 * movimientos y agruparlo del lado de PHP obliga a traerlos todos por la red:
 * medido, tarda más de siete minutos. Agregado en SQL Server son 12.782 filas
 * en ~3 segundos.
 *
 * ⚠️ **Refresh completo, no upsert**, igual que ventas: una partida que
 * desaparece del ERP tiene que desaparecer de acá, y un upsert la dejaría
 * colgada para siempre. Va en una transacción para que la tabla nunca quede
 * vacía si el ERP corta la conexión a mitad de camino.
 */
class PartidaSyncService
{
    private const TABLA = 'COMPRO_PARTIDAS';

    private const CHUNK = 500;

    /**
     * Un movimiento del kardex no describe la partida entera: el vencimiento,
     * el proveedor y la ubicación están cargados en unos renglones sí y en
     * otros no, y en una minoría se contradicen (563 partidas tienen más de un
     * vencimiento, 17 más de un proveedor, 1.312 más de una ubicación).
     *
     * La regla es **el movimiento más reciente que tenga el dato cargado**, por
     * columna. Para resolverlo en un solo hash aggregate —sin subqueries
     * correlacionadas, que medidas tardaban minutos— se empaqueta la fecha del
     * movimiento delante del valor (`AAAAMMDD` + valor) y se toma `MAX()` del
     * string: gana el movimiento más nuevo. Después se desempaqueta cortando
     * los 8 primeros caracteres.
     *
     * `RIGHT('0000000000' + ...)` sobre el proveedor es para que el número
     * ordene como número dentro del string y no alfabéticamente.
     *
     * ⚠️ **El `GROUP BY` normaliza la clave (mayúsculas y sin espacios en los
     * bordes) y no agrupa por la columna cruda.** SQL Server ignora los
     * espacios a la derecha pero **no los de la izquierda**, y su collation
     * acá distingue mayúsculas, así que `' RE-1573'` y `'RE-1573'` salían como
     * dos grupos que después colisionaban contra el índice único de MySQL, que
     * sí los considera el mismo. Agrupar por la forma normalizada deja una
     * fila por partida y hace que la clave guardada sea exactamente la que
     * busca `Partida::normalizarCodigo()`.
     */
    private function sql(): string
    {
        $tabla = self::TABLA;

        return <<<SQL
            SELECT UPPER(LTRIM(RTRIM(COD_ARTICULO))) COD_ARTICULO,
                   UPPER(LTRIM(RTRIM(COD_PARTIDA))) COD_PARTIDA,
                   MAX(FECHA) ultimo_movimiento,
                   MAX(CASE WHEN FECHA_VENCI IS NOT NULL
                       THEN CONVERT(char(8), FECHA, 112) + CONVERT(char(8), FECHA_VENCI, 112) END) venci_pack,
                   MAX(CASE WHEN PROVE_COMPRA IS NOT NULL AND PROVE_COMPRA <> 0
                       THEN CONVERT(char(8), FECHA, 112) + RIGHT('0000000000' + CAST(PROVE_COMPRA AS varchar(10)), 10) END) prov_pack,
                   MAX(CASE WHEN NULLIF(LTRIM(RTRIM(UBICACION_PARTIDA)), '') IS NOT NULL
                       THEN CONVERT(char(8), FECHA, 112) + UBICACION_PARTIDA END) ubi_pack
            FROM {$tabla}
            WHERE COD_ARTICULO IS NOT NULL
              AND NULLIF(LTRIM(RTRIM(COD_PARTIDA)), '') IS NOT NULL
            GROUP BY UPPER(LTRIM(RTRIM(COD_ARTICULO))), UPPER(LTRIM(RTRIM(COD_PARTIDA)))
            SQL;
    }

    public function sync(): int
    {
        $syncedAt = Carbon::now();
        $total = 0;

        Log::info('RpSistemas: iniciando sincronización de partidas por SQL');

        $filas = DB::connection('erp')->select($this->sql());

        DB::transaction(function () use ($filas, $syncedAt, &$total) {
            Partida::query()->delete();

            foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
                $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

                Partida::insert($lote);
                $total += count($lote);
            }
        });

        // Con las partidas frescas se aprovecha para completar el proveedor de
        // los artículos que todavía no lo tienen. Va acá y no en
        // ArticuloSyncService justamente para usar lo recién sincronizado:
        // artículos corre a las 03:00 y partidas a las 04:30.
        $vinculados = VinculacionProveedores::desdeKardex();

        Log::info("RpSistemas: partidas sincronizadas — {$total} partidas, {$vinculados} artículos vinculados por kardex");

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

        return [
            'codigo_articulo' => $this->texto($fila['COD_ARTICULO'] ?? null),
            'codigo_partida' => $this->texto($fila['COD_PARTIDA'] ?? null),
            'fecha_vencimiento' => $this->fechaEmpaquetada($fila['venci_pack'] ?? null),
            'proveedor_numero' => $this->numeroEmpaquetado($fila['prov_pack'] ?? null),
            'ubicacion' => $this->desempaquetar($fila['ubi_pack'] ?? null),
            'ultimo_movimiento_at' => $this->fecha($fila['ultimo_movimiento'] ?? null),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Saca los 8 caracteres de la fecha del movimiento que se usó para ordenar. */
    private function desempaquetar(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $this->texto(mb_substr((string) $valor, 8));
    }

    /** El valor empaquetado viene como `AAAAMMDD`. */
    private function fechaEmpaquetada(mixed $valor): ?string
    {
        $crudo = $this->desempaquetar($valor);

        if ($crudo === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Ymd', $crudo)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** El número del proveedor viaja con ceros a la izquierda para poder ordenarlo. */
    private function numeroEmpaquetado(mixed $valor): ?string
    {
        $crudo = $this->desempaquetar($valor);

        if ($crudo === null) {
            return null;
        }

        $numero = ltrim($crudo, '0');

        return $numero === '' ? null : $numero;
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
