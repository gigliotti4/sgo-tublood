<?php

namespace App\Services\RpSistemas;

use App\Exceptions\RpSistemasException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RpSistemasClient
{
    private string $baseUrl;

    private string $token;

    private int $timeout = 30;

    private int $retries = 2;

    private int $retryDelayMs = 300;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.rpsistemas.base_url'), '/');
        $this->token = (string) config('services.rpsistemas.token');
    }

    /**
     * Consulta una página de clientes del sistema RP.
     *
     * @param  int  $pagina  Página a consultar (1-based)
     * @param  int  $tamano  Registros por página
     * @param  array  $filtros  Filtros opcionales: numero, cuit, mail, usuario_web, celular
     * @return array{datos: array, paginado: array}
     *
     * @throws RpSistemasException
     */
    public function getClientes(int $pagina = 1, int $tamano = 100, array $filtros = []): array
    {
        $body = [
            'cliente' => array_merge([
                'numero' => '',
                'cuit' => '',
                'mail' => '',
                'usuario_web' => '',
                'celular' => '',
                'pagina' => (string) $pagina,
                'tamanoPagina' => (string) $tamano,
            ], $filtros),
        ];

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelayMs)
                ->post('clientes.php', $body);
        } catch (\Throwable $e) {
            Log::error('RpSistemas: error de conexión al consultar clientes', [
                'pagina' => $pagina,
                'message' => $e->getMessage(),
            ]);
            throw new RpSistemasException(
                'Error de conexión con RP Sistemas: '.$e->getMessage(),
                'clientes.php',
                0,
                $e
            );
        }

        // La respuesta viene siempre envuelta en un array [{ datos_recibidos, info }]
        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload[0]['info'])) {
            Log::error('RpSistemas: respuesta inesperada de clientes.php', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RpSistemasException(
                'Respuesta inesperada de RP Sistemas (estructura inválida)',
                'clientes.php'
            );
        }

        $info = $payload[0]['info'];

        // El error puede venir con HTTP 200, por eso validamos info.estado
        if (($info['estado'] ?? 0) !== 200) {
            $msj = $info['msj'] ?? 'Error desconocido';
            Log::warning('RpSistemas: clientes.php devolvió estado '.($info['estado'] ?? '?'), [
                'msj' => $msj,
                'pagina' => $pagina,
            ]);
            throw new RpSistemasException($msj, 'clientes.php', (int) ($info['estado'] ?? 400));
        }

        return [
            'datos' => $info['datos'] ?? [],
            'paginado' => $info['datos_paginado'][0] ?? [],
        ];
    }

    /**
     * Consulta una página de artículos del sistema RP.
     *
     * Dos diferencias con `getClientes()` que no son obvias:
     *  - `pagina` y `tamanoPagina` van en la **raíz** del body, no dentro del
     *    objeto `articulo` (así lo documenta RP y así responde).
     *  - `codigo_lista_precios` es obligatorio: el servicio lo usa para
     *    valorizar. No guardamos precios, pero sin ese parámetro devuelve 400.
     *
     * Ojo: hoy el endpoint **ignora `tamanoPagina` y devuelve todo el catálogo
     * en una sola respuesta** (`tiene_pagina_siguiente: "0"`). El bucle de
     * paginación de arriba igual funciona si algún día empieza a paginar.
     *
     * @return array{datos: array, paginado: array}
     *
     * @throws RpSistemasException
     */
    public function getArticulos(int $pagina = 1, int $tamano = 500, array $filtros = []): array
    {
        $body = [
            'articulo' => array_merge([
                'codigo' => '',
                'codigo_barras' => '',
                'codigo_proveedor' => '',
                'codigo_deposito' => '',
                'codigo_lista_precios' => (string) config('services.rpsistemas.lista_precios'),
                'descripcion' => '',
            ], $filtros),
            'pagina' => $pagina,
            'tamanoPagina' => $tamano,
        ];

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->timeout($this->timeout)
                ->retry($this->retries, $this->retryDelayMs)
                ->post('articulos.php', $body);
        } catch (\Throwable $e) {
            Log::error('RpSistemas: error de conexión al consultar artículos', [
                'pagina' => $pagina,
                'message' => $e->getMessage(),
            ]);
            throw new RpSistemasException(
                'Error de conexión con RP Sistemas: '.$e->getMessage(),
                'articulos.php',
                0,
                $e
            );
        }

        $payload = $response->json();

        // Los errores de articulos.php NO vienen con el envoltorio habitual:
        // salen como {"success":false,"code":400,"message":"..."}. Se contempla
        // acá para que el motivo real llegue al log y no un "estructura inválida".
        if (is_array($payload) && ($payload['success'] ?? null) === false) {
            $msj = $payload['message'] ?? 'Error desconocido';
            Log::warning('RpSistemas: articulos.php devolvió un error', ['msj' => $msj, 'pagina' => $pagina]);
            throw new RpSistemasException($msj, 'articulos.php', (int) ($payload['code'] ?? 400));
        }

        if (! is_array($payload) || ! isset($payload[0]['info'])) {
            Log::error('RpSistemas: respuesta inesperada de articulos.php', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            throw new RpSistemasException(
                'Respuesta inesperada de RP Sistemas (estructura inválida)',
                'articulos.php'
            );
        }

        $info = $payload[0]['info'];

        if (($info['estado'] ?? 0) !== 200) {
            $msj = $info['msj'] ?? 'Error desconocido';
            Log::warning('RpSistemas: articulos.php devolvió estado '.($info['estado'] ?? '?'), [
                'msj' => $msj,
                'pagina' => $pagina,
            ]);
            throw new RpSistemasException($msj, 'articulos.php', (int) ($info['estado'] ?? 400));
        }

        return [
            'datos' => $info['datos'] ?? [],
            'paginado' => $info['datos_paginado'][0] ?? [],
        ];
    }

    /**
     * Indica si hay página siguiente, manejando que el valor viene como string "0"/"1".
     */
    public static function tienePaginaSiguiente(array $paginado): bool
    {
        return ($paginado['tiene_pagina_siguiente'] ?? '0') === '1';
    }
}
