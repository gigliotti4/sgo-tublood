<?php

namespace App\Services\RpSistemas;

use App\Models\Cliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ClienteSyncService
{
    public function __construct(private RpSistemasClient $client) {}

    /**
     * Sincroniza todos los clientes de RP Sistemas a la tabla local.
     * Usa upsert con `numero` como clave única (idempotente).
     *
     * @return int Total de registros sincronizados
     */
    public function sync(): int
    {
        $pagina    = 1;
        $tamano    = config('services.rpsistemas.page_size', 100);
        $total     = 0;
        $syncedAt  = Carbon::now();

        Log::info('RpSistemas: iniciando sincronización de clientes');

        do {
            $result   = $this->client->getClientes($pagina, $tamano);
            $datos    = $result['datos'];
            $paginado = $result['paginado'];

            if (empty($datos)) {
                break;
            }

            $lote = array_map(fn($c) => $this->mapear($c, $syncedAt), $datos);

            Cliente::upsert(
                $lote,
                ['numero'],
                [
                    'razon_social', 'nombre_fantasia', 'cuit', 'codigo_iva',
                    'descripcion_iva', 'telefono', 'mail', 'contacto',
                    'domicilio', 'localidad', 'codigo_provincia', 'descripcion_provincia',
                    'porcen_descuen', 'usuario_web', 'codigo_vendedor', 'nombre_vendedor',
                    'codigo_postal', 'synced_at', 'updated_at',
                ]
            );

            $total += count($lote);
            $pagina++;

        } while (RpSistemasClient::tienePaginaSiguiente($paginado));

        Log::info("RpSistemas: sincronización completada — {$total} clientes procesados");

        return $total;
    }

    private function mapear(array $c, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'numero'               => (string) ($c['numero'] ?? ''),
            'razon_social'         => (string) ($c['razon'] ?? ''),
            'nombre_fantasia'      => $c['nombre_fantasia'] ?? null,
            'cuit'                 => $c['cuit'] ?? null,
            'codigo_iva'           => isset($c['codigo_iva']) ? (string) $c['codigo_iva'] : null,
            'descripcion_iva'      => $c['descripcion_iva'] ?? null,
            'telefono'             => $c['telefono'] ?? null,
            'mail'                 => $c['mail'] ?? null,
            'contacto'             => $c['contacto'] ?? null,
            'domicilio'            => $c['domicilio'] ?? null,
            'localidad'            => $c['localidad'] ?? null,
            'codigo_provincia'     => $c['codigo_provincia'] ?? null,
            'descripcion_provincia'=> $c['descripcion_provincia'] ?? null,
            'porcen_descuen'       => isset($c['porcen_descuen']) && $c['porcen_descuen'] !== '' ? (float) $c['porcen_descuen'] : null,
            'usuario_web'          => $c['usuario_web'] ?? null,
            'codigo_vendedor'      => $c['codigo_vendedor'] ?? null,
            'nombre_vendedor'      => $c['nombre_vendedor'] ?? null,
            // La clave llega con tilde: "código_postal"
            'codigo_postal'        => $c['código_postal'] ?? $c['codigo_postal'] ?? null,
            'synced_at'            => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }
}
