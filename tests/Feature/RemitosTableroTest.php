<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\Remito;
use App\Models\User;
use App\Models\VentaPartida;
use App\Services\RpSistemas\RemitoSyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Remitos de venta: el denominador de la tasa de fallos del tablero y la medida
 * de pedidos con la que escala el objetivo de armado.
 */
class RemitosTableroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function remito(string $nro, string $fecha, ?string $cliente, string $tipo = 'VR8'): void
    {
        Remito::create(['compro_nro' => $nro, 'tipo' => $tipo, 'fecha' => $fecha, 'cliente_numero' => $cliente]);
    }

    public function test_el_sync_normaliza_la_fila_de_la_vista(): void
    {
        $fila = (new RemitoSyncService)->mapear([
            'compro_nro' => ' VR8-00027682 ',
            'tipo' => 'VR8',
            'fecha' => '2026-10-21 00:00:00.000',
            'cliente' => ' 2211 ',
        ], Carbon::parse('2026-11-06 10:00:00'));

        $this->assertSame('VR8-00027682', $fila['compro_nro']);
        $this->assertSame('2026-10-21', $fila['fecha']);
        $this->assertSame('2211', $fila['cliente_numero']);

        $sinCliente = (new RemitoSyncService)->mapear(['compro_nro' => 'VR8-1', 'tipo' => 'VR8', 'fecha' => '2026-10-21', 'cliente' => ' '], Carbon::now());
        $this->assertNull($sinCliente['cliente_numero']);
    }

    /**
     * El maestro de comprobantes del cliente lista cuatro remitos de venta; el cuarto,
     * VNN, es "Remito Prueba". Contarlo sumaría remitos de prueba a la tasa.
     */
    public function test_las_series_de_remito_no_incluyen_el_remito_de_prueba(): void
    {
        $this->assertEqualsCanonicalizing(['VR8', 'VRM', 'VR6'], VentaPartida::SERIES_DE_REMITO);
        $this->assertNotContains('VNN', VentaPartida::SERIES_DE_REMITO);
    }

    public function test_el_tablero_cuenta_los_remitos_por_mes_y_por_cliente(): void
    {
        $mantel = Cliente::create(['numero' => '2211', 'razon_social' => 'Laboratorio Mantel S.A.']);
        Observacion::create([
            'numero' => '0001-26', 'anio' => 2026, 'tipo' => 'falla_proceso_dep', 'estado' => 'clasificada',
            'origen' => 'interna', 'contacto_nombre' => 'T', 'contacto_email' => 't@example.com',
            'titulo' => 'Caso', 'descripcion' => 'D', 'cliente_id' => $mantel->id,
        ]);

        $this->remito('VR8-1', '2026-09-03', '2211');
        $this->remito('VR8-2', '2026-09-20', '2211');
        $this->remito('VR8-3', '2026-10-02', '2211');
        $this->remito('VR8-4', '2026-10-05', '999');   // otro cliente que no está en el tablero

        $user = tap(User::factory()->create())->assignRole('tablero');

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('tablero.remitosPorMes', ['2026-09' => 2, '2026-10' => 2])
            // Solo los clientes que aparecen en el tablero: no se manda el padrón entero.
            ->where('tablero.remitosPorCliente', ['2211' => ['2026-09' => 2, '2026-10' => 1]])
            ->where('tablero.observaciones.0.cn', '2211'));
    }

    public function test_sin_remitos_el_tablero_viaja_igual(): void
    {
        $user = tap(User::factory()->create())->assignRole('tablero');

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p
            ->where('tablero.remitosPorMes', [])
            ->where('tablero.remitosPorCliente', []));
    }
}
