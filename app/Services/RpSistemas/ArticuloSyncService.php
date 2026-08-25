<?php

namespace App\Services\RpSistemas;

use App\Models\Articulo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArticuloSyncService
{
    public function __construct(private RpSistemasClient $client) {}

    /**
     * Sincroniza el catálogo de artículos de RP Sistemas a la tabla local.
     * Upsert por `codigo` (idempotente).
     *
     * `articulos.php` no expone ningún campo de estado, y la conexión `erp`
     * tampoco tiene una vista de artículos (a diferencia de proveedores). La
     * única señal disponible es estar o no en el feed: lo que llega en esta
     * corrida queda `activo`, y lo que dejó de venir se marca `activo = false`
     * comparando `synced_at` contra el timestamp de esta corrida — sin
     * necesidad de un `whereNotIn` con miles de códigos.
     *
     * `synced_at IS NULL` (los artículos que creó el Excel de Calidad con
     * "crear faltantes" porque RP no los tenía) queda afuera del
     * `whereNotNull` y por lo tanto nunca se desactiva por esta vía: no
     * vinieron de un feed del que puedan "dejar de venir".
     *
     * Al final intenta vincular el proveedor: `codigo_proveedor` (el string
     * suelto del ERP) casi nunca es un número de proveedor real — verificado
     * a mano, de ~70 valores distintos solo un puñado coincide con
     * `proveedores.numero` — pero cuando coincide, el dato es correcto (se
     * confirmó contra un proveedor ya cargado a mano por Excel, y matcheaba).
     * Vale la pena aprovechar esos casos aunque sean pocos. Nunca pisa un
     * `proveedor_id` ya asignado: es un campo propio del panel, y esto es un
     * intento best-effort de completarlo, no una fuente de verdad.
     *
     * @return array{procesados: int, activos: int, desactivados: int, proveedores_vinculados: int}
     */
    public function sync(): array
    {
        $pagina = 1;
        $tamano = (int) config('services.rpsistemas.page_size', 100);
        $total = 0;
        $syncedAt = Carbon::now();
        // Con microsegundos: la comparación de más abajo distingue dos
        // corridas seguidas (dos clics en "Sincronizar") aunque caigan dentro
        // del mismo segundo — con precisión de segundo compartirían el mismo
        // valor y la desactivación no detectaría nada.
        $syncedAtValor = $syncedAt->format('Y-m-d H:i:s.u');

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
                $lote = array_map(fn ($a) => $this->mapear($a, $syncedAt, $syncedAtValor), $bloque);

                // No incluir 'fecha_vencimiento', 'pm', 'legajo',
                // 'observaciones', 'link_registro' ni 'proveedor_id' acá: son
                // campos propios (no gestionados por el ERP) que se cargan a
                // mano o por Excel desde el panel, y la sync nunca debe
                // pisarlos. Mismo contrato que ClienteSyncService con
                // 'fecha_vencimiento' / 'mail_nuevo'.
                //
                // 'codigo_proveedor' sí va: ese es el string del ERP, distinto
                // de la FK 'proveedor_id' que resuelve contra el padrón local.
                //
                // 'activo' también va: a diferencia de los campos de arriba,
                // lo determina el ERP (estar o no en el feed), no el panel.
                Articulo::upsert(
                    $lote,
                    ['codigo'],
                    [
                        'descripcion', 'descripcion_adicional', 'codigo_barras', 'unidad_medida',
                        'codigo_agrupacion_1', 'descripcion_agrupacion_1',
                        'codigo_agrupacion_2', 'descripcion_agrupacion_2',
                        'codigo_agrupacion_3', 'descripcion_agrupacion_3',
                        'stock', 'stock_disponible', 'codigo_proveedor',
                        'modificado_en', 'synced_at', 'activo', 'updated_at',
                    ]
                );

                $total += count($lote);
            }

            $pagina++;

        } while (RpSistemasClient::tienePaginaSiguiente($paginado));

        // Nunca se desactiva sobre un feed vacío: un mal día del ERP no puede
        // dejar el selector de productos del portal público sin nada.
        $desactivados = 0;

        if ($total > 0) {
            // `where('activo', true)` no es solo optimización: sin esto, un
            // artículo ya desactivado vuelve a tocar `updated_at` en cada
            // corrida y MySQL lo cuenta como fila afectada, así que
            // `$desactivados` nunca bajaría a 0 aunque nada cambie de verdad.
            $desactivados = Articulo::whereNotNull('synced_at')
                ->where('synced_at', '<', $syncedAtValor)
                ->where('activo', true)
                ->update(['activo' => false, 'updated_at' => Carbon::now()]);
        }

        // Subconsulta escalar y no un UPDATE...JOIN: SQLite (lo que usan los
        // tests) no soporta join en un UPDATE, pero sí una subconsulta, y
        // rinde igual de bien en MySQL. `proveedores.numero` tiene índice
        // único (permite varios NULL, pero no un no-NULL repetido), así que
        // la subconsulta nunca puede devolver más de una fila.
        //
        // Recorrer ~4000 filas en PHP para resolver cada una contra ~1900
        // proveedores sería lento y no aporta nada que SQL no resuelva mejor.
        // `whereNull('proveedor_id')` es lo que garantiza que nunca se pisa
        // una asignación manual.
        $vinculados = DB::table('articulos')
            ->whereNull('proveedor_id')
            ->whereNotNull('codigo_proveedor')
            ->whereIn('codigo_proveedor', function ($query) {
                $query->select('numero')->from('proveedores')->whereNotNull('numero');
            })
            ->update([
                'proveedor_id' => DB::raw(
                    '(select id from proveedores where proveedores.numero = articulos.codigo_proveedor)'
                ),
            ]);

        Log::info("RpSistemas: sincronización completada — {$total} artículos procesados, {$desactivados} desactivados, {$vinculados} proveedores vinculados");

        return ['procesados' => $total, 'activos' => $total, 'desactivados' => $desactivados, 'proveedores_vinculados' => $vinculados];
    }

    private function mapear(array $a, Carbon $syncedAt, string $syncedAtValor): array
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
            'synced_at' => $syncedAtValor,
            'activo' => true,
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
