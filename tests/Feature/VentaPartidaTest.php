<?php

namespace Tests\Feature;

use App\Models\Partida;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaPartida;
use App\Services\RpSistemas\VentaPartidaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Lotes despachados por comprobante de venta.
 *
 * El sync se prueba por `mapear()`, sin tocar SQL Server: la conexión `erp` es
 * de un sistema de terceros y no entra en los tests.
 */
class VentaPartidaTest extends TestCase
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

    /** @param array<string, mixed> $attrs */
    private function despacho(array $attrs = []): VentaPartida
    {
        return VentaPartida::create(array_merge([
            'compro_nro' => 'FEA-00036326',
            'cod_comprobante' => 'FEA',
            'numero' => 36326,
            'codigo_articulo' => 'RE-4176',
            'codigo_partida' => '160224',
            'cantidad' => 1,
            'remito_tipo' => 'VR8',
            'remito_numero' => 8500,
            'fecha' => '2024-08-02',
            'synced_at' => now(),
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function venta(array $attrs = []): Venta
    {
        return Venta::create(array_merge([
            'compro_nro' => 'FEA-00036326',
            'cod_comprobante' => 'FEA',
            'fecha' => '2024-08-02',
            'cliente' => 1234,
            'razon_social' => 'SANATORIO DE PRUEBA',
            'provincia' => 'BUENOS AIRES',
            'articulo' => 'RE-4176',
            'descrip_arti' => 'RECOLECTOR DE ORINA',
            'cantidad' => 1,
            'remito_nro' => 8500,
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function partida(array $attrs = []): Partida
    {
        return Partida::create(array_merge([
            'codigo_articulo' => 'RE-4176',
            'codigo_partida' => '160224',
            'fecha_vencimiento' => '2027-02-28',
            'proveedor_numero' => '229',
            'synced_at' => now(),
        ], $attrs));
    }

    // ---------- mapeo del sync ----------

    public function test_mapear_reconstruye_el_compro_nro_con_ocho_digitos(): void
    {
        $mapeado = (new VentaPartidaSyncService)->mapear([
            'TIPO' => 'FEA',
            'NUM' => 36326,
            'COD_ARTICULO' => 'RE-4176',
            'COD_PARTIDA' => '160224',
            'cantidad' => 1.0,
            'fecha' => '2024-08-02 00:00:00.000',
            'remito_tipo' => 'VR8',
            'remito_numero' => 8500,
        ], Carbon::parse('2026-09-01 10:00:00'));

        $this->assertSame('FEA-00036326', $mapeado['compro_nro']);
        $this->assertSame('FEA', $mapeado['cod_comprobante']);
        $this->assertSame(36326, $mapeado['numero']);
        $this->assertSame('VR8', $mapeado['remito_tipo']);
        $this->assertSame(8500, $mapeado['remito_numero']);
        $this->assertSame('2024-08-02', $mapeado['fecha']);
    }

    /**
     * `F-A` lleva un guion adentro del tipo: partir `compro_nro` por `-` daría
     * `F`. Por eso el join usa `cod_comprobante` y no el prefijo.
     */
    public function test_mapear_soporta_tipos_con_guion_adentro(): void
    {
        $mapeado = (new VentaPartidaSyncService)->mapear([
            'TIPO' => 'F-A',
            'NUM' => 1857,
            'COD_ARTICULO' => 'RE-1',
            'COD_PARTIDA' => 'L1',
        ], Carbon::now());

        $this->assertSame('F-A-00001857', $mapeado['compro_nro']);
        $this->assertSame('F-A', $mapeado['cod_comprobante']);
    }

    /** En los comprobantes de venta el kardex trae `CANTI` negativa. */
    public function test_mapear_guarda_la_cantidad_en_positivo(): void
    {
        $mapeado = (new VentaPartidaSyncService)->mapear([
            'TIPO' => 'FEA', 'NUM' => 1, 'COD_ARTICULO' => 'A', 'COD_PARTIDA' => 'L',
            // La query ya aplica ABS(); acá se verifica que el mapeo no lo deshaga.
            'cantidad' => 5.0,
        ], Carbon::now());

        $this->assertSame(5.0, $mapeado['cantidad']);
    }

    public function test_mapear_tolera_un_movimiento_sin_remito(): void
    {
        $mapeado = (new VentaPartidaSyncService)->mapear([
            'TIPO' => 'FEA', 'NUM' => 1, 'COD_ARTICULO' => 'A', 'COD_PARTIDA' => 'L',
            'remito_tipo' => null, 'remito_numero' => 0,
        ], Carbon::now());

        $this->assertNull($mapeado['remito_tipo']);
        $this->assertNull($mapeado['remito_numero']);
    }

    // ---------- el remito con su serie ----------

    public function test_el_remito_se_expone_con_su_serie(): void
    {
        $this->assertSame('VR8-8500', $this->despacho()->remito);
    }

    public function test_sin_remito_el_accesor_devuelve_null(): void
    {
        $this->assertNull($this->despacho(['remito_numero' => null])->remito);
    }

    /**
     * `PRECE_*` es "el documento precedente", que en el 39% de los movimientos
     * es de stock y no un remito: rotularlo como remito sería mentir.
     */
    public function test_un_documento_de_stock_no_se_expone_como_remito(): void
    {
        $this->assertNull($this->despacho(['remito_tipo' => 'STO', 'remito_numero' => 9366])->remito);
    }

    // ---------- atribución a los renglones de venta ----------

    public function test_cuelga_el_lote_del_renglon_de_venta(): void
    {
        $this->despacho();
        $ventas = collect([$this->venta()]);

        VentaPartida::adjuntarAVentas($ventas);

        $lotes = $ventas[0]->getRelation('lotes');
        $this->assertCount(1, $lotes);
        $this->assertSame('160224', $lotes[0]->codigo_partida);
    }

    /** El 11% de los renglones no tiene lote: vacío es normal, no un error. */
    public function test_renglon_sin_lote_queda_con_la_relacion_vacia(): void
    {
        $ventas = collect([$this->venta(['articulo' => 'SERVICIO'])]);

        VentaPartida::adjuntarAVentas($ventas);

        $this->assertTrue($ventas[0]->relationLoaded('lotes'));
        $this->assertCount(0, $ventas[0]->getRelation('lotes'));
    }

    /** 5.105 renglones salen partidos en dos lotes. */
    public function test_un_renglon_partido_devuelve_los_dos_lotes_con_su_cantidad(): void
    {
        $this->despacho(['codigo_partida' => 'LOTE-A', 'cantidad' => 3]);
        $this->despacho(['codigo_partida' => 'LOTE-B', 'cantidad' => 2]);
        $ventas = collect([$this->venta(['cantidad' => 5])]);

        VentaPartida::adjuntarAVentas($ventas);

        $lotes = $ventas[0]->getRelation('lotes');
        $this->assertCount(2, $lotes);
        $this->assertEqualsCanonicalizing(['LOTE-A', 'LOTE-B'], $lotes->pluck('codigo_partida')->all());
        $this->assertSame('3.0000', $lotes->firstWhere('codigo_partida', 'LOTE-A')->cantidad);
    }

    /** El ERP mezcla mayúsculas y minúsculas en los códigos de artículo. */
    public function test_el_articulo_matchea_sin_distinguir_mayusculas(): void
    {
        $this->despacho(['codigo_articulo' => 'RE-4176']);
        $ventas = collect([$this->venta(['articulo' => 're-4176'])]);

        VentaPartida::adjuntarAVentas($ventas);

        $this->assertCount(1, $ventas[0]->getRelation('lotes'));
    }

    /** Un lote de otro comprobante no se le puede colgar a esta venta. */
    public function test_no_cuelga_lotes_de_otro_comprobante(): void
    {
        $this->despacho(['compro_nro' => 'FEA-00099999', 'numero' => 99999]);
        $ventas = collect([$this->venta()]);

        VentaPartida::adjuntarAVentas($ventas);

        $this->assertCount(0, $ventas[0]->getRelation('lotes'));
    }

    public function test_resuelve_todos_los_renglones_en_una_sola_query(): void
    {
        foreach (['A', 'B', 'C'] as $i => $art) {
            $this->despacho(['codigo_articulo' => $art, 'codigo_partida' => "L{$i}"]);
        }
        $ventas = collect(array_map(fn ($art) => $this->venta(['articulo' => $art]), ['A', 'B', 'C']));

        DB::enableQueryLog();
        VentaPartida::adjuntarAVentas($ventas);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    // ---------- listado de ventas ----------

    public function test_el_listado_de_ventas_expone_el_lote(): void
    {
        $this->despacho();
        $this->venta();

        $this->actingAs($this->userWith('ventas.view'))
            ->get('/ventas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ventas.data.0.lotes.0.codigo_partida', '160224')
                ->where('ventas.data.0.lotes.0.remito', 'VR8-8500')
            );
    }

    public function test_filtra_ventas_por_lote_sin_distinguir_mayusculas(): void
    {
        $this->despacho(['codigo_partida' => 'ATDEC24090046']);
        $this->venta();
        // Otra venta, de otro comprobante y sin ese lote.
        $this->venta(['compro_nro' => 'FEA-00040000', 'articulo' => 'OTRO']);

        $this->actingAs($this->userWith('ventas.view'))
            ->get('/ventas?lote=atdec24')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ventas.total', 1)
                ->where('ventas.data.0.compro_nro', 'FEA-00036326')
            );
    }

    // ---------- ficha de la partida ----------

    public function test_la_ficha_de_partida_requiere_permiso(): void
    {
        $partida = $this->partida();

        $this->actingAs(User::factory()->create())
            ->get("/partidas/{$partida->id}")
            ->assertStatus(403);
    }

    public function test_la_ficha_lista_los_despachos_con_su_cliente(): void
    {
        Proveedor::create(['numero' => '229', 'razon_social' => 'LABORATORIOS GAUDIUM S.R.L']);
        $partida = $this->partida();
        $this->despacho();
        $this->venta();

        $this->actingAs($this->userWith('partidas.view'))
            ->get("/partidas/{$partida->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Partidas/Show')
                ->where('totalDespachos', 1)
                ->where('despachos.data.0.razon_social', 'SANATORIO DE PRUEBA')
                ->where('despachos.data.0.compro_nro', 'FEA-00036326')
            );
    }

    /**
     * `ventas` solo cubre desde 2024-08 y el kardex llega a 2016: un despacho
     * viejo no tiene venta local, y aun así tiene que listarse.
     */
    public function test_un_despacho_sin_venta_local_no_rompe_la_ficha(): void
    {
        $partida = $this->partida();
        $this->despacho(['compro_nro' => 'FEA-00001111', 'numero' => 1111, 'fecha' => '2018-05-10']);

        $this->actingAs($this->userWith('partidas.view'))
            ->get("/partidas/{$partida->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totalDespachos', 1)
                ->where('despachos.data.0.razon_social', null)
                ->where('despachos.data.0.compro_nro', 'FEA-00001111')
            );
    }

    /**
     * El lote solo es único dentro de un artículo: 885 códigos se repiten entre
     * artículos distintos, así que la ficha no puede traer los del otro.
     */
    public function test_la_ficha_no_mezcla_el_mismo_lote_de_otro_articulo(): void
    {
        $partida = $this->partida();
        $this->despacho();
        // Mismo código de lote, artículo distinto.
        $this->despacho(['compro_nro' => 'FEA-00040000', 'numero' => 40000, 'codigo_articulo' => 'OTRO-ART']);

        $this->actingAs($this->userWith('partidas.view'))
            ->get("/partidas/{$partida->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totalDespachos', 1));
    }

    public function test_la_ficha_suma_las_unidades_despachadas(): void
    {
        $partida = $this->partida();
        $this->despacho(['cantidad' => 3]);
        $this->despacho(['compro_nro' => 'FEA-00040000', 'numero' => 40000, 'cantidad' => 7]);

        $this->actingAs($this->userWith('partidas.view'))
            ->get("/partidas/{$partida->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unidadesDespachadas', fn ($v) => (float) $v === 10.0));
    }
}
