<?php

namespace Tests\Feature;

use App\Jobs\SyncComprasJob;
use App\Models\CompraOrdenPendiente;
use App\Models\CompraPedidoPendiente;
use App\Models\ComprasArticulo;
use App\Models\User;
use App\Models\Venta;
use App\Services\Compras\ReposicionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ComprasTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $user->givePermissionTo($permissions);

        return $user;
    }

    private function articulo(string $codigo, array $attrs = []): ComprasArticulo
    {
        return ComprasArticulo::create(array_merge([
            'codigo' => $codigo,
            'descripcion' => "Producto {$codigo}",
            'cant_stock' => 0,
            'agru_1' => 'DIS',
            'gtin' => null,
            'sin_stock' => false,
            'activo' => true,
            'unidades_por_envase' => null,
            'synced_at' => now(),
        ], $attrs));
    }

    private function venta(string $articulo, string $fecha, float $cantidad, float $subTotal): void
    {
        Venta::create([
            'compro_nro' => 'FEA-00000001',
            'cod_comprobante' => 'FEA',
            'fecha' => $fecha,
            'articulo' => $articulo,
            'cantidad' => $cantidad,
            'sub_total' => $subTotal,
            'synced_at' => now(),
        ]);
    }

    private function dataset(): array
    {
        return app(ReposicionService::class)->construir();
    }

    // ── Acceso ──────────────────────────────────────────────────────────────

    public function test_el_tablero_requiere_permiso_compras_view(): void
    {
        $this->actingAs(User::factory()->create())->get('/compras')->assertStatus(403);
    }

    public function test_el_tablero_rinde_con_el_permiso(): void
    {
        $this->articulo('RE-1');

        $this->actingAs($this->userWith('compras.view'))->get('/compras')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Compras/Index')
                ->has('groups', 1)
                ->has('meses')
                ->has('categorias')
            );
    }

    public function test_sincronizar_requiere_permiso_propio_y_despacha_el_job(): void
    {
        Bus::fake();

        $this->actingAs($this->userWith('compras.view'))->post('/compras/sync')->assertStatus(403);
        Bus::assertNotDispatched(SyncComprasJob::class);

        $this->actingAs($this->userWith('compras.view', 'compras.sync'))
            ->post('/compras/sync')
            ->assertRedirect(route('compras.index'));

        Bus::assertDispatched(SyncComprasJob::class);
    }

    // ── R3: unificación multimarca por GTIN ─────────────────────────────────

    public function test_los_articulos_con_el_mismo_gtin_se_unifican_en_un_renglon(): void
    {
        $this->articulo('RE-1', ['gtin' => 'TAPA ALETA VERDE 16MM']);
        $this->articulo('RE-2', ['gtin' => 'TAPA ALETA VERDE 16MM']);

        $groups = $this->dataset()['groups'];

        $this->assertCount(1, $groups);
        $this->assertSame('TAPA ALETA VERDE 16MM', $groups[0]['n']);
        $this->assertCount(2, $groups[0]['i']);
    }

    /**
     * El campo GTIN se carga a mano y está lleno de comodines. Si agruparan,
     * 617 artículos sin relación entre sí quedarían en un solo renglón.
     */
    public function test_los_gtin_comodin_no_agrupan_y_van_cada_uno_en_su_renglon(): void
    {
        foreach (['NO APLICA', 'no aplica', 'NO APLCIA', 'N/A', 'n/a', '0', '000', '--', 'S/D'] as $i => $comodin) {
            $this->articulo("RE-{$i}", ['gtin' => $comodin]);
        }

        $groups = $this->dataset()['groups'];

        $this->assertCount(9, $groups, 'Ningún comodín debería agrupar');

        foreach ($groups as $grupo) {
            $this->assertCount(1, $grupo['i']);
            // Sin GTIN utilizable, el renglón se llama como el artículo.
            $this->assertStringStartsWith('Producto RE-', $grupo['n']);
        }
    }

    /**
     * La clave del grupo es lo que usa Vue como `:key` y lo que indexa qué filas
     * están desplegadas. El NOMBRE no sirve: en los datos reales 73
     * descripciones se repiten entre 166 artículos distintos sin GTIN, y con el
     * nombre como clave desplegar uno desplegaba el otro.
     */
    public function test_la_clave_del_grupo_es_unica_aunque_se_repita_la_descripcion(): void
    {
        $this->articulo('RE-1', ['descripcion' => 'EMBUDO VIDRIO 9CM']);
        $this->articulo('RE-2', ['descripcion' => 'EMBUDO VIDRIO 9CM']);

        $groups = $this->dataset()['groups'];
        $ids = array_column($groups, 'id');

        $this->assertCount(2, $groups);
        $this->assertSame($ids, array_unique($ids), 'Las claves de grupo deben ser únicas');
        $this->assertSame('EMBUDO VIDRIO 9CM', $groups[0]['n'], 'El nombre sí puede repetirse');
        $this->assertSame('EMBUDO VIDRIO 9CM', $groups[1]['n']);
    }

    public function test_los_articulos_sin_gtin_van_cada_uno_en_su_renglon(): void
    {
        $this->articulo('RE-1', ['gtin' => null]);
        $this->articulo('RE-2', ['gtin' => '']);
        $this->articulo('RE-3', ['gtin' => '   ']);

        $this->assertCount(3, $this->dataset()['groups']);
    }

    // ── R2: el envase es por artículo ───────────────────────────────────────

    public function test_un_grupo_que_mezcla_envases_se_marca_como_varios(): void
    {
        $this->articulo('RE-1', ['gtin' => 'RECOLECTOR', 'unidades_por_envase' => 500]);
        $this->articulo('RE-2', ['gtin' => 'RECOLECTOR', 'unidades_por_envase' => 150]);

        $grupo = $this->dataset()['groups'][0];

        // 0 = "varios": el cálculo usa el envase propio de cada artículo.
        $this->assertSame(0, $grupo['u']);
        $this->assertSame(500, $grupo['i'][0]['u']);
        $this->assertSame(150, $grupo['i'][1]['u']);
    }

    public function test_un_grupo_con_un_solo_envase_lo_reporta(): void
    {
        $this->articulo('RE-1', ['gtin' => 'RECOLECTOR', 'unidades_por_envase' => 500]);
        $this->articulo('RE-2', ['gtin' => 'RECOLECTOR', 'unidades_por_envase' => 500]);

        $this->assertSame(500, $this->dataset()['groups'][0]['u']);
    }

    /** Vacío y 0 significan lo mismo: se cuenta de a uno. */
    public function test_sin_codigo_de_referencia_el_envase_es_uno(): void
    {
        $this->articulo('RE-1', ['unidades_por_envase' => null]);

        $this->assertSame(1, $this->dataset()['groups'][0]['i'][0]['u']);
    }

    // ── R4: la categoría es por artículo ────────────────────────────────────

    public function test_un_grupo_reporta_todas_las_categorias_de_sus_articulos(): void
    {
        $this->articulo('RE-1', ['gtin' => 'TAPA', 'agru_1' => 'DIS']);
        $this->articulo('RE-2', ['gtin' => 'TAPA', 'agru_1' => 'IMP']);

        $this->assertSame(['DIS', 'IMP'], $this->dataset()['groups'][0]['c']);
    }

    public function test_un_articulo_sin_categoria_cae_en_sin_cat(): void
    {
        $this->articulo('RE-1', ['agru_1' => null]);
        $this->articulo('RE-2', ['agru_1' => '   ']);

        $groups = $this->dataset()['groups'];

        $this->assertSame(['SIN_CAT'], $groups[0]['c']);
        $this->assertSame(['SIN_CAT'], $groups[1]['c']);
    }

    // ── R8: productos que no mueven stock ───────────────────────────────────

    public function test_los_articulos_que_no_mueven_stock_van_a_la_categoria_servicios(): void
    {
        $this->articulo('SERV-1', ['sin_stock' => true, 'agru_1' => 'DIS']);

        // Pisa la categoría del ERP: el filtro de la pantalla es por SERVICIOS.
        $this->assertSame(['SERVICIOS'], $this->dataset()['groups'][0]['c']);
    }

    // ── R7: stock negativo ──────────────────────────────────────────────────

    /**
     * El stock negativo viaja CRUDO. Es la pantalla la que lo cuenta como 0 y
     * marca el producto: corregirlo acá escondería el error de carga del ERP.
     */
    public function test_el_stock_negativo_del_erp_viaja_crudo(): void
    {
        $this->articulo('RE-1', ['cant_stock' => -4296450]);

        $this->assertSame(-4296450.0, $this->dataset()['groups'][0]['i'][0]['s']);
    }

    // ── R5: notas de crédito ────────────────────────────────────────────────

    /**
     * Las notas de crédito ya vienen en negativo desde el ERP: se suman tal cual
     * y restan solas. Negarlas infló la facturación un 35% en el prototipo.
     */
    public function test_las_notas_de_credito_restan_de_las_ventas_del_mes(): void
    {
        $this->articulo('RE-1');
        $this->venta('RE-1', '2026-03-10', 100, 50000);
        $this->venta('RE-1', '2026-03-20', -30, -15000);

        $item = $this->dataset()['groups'][0]['i'][0];
        $indice = array_search('2026-03', $this->dataset()['meses'], true);

        $this->assertSame(70.0, $item['v'][$indice]);
        $this->assertSame(35000.0, $item['m'][$indice]);
    }

    public function test_un_articulo_sin_ventas_no_manda_los_arrays_mensuales(): void
    {
        $this->articulo('RE-1');
        $this->articulo('RE-2');
        $this->venta('RE-2', '2026-03-10', 5, 100);

        $groups = $this->dataset()['groups'];

        // Son ~4.400 de ~5.200 artículos: mandarlos con 25 ceros duplicaría el payload.
        $this->assertArrayNotHasKey('v', $groups[0]['i'][0]);
        $this->assertArrayHasKey('v', $groups[1]['i'][0]);
    }

    /** El código puede venir con otra caja desde `ventas`: el cruce normaliza. */
    public function test_las_ventas_cruzan_aunque_el_codigo_venga_en_minuscula(): void
    {
        $this->articulo('RE-1');
        $this->venta('re-1', '2026-03-10', 40, 1000);

        $this->assertContains(40.0, $this->dataset()['groups'][0]['i'][0]['v']);
    }

    // ── R6: reservado ───────────────────────────────────────────────────────

    public function test_solo_cuentan_como_reservadas_las_lineas_del_deposito_unico(): void
    {
        $this->articulo('RE-1');

        $linea = fn (array $attrs) => CompraPedidoPendiente::create(array_merge([
            'articulo' => 'RE-1',
            'cant_pend' => 10,
            'reser' => 'S',
            'deposito_reserva' => 'Deposito unico',
            'estado' => 'PENDIENTE',
            'synced_at' => now(),
        ], $attrs));

        $linea([]);
        $linea(['deposito_reserva' => 'CUARENTENA']);
        $linea(['deposito_reserva' => 'PROGRAMADAS']);
        $linea(['reser' => 'N']);

        $this->assertSame(10.0, $this->dataset()['groups'][0]['i'][0]['r']);
    }

    /**
     * La regla no está conciliada con el ERP, así que los estados excluidos son
     * configurables. Sin este test, cambiar el config no tendría red.
     */
    public function test_los_estados_excluidos_del_config_no_cuentan_como_reserva(): void
    {
        $this->articulo('RE-1');

        foreach (['PENDIENTE', 'ADMINISTRACION'] as $estado) {
            CompraPedidoPendiente::create([
                'articulo' => 'RE-1',
                'cant_pend' => 10,
                'reser' => 'S',
                'deposito_reserva' => 'Deposito unico',
                'estado' => $estado,
                'synced_at' => now(),
            ]);
        }

        $this->assertSame(20.0, $this->dataset()['groups'][0]['i'][0]['r']);

        config(['compras.reserva.estados_excluidos' => ['ADMINISTRACION']]);

        $this->assertSame(10.0, $this->dataset()['groups'][0]['i'][0]['r']);
    }

    public function test_la_oc_pendiente_se_suma_por_articulo(): void
    {
        $this->articulo('RE-1');

        foreach ([100, 250] as $cantidad) {
            CompraOrdenPendiente::create([
                'tipo' => 'OC',
                'articulo' => 'RE-1',
                'cant_pend' => $cantidad,
                'synced_at' => now(),
            ]);
        }

        $this->assertSame(350.0, $this->dataset()['groups'][0]['i'][0]['o']);
    }

    // ── Meses ───────────────────────────────────────────────────────────────

    public function test_el_historial_termina_en_el_ultimo_mes_con_ventas(): void
    {
        config(['compras.meses_historial' => 3]);

        $this->articulo('RE-1');
        $this->venta('RE-1', '2026-05-10', 1, 1);

        $this->assertSame(['2026-03', '2026-04', '2026-05'], $this->dataset()['meses']);
    }

    public function test_sin_ventas_no_hay_meses_y_el_tablero_igual_rinde(): void
    {
        $this->articulo('RE-1');

        $this->assertSame([], $this->dataset()['meses']);

        $this->actingAs($this->userWith('compras.view'))->get('/compras')->assertOk();
    }
}
