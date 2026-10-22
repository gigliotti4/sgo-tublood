<?php

namespace Tests\Feature;

use App\Models\Proforma;
use App\Models\User;
use App\Services\RpSistemas\ProformaSyncService;
use App\Support\Urgencia;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tablero de urgencias en facturas proforma (pedido del 22/10/2026).
 *
 * La regla de "urgente" es un port del tablero suelto que leía el Excel; con
 * los datos de ese Excel da exactamente lo que mostraba (septiembre 2026: 26
 * urgencias, TUBLOOD SA 10, Carolina Martinchuk 9, …). Estos tests fijan sus
 * bordes.
 */
class UrgenciasTest extends TestCase
{
    use RefreshDatabase;

    // ── Qué cuenta como urgente ─────────────────────────────────────────────

    /** Casos reales de la observación general de las proformas. */
    public function test_detecta_las_urgencias_como_se_escriben_en_el_erp(): void
    {
        foreach ([
            'urgente pedido',
            'URGENTE PEDIDO',
            'URGENCIA - PARA ENTREGAR JUEVES 22/10',
            'PROFORMA URGENTE PARA REALIZAR CAMBIO CONFORME A SGO PUBLICADO',
            'PEDIDO URGENTE PARA SALIR Y ENTREGAR DIA JUEVES 21/10',
            'urgentísimo',
        ] as $texto) {
            $this->assertTrue(Urgencia::detectar($texto), $texto);
        }
    }

    /** "Teniendo en cuenta que pudo haberse tipeado mal". */
    public function test_detecta_urgente_mal_escrito(): void
    {
        foreach (['URJENTE', 'UGRENTE', 'URRGENTE', 'URGNTE', 'URGENTISMO', 'RUGENTE', 'URGENCAI'] as $texto) {
            $this->assertTrue(Urgencia::detectar($texto), $texto);
        }
    }

    public function test_no_marca_palabras_que_solo_se_parecen_de_lejos(): void
    {
        foreach (['', '   ', 'URUGUAY', 'RUTA 8', 'GENTE', 'ENTREGAR EL LUNES', 'URUS', 'RECIBO', null] as $texto) {
            $this->assertFalse(Urgencia::detectar($texto), (string) $texto);
        }
    }

    // ── Sync ────────────────────────────────────────────────────────────────

    public function test_el_sync_normaliza_la_fila_de_la_vista(): void
    {
        $fila = (new ProformaSyncService)->mapear([
            'compro_nro' => ' 52182 ',
            'fecha' => '2026-10-21 00:00:00.000',
            'cliente' => '2211',
            'razon_social' => 'MEDISTORE SRL',
            'vendedor' => 'Leandro Davegno',
            'codi_vende' => 'LD',
            'observacion' => '  ',
            'estado' => 'PENDIENTE',
        ], Carbon::parse('2026-10-22 10:00:00'));

        $this->assertSame('52182', $fila['compro_nro']);
        $this->assertSame('2026-10-21', $fila['fecha']);
        $this->assertSame('2211', $fila['cliente_numero']);
        $this->assertNull($fila['observacion']);
    }

    // ── Pantalla y acceso ───────────────────────────────────────────────────

    private function proforma(string $nro, string $fecha, string $vendedor, ?string $observacion): void
    {
        Proforma::create([
            'compro_nro' => $nro, 'fecha' => $fecha, 'cliente_numero' => '10',
            'razon_social' => 'CLIENTE SA', 'vendedor' => $vendedor, 'observacion' => $observacion,
        ]);
    }

    public function test_el_rol_urgencias_entra_y_el_dataset_marca_las_urgentes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->proforma('1', '2026-09-03', 'Nadia Defelipe', 'URGENTE PEDIDO');
        $this->proforma('2', '2026-09-04', 'Nadia Defelipe', 'entregar el lunes');
        $this->proforma('3', '2026-10-01', 'Leandro Davegno', 'urjente');

        $usuario = tap(User::factory()->create())->assignRole('urgencias');

        $this->actingAs($usuario)->get('/urgencias')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Urgencias/Index')
                ->where('meses', ['2026-09', '2026-10'])
                ->where('vendedores', ['Nadia Defelipe', 'Leandro Davegno'])
                ->where('filas', [[0, 0, 0, 1], [0, 0, 0, 0], [1, 1, 0, 1]])
                ->has('urgentes', 2)
                ->where('urgentes.0.o', 'URGENTE PEDIDO'));
    }

    /** Un nombre que parece número tiene que llegar como texto, o el filtro de cliente no lo encuentra. */
    public function test_los_nombres_numericos_llegan_como_texto(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Proforma::create(['compro_nro' => '9', 'fecha' => '2026-09-03', 'razon_social' => '123', 'vendedor' => '456']);

        $this->actingAs(tap(User::factory()->create())->assignRole('urgencias'))->get('/urgencias')
            ->assertInertia(fn ($page) => $page
                ->where('vendedores', ['456'])
                ->where('clientes', ['123']));
    }

    public function test_sin_el_rol_no_entra(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $interno = tap(User::factory()->create())->assignRole('usuario_interno');

        $this->actingAs($interno)->get('/urgencias')->assertForbidden();
        $this->actingAs($interno)->post('/urgencias/sync')->assertForbidden();
    }

    /** Mismo criterio que Compras: un rol propio, que no viene con los roles generales. */
    public function test_el_rol_trae_solo_lo_suyo_y_los_generales_no_lo_traen(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['urgencias.view', 'urgencias.sync'],
            Role::findByName('urgencias')->permissions->pluck('name')->all(),
        );

        foreach (['usuario_interno', 'admin', 'viewer'] as $rol) {
            $this->assertFalse(Role::findByName($rol)->hasPermissionTo('urgencias.view'), "{$rol} no debería ver urgencias");
        }
    }
}
