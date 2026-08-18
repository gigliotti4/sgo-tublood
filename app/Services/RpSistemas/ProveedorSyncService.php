<?php

namespace App\Services\RpSistemas;

use App\Models\Proveedor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja `powerbi_proveedores_vista` del ERP en la tabla local `proveedores`.
 *
 * A diferencia de clientes y artículos, acá no hay API: RP Sistemas solo
 * habilita vistas SQL de solo lectura (las mismas que consumen desde Power BI),
 * así que se lee por la conexión `erp` en vez de por HTTP.
 *
 * Sincronización **completa y no incremental**: son ~1.850 filas, leerlas todas
 * es instantáneo y hace la corrida idempotente sin depender de que `FECHA_MODI`
 * esté bien mantenida. El valor se guarda igual en `modificado_en`, por si más
 * adelante conviene filtrar.
 */
class ProveedorSyncService
{
    private const VISTA = 'powerbi_proveedores_vista';

    /** El ERP manda 1.849 filas; de a 500 el INSERT no se vuelve gigante. */
    private const CHUNK = 500;

    /** @return array{procesados: int, adoptados: int} */
    public function sync(): array
    {
        $syncedAt = Carbon::now();
        $procesados = 0;

        Log::info('RpSistemas: iniciando sincronización de proveedores por SQL');

        $filas = DB::connection('erp')->table(self::VISTA)->get();

        // Antes de tocar nada: los proveedores que creó el Excel de artículos
        // sin número. Si no se los adopta primero, el upsert daría de alta un
        // registro nuevo con el mismo nombre y los artículos que ya apuntaban
        // al viejo quedarían colgados de un fantasma.
        $adoptados = $this->adoptarSinNumero($filas);

        foreach ($filas->chunk(self::CHUNK) as $bloque) {
            $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt))->all();

            // `observaciones` NO va acá: es el único campo propio del panel y la
            // sincronización nunca debe pisarlo. Mismo contrato que
            // `clientes.mail_nuevo` y `articulos.pm`.
            Proveedor::upsert(
                $lote,
                ['numero'],
                [
                    'razon_social', 'nombre_fantasia', 'domicilio', 'cuit',
                    'telefono', 'celular', 'mail', 'localidad', 'provincia',
                    'codigo_postal', 'contacto', 'estado', 'modificado_en',
                    'synced_at', 'updated_at',
                ]
            );

            $procesados += count($lote);
        }

        Log::info("RpSistemas: proveedores sincronizados — {$procesados} procesados, {$adoptados} adoptados");

        return ['procesados' => $procesados, 'adoptados' => $adoptados];
    }

    /**
     * Completa el número de los proveedores que entraron por el Excel de
     * artículos, matcheando por razón social normalizada.
     *
     * Reusa `Proveedor::normalizarRazonSocial()` — la misma forma canónica que
     * usa el import de artículos para asignar el proveedor de un artículo. Si
     * las dos se desincronizaran, un proveedor entraría dos veces.
     *
     * Una razón social ambigua (dos huérfanos que normalizan igual, o dos filas
     * del ERP con el mismo nombre) no se adopta: ponerle el número al que no era
     * es peor que dejarlo suelto y que lo arregle una persona.
     *
     * @param  Collection<int, object>  $filas
     */
    private function adoptarSinNumero($filas): int
    {
        $huerfanos = Proveedor::whereNull('numero')->get();

        if ($huerfanos->isEmpty()) {
            return 0;
        }

        $porRazonSocial = $this->indexarPorRazonSocial($filas);
        $adoptados = 0;

        foreach ($huerfanos as $huerfano) {
            $clave = Proveedor::normalizarRazonSocial($huerfano->razon_social);
            $numero = $porRazonSocial[$clave] ?? null;

            if ($numero === null) {
                continue;
            }

            // Si ese número ya existe como otra fila local, no se puede adoptar
            // sin violar el índice único. Queda para resolución manual.
            if (Proveedor::where('numero', $numero)->exists()) {
                continue;
            }

            $huerfano->update(['numero' => $numero]);
            $adoptados++;
        }

        return $adoptados;
    }

    /**
     * Razón social normalizada => número, descartando las ambiguas.
     *
     * @param  Collection<int, object>  $filas
     * @return array<string, string>
     */
    private function indexarPorRazonSocial($filas): array
    {
        $indice = [];
        $ambiguas = [];

        foreach ($filas as $fila) {
            $clave = Proveedor::normalizarRazonSocial((string) ($fila->RAZON ?? ''));

            if ($clave === '') {
                continue;
            }

            if (isset($indice[$clave])) {
                $ambiguas[$clave] = true;
            }

            $indice[$clave] = (string) $fila->NUM_PROV;
        }

        return array_diff_key($indice, $ambiguas);
    }

    /**
     * Fila de la vista => columnas locales.
     *
     * Público para poder testearlo sin un SQL Server: es donde se rompen los
     * nombres de columna cuando el ERP cambia la vista.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'numero' => (string) ($fila['NUM_PROV'] ?? ''),
            'razon_social' => $this->texto($fila['RAZON'] ?? null) ?? '',
            'nombre_fantasia' => $this->texto($fila['NOM_FANTASIA'] ?? null),
            'domicilio' => $this->texto($fila['DOMICILIO'] ?? null),
            'cuit' => $this->texto($fila['CUIT'] ?? null),
            'telefono' => $this->texto($fila['TELEFONO'] ?? null),
            'celular' => $this->texto($fila['CELULAR'] ?? null),
            'mail' => $this->texto($fila['MAIL'] ?? null),
            'localidad' => $this->texto($fila['LOCALIDAD'] ?? null),
            'provincia' => $this->texto($fila['PROVINCIA'] ?? null),
            'codigo_postal' => $this->texto($fila['CP'] ?? null),
            'contacto' => $this->texto($fila['CONTACTO'] ?? null),
            'estado' => $this->texto($fila['ESTADO'] ?? null),
            'modificado_en' => $this->fecha($fila['FECHA_MODI'] ?? null),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** El ERP rellena con espacios los `char`, y usa cadenas vacías en vez de null. */
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
            return Carbon::parse($valor)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
