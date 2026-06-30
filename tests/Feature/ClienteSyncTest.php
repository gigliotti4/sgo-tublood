<?php

namespace Tests\Feature;

use App\Exceptions\RpSistemasException;
use App\Models\Cliente;
use App\Services\RpSistemas\ClienteSyncService;
use App\Services\RpSistemas\RpSistemasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClienteSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeResponse(array $datos, array $paginado): array
    {
        return [[
            'datos_recibidos' => [],
            'info' => [
                'servicio' => 'clientes.php',
                'estado'   => 200,
                'datos'    => $datos,
                'datos_paginado' => [$paginado],
            ],
        ]];
    }

    private function makeCliente(string $numero, string $razon = 'Empresa SA'): array
    {
        return [
            'numero'               => $numero,
            'razon'                => $razon,
            'nombre_fantasia'      => '',
            'cuit'                 => '30-12345678-9',
            'codigo_iva'           => 1,
            'descripcion_iva'      => 'IVA Responsable Inscripto',
            'telefono'             => '01112345678',
            'mail'                 => 'test@empresa.com',
            'contacto'             => '',
            'domicilio'            => 'Av. Test 123',
            'localidad'            => 'Buenos Aires',
            'codigo_provincia'     => 'BA',
            'descripcion_provincia'=> 'Buenos Aires',
            'porcen_descuen'       => 10.00,
            'usuario_web'          => 'C1',
            'codigo_vendedor'      => '',
            'nombre_vendedor'      => '',
            'código_postal'        => '1000',
        ];
    }

    private function paginado(bool $tieneSiguiente, int $pagina = 1): array
    {
        return [
            'total_registros'      => '2',
            'total_paginas'        => '2',
            'pagina_actual'        => (string) $pagina,
            'tamano_pagina'        => '1',
            'tiene_pagina_siguiente' => $tieneSiguiente ? '1' : '0',
            'tiene_pagina_anterior'  => $pagina > 1 ? '1' : '0',
        ];
    }

    public function test_sync_inserta_clientes_correctamente(): void
    {
        Http::fake([
            '*/clientes.php' => Http::response(
                $this->makeResponse([$this->makeCliente('1', 'Empresa Uno')], $this->paginado(false)),
                200
            ),
        ]);

        $service = new ClienteSyncService(new RpSistemasClient());
        $total   = $service->sync();

        $this->assertSame(1, $total);
        $this->assertDatabaseHas('clientes', [
            'numero'       => '1',
            'razon_social' => 'Empresa Uno',
            'codigo_postal'=> '1000',
        ]);
    }

    public function test_sync_es_idempotente(): void
    {
        Http::fake([
            '*/clientes.php' => Http::response(
                $this->makeResponse([$this->makeCliente('1', 'Empresa Uno')], $this->paginado(false)),
                200
            ),
        ]);

        $service = new ClienteSyncService(new RpSistemasClient());
        $service->sync();
        $service->sync();

        $this->assertSame(1, Cliente::count());
    }

    public function test_sync_pagina_hasta_fin(): void
    {
        Http::fake([
            '*/clientes.php' => Http::sequence()
                ->push($this->makeResponse([$this->makeCliente('1', 'Empresa Uno')], $this->paginado(true, 1)), 200)
                ->push($this->makeResponse([$this->makeCliente('2', 'Empresa Dos')], $this->paginado(false, 2)), 200),
        ]);

        $service = new ClienteSyncService(new RpSistemasClient());
        $total   = $service->sync();

        $this->assertSame(2, $total);
        $this->assertSame(2, Cliente::count());
    }

    public function test_sync_lanza_excepcion_cuando_api_retorna_estado_400(): void
    {
        Http::fake([
            '*/clientes.php' => Http::response([[
                'datos_recibidos' => [],
                'info' => [
                    'servicio' => 'clientes.php',
                    'estado'   => 400,
                    'msj'      => 'Error al realizar la operacion.',
                ],
            ]], 200),
        ]);

        $this->expectException(RpSistemasException::class);
        $this->expectExceptionMessage('Error al realizar la operacion.');

        $service = new ClienteSyncService(new RpSistemasClient());
        $service->sync();
    }
}
