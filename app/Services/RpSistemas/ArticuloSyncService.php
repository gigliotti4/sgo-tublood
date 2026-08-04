<?php

namespace App\Services\RpSistemas;

use App\Models\Articulo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ArticuloSyncService
{
    public function __construct(private RpSistemasClient $client) {}

    /**
     * Sincroniza el catálogo de artículos de RP Sistemas a la tabla local.
     * Upsert por `codigo` (idempotente).
     *
     * @return int Total de registros sincronizados
     */
    public function sync(): int
    {
        $pagina = 1;
        $tamano = (int) config('services.rpsistemas.page_size', 100);
        $total = 0;
        $syncedAt = Carbon::now();

        Log::info('RpSistemas: iniciando sincronización de artículos');

        do {
            $result = $this->client->getArticulos($pagina, max($tamano, 500));
            $datos = $result['datos'];
            $paginado = $result['paginado'];

            if (empty($datos)) {
                break;
            }

            // De a 500 para no armar un INSERT gigante: hoy el ERP devuelve las
            // ~4000 filas de una sola vez, no en páginas.
            foreach (array_chunk($datos, 500) as $bloque) {
                $lote = array_map(fn ($a) => $this->mapear($a, $syncedAt), $bloque);

                // No incluir 'fecha_vencimiento', 'pm', 'legajo' ni
                // 'observaciones' acá: son campos propios (no gestionados por
                // el ERP) que se cargan a mano o por Excel desde el panel, y la
                // sync nunca debe pisarlos. Mismo contrato que
                // ClienteSyncService con 'fecha_vencimiento' / 'mail_nuevo'.
                Articulo::upsert(
                    $lote,
                    ['codigo'],
                    [
                        'descripcion', 'descripcion_adicional', 'codigo_barras', 'unidad_medida',
                        'codigo_agrupacion_1', 'descripcion_agrupacion_1',
                        'codigo_agrupacion_2', 'descripcion_agrupacion_2',
                        'codigo_agrupacion_3', 'descripcion_agrupacion_3',
                        'stock', 'stock_disponible', 'codigo_proveedor',
                        'modificado_en', 'synced_at', 'updated_at',
                    ]
                );

                $total += count($lote);
            }

            $pagina++;

        } while (RpSistemasClient::tienePaginaSiguiente($paginado));

        Log::info("RpSistemas: sincronización completada — {$total} artículos procesados");

        return $total;
    }

    private function mapear(array $a, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'codigo' => trim((string) ($a['codigo_articulo'] ?? '')),
            'descripcion' => $this->texto($a['descripcion_articulo'] ?? ''),
            'descripcion_adicional' => $this->texto($a['descripcion_adicional'] ?? '') ?: null,
            'codigo_barras' => $this->texto($a['codigo_barras'] ?? '') ?: null,
            'unidad_medida' => $this->texto($a['UM'] ?? '') ?: null,
            'codigo_agrupacion_1' => $this->texto($a['codigo_agrupacion_1'] ?? '') ?: null,
            'descripcion_agrupacion_1' => $this->texto($a['descripcion_agrupacion_1'] ?? '') ?: null,
            'codigo_agrupacion_2' => $this->texto($a['codigo_agrupacion_2'] ?? '') ?: null,
            'descripcion_agrupacion_2' => $this->texto($a['descripcion_agrupacion_2'] ?? '') ?: null,
            'codigo_agrupacion_3' => $this->texto($a['codigo_agrupacion_3'] ?? '') ?: null,
            'descripcion_agrupacion_3' => $this->texto($a['descripcion_agrupacion_3'] ?? '') ?: null,
            'stock' => $this->numero($a['stock'] ?? null),
            'stock_disponible' => $this->numero($a['stock_disponible'] ?? null),
            'codigo_proveedor' => $this->texto($a['codigo_proveedor'] ?? '') ?: null,
            'modificado_en' => $this->fecha($a['fecha_modi'] ?? null),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Deshace el doble encoding con el que articulos.php devuelve el texto.
     *
     * El ERP toma bytes UTF-8 y los vuelve a codificar byte a byte como si
     * fueran de un charset de 8 bits: "1½" llega como "1Â½" y "DISTRIBUCIÓN"
     * como "DISTRIBUCIÃ<0x93>N". Afecta al ~97% del catálogo. Se revierte
     * volviendo a ese charset y releyendo los bytes como UTF-8.
     *
     * ⚠️ Se prueban los dos charsets, en este orden, porque el ERP mezcla:
     *  - ISO-8859-1 cubre el rango 0x80-0x9F, que es donde caen las segundas
     *    mitades de Ó (C3 93) y compañía. Es el caso real más común.
     *  - CP1252 cubre las variantes donde ese byte llegó como comilla o guion
     *    tipográfico (“ – —), que en ISO-8859-1 no existen.
     *
     * Solo se acepta la conversión si el resultado es UTF-8 válido: si el texto
     * ya venía bien (clientes.php no tiene este problema), queda intacto.
     */
    private function texto(mixed $valor): string
    {
        $s = trim((string) $valor);

        if ($s === '') {
            return '';
        }

        foreach (['ISO-8859-1', 'CP1252'] as $charset) {
            $bytes = @mb_convert_encoding($s, $charset, 'UTF-8');

            if ($bytes !== false && $bytes !== '' && $bytes !== $s && mb_check_encoding($bytes, 'UTF-8')) {
                return $bytes;
            }
        }

        return $s;
    }

    private function numero(mixed $valor): ?float
    {
        return ($valor === null || $valor === '') ? null : (float) $valor;
    }

    private function fecha(mixed $valor): ?string
    {
        $s = trim((string) $valor);

        if ($s === '') {
            return null;
        }

        try {
            return Carbon::parse($s)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
