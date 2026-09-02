<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Observacion;
use App\Models\ObservationProduct;
use App\Models\Partida;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\RpSistemas\PartidaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Padrón de partidas (lotes) y su atribución al lote de un reclamo.
 *
 * El sync se prueba por `mapear()`, sin tocar SQL Server: la conexión `erp` es
 * de un sistema de terceros y no entra en los tests (ver `config/database.php`).
 */
class PartidaTest extends TestCase
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
    private function partida(array $attrs = []): Partida
    {
        return Partida::create(array_merge([
            'codigo_articulo' => 'RE-4176',
            'codigo_partida' => '101224',
            'fecha_vencimiento' => '2027-11-30',
            'proveedor_numero' => '229',
            'ubicacion' => 'R11U1M5',
            'ultimo_movimiento_at' => '2026-08-28',
            'synced_at' => now(),
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    private function producto(array $attrs = []): ObservationProduct
    {
        return new ObservationProduct(array_merge([
            'producto' => 'Recolector de orina',
            'codigo' => 'RE-4176',
            'lote' => '101224',
        ], $attrs));
    }

    private function observacion(): Observacion
    {
        return Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);
    }

    // ---------- mapeo del sync ----------

    public function test_mapear_desempaqueta_los_valores_del_movimiento_mas_reciente(): void
    {
        $mapeado = (new PartidaSyncService)->mapear([
            'COD_ARTICULO' => 'RE-4176',
            'COD_PARTIDA' => '101224',
            'ultimo_movimiento' => '2026-08-28 00:00:00.000',
            // 8 caracteres con la fecha del movimiento + el valor.
            'venci_pack' => '2026082820271130',
            'prov_pack' => '202608280000000229',
            'ubi_pack' => '20260828R11U1M5',
        ], Carbon::parse('2026-09-01 10:00:00'));

        $this->assertSame('RE-4176', $mapeado['codigo_articulo']);
        $this->assertSame('101224', $mapeado['codigo_partida']);
        $this->assertSame('2027-11-30', $mapeado['fecha_vencimiento']);
        $this->assertSame('229', $mapeado['proveedor_numero']);
        $this->assertSame('R11U1M5', $mapeado['ubicacion']);
        $this->assertSame('2026-08-28', $mapeado['ultimo_movimiento_at']);
    }

    public function test_mapear_tolera_columnas_sin_dato(): void
    {
        $mapeado = (new PartidaSyncService)->mapear([
            'COD_ARTICULO' => 'RE-1',
            'COD_PARTIDA' => 'L1',
            'ultimo_movimiento' => null,
            'venci_pack' => null,
            'prov_pack' => null,
            'ubi_pack' => null,
        ], Carbon::now());

        $this->assertNull($mapeado['fecha_vencimiento']);
        $this->assertNull($mapeado['proveedor_numero']);
        $this->assertNull($mapeado['ubicacion']);
        $this->assertNull($mapeado['ultimo_movimiento_at']);
    }

    /** Los ceros a la izquierda son del empaquetado, no del número de proveedor. */
    public function test_mapear_trata_el_proveedor_en_cero_como_sin_proveedor(): void
    {
        $mapeado = (new PartidaSyncService)->mapear([
            'COD_ARTICULO' => 'RE-1',
            'COD_PARTIDA' => 'L1',
            'prov_pack' => '202608280000000000',
        ], Carbon::now());

        $this->assertNull($mapeado['proveedor_numero']);
    }

    // ---------- relaciones con los padrones locales ----------

    public function test_resuelve_articulo_y_proveedor_del_padron(): void
    {
        $proveedor = Proveedor::create(['numero' => '229', 'razon_social' => 'LABORATORIOS GAUDIUM S.R.L']);
        Articulo::create(['codigo' => 'RE-4176', 'descripcion' => 'RECOLECTOR DE ORINA ESTERIL 125ML.']);

        $partida = $this->partida()->fresh(['articulo', 'proveedor']);

        $this->assertSame($proveedor->id, $partida->proveedor->id);
        $this->assertSame('RECOLECTOR DE ORINA ESTERIL 125ML.', $partida->articulo->descripcion);
    }

    /** El ERP puede nombrar un artículo que todavía no sincronizamos: no debe romper. */
    public function test_partida_de_articulo_no_sincronizado_no_rompe(): void
    {
        $partida = $this->partida(['codigo_articulo' => 'NO-SINCRONIZADO'])->fresh(['articulo']);

        $this->assertNull($partida->articulo);
    }

    // ---------- atribución al lote de un reclamo ----------

    public function test_atribuye_la_partida_al_lote_declarado(): void
    {
        $this->partida();
        $productos = collect([$this->producto()]);

        Partida::adjuntarAProductos($productos);

        $this->assertSame('101224', $productos[0]->getRelation('partida')->codigo_partida);
    }

    /** El lote llega tipeado a mano desde el portal: sobran minúsculas y espacios. */
    public function test_normaliza_el_lote_tipeado_por_el_cliente(): void
    {
        $this->partida(['codigo_partida' => 'ATDEC24090046']);
        $productos = collect([$this->producto(['lote' => '  atdec24090046 '])]);

        Partida::adjuntarAProductos($productos);

        $this->assertNotNull($productos[0]->getRelation('partida'));
    }

    public function test_no_atribuye_si_el_lote_es_de_otro_articulo(): void
    {
        $this->partida();
        $productos = collect([$this->producto(['codigo' => 'OTRO-ARTICULO'])]);

        Partida::adjuntarAProductos($productos);

        $this->assertNull($productos[0]->getRelation('partida'));
    }

    /**
     * 885 códigos de lote se repiten entre artículos: sin un código que
     * desempate, atribuir uno mandaría a revisar el artículo equivocado.
     */
    public function test_no_atribuye_un_lote_ambiguo_sin_codigo_de_articulo(): void
    {
        $this->partida(['codigo_articulo' => 'RE-1']);
        $this->partida(['codigo_articulo' => 'RE-2']);

        $productos = collect([$this->producto(['codigo' => null])]);
        Partida::adjuntarAProductos($productos);

        $this->assertNull($productos[0]->getRelation('partida'));
    }

    public function test_atribuye_un_lote_inequivoco_sin_codigo_de_articulo(): void
    {
        $this->partida();
        $productos = collect([$this->producto(['codigo' => null])]);

        Partida::adjuntarAProductos($productos);

        $this->assertNotNull($productos[0]->getRelation('partida'));
    }

    public function test_producto_sin_lote_queda_sin_partida(): void
    {
        $this->partida();
        $productos = collect([$this->producto(['lote' => null])]);

        Partida::adjuntarAProductos($productos);

        $this->assertNull($productos[0]->getRelation('partida'));
    }

    /** Una sola query para todos los productos, no una por renglón. */
    public function test_resuelve_todos_los_productos_en_una_sola_query(): void
    {
        $this->partida(['codigo_partida' => 'L1']);
        $this->partida(['codigo_partida' => 'L2']);
        $this->partida(['codigo_partida' => 'L3']);

        $productos = collect([
            $this->producto(['lote' => 'L1']),
            $this->producto(['lote' => 'L2']),
            $this->producto(['lote' => 'L3']),
        ]);

        DB::enableQueryLog();
        Partida::adjuntarAProductos($productos);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 1 por las partidas + los dos eager loads (proveedor y artículo).
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertNotNull($productos[2]->getRelation('partida'));
    }

    // ---------- integración con el detalle ----------

    public function test_el_detalle_expone_la_partida_de_cada_producto(): void
    {
        Proveedor::create(['numero' => '229', 'razon_social' => 'LABORATORIOS GAUDIUM S.R.L']);
        $this->partida();

        $observacion = $this->observacion();
        $observacion->productos()->create([
            'producto' => 'Recolector de orina',
            'codigo' => 'RE-4176',
            'cantidad_afectada' => 1,
            'lote' => '101224',
            'fecha_vencimiento' => '2027-11-30',
        ]);

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('observacion.productos.0.partida.codigo_partida', '101224')
                ->where('observacion.productos.0.partida.proveedor.razon_social', 'LABORATORIOS GAUDIUM S.R.L')
            );
    }

    // ---------- listado ----------

    public function test_el_listado_requiere_permiso_partidas_view(): void
    {
        $this->actingAs(User::factory()->create())->get('/partidas')->assertStatus(403);
    }

    public function test_el_listado_muestra_las_partidas(): void
    {
        Proveedor::create(['numero' => '229', 'razon_social' => 'LABORATORIOS GAUDIUM S.R.L']);
        Articulo::create(['codigo' => 'RE-4176', 'descripcion' => 'RECOLECTOR DE ORINA']);
        $this->partida();

        $this->actingAs($this->userWith('partidas.view'))
            ->get('/partidas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Partidas/Index')
                ->where('total', 1)
                ->where('partidas.data.0.codigo_partida', '101224')
                ->where('partidas.data.0.proveedor.razon_social', 'LABORATORIOS GAUDIUM S.R.L')
                ->where('partidas.data.0.articulo.descripcion', 'RECOLECTOR DE ORINA')
            );
    }

    /** El ERP nombra códigos que el feed de artículos no trae: la fila igual se lista. */
    public function test_el_listado_incluye_partidas_de_articulos_fuera_del_catalogo(): void
    {
        $this->partida(['codigo_articulo' => 'NO-ESTA-EN-EL-CATALOGO']);

        $this->actingAs($this->userWith('partidas.view'))
            ->get('/partidas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('partidas.total', 1)
                ->where('partidas.data.0.articulo', null)
            );
    }

    public function test_busca_el_lote_sin_distinguir_mayusculas(): void
    {
        $this->partida(['codigo_partida' => 'ATDEC24090046']);
        $this->partida(['codigo_partida' => 'OTRO-LOTE']);

        $this->actingAs($this->userWith('partidas.view'))
            ->get('/partidas?search=atdec24')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('partidas.total', 1)
                ->where('partidas.data.0.codigo_partida', 'ATDEC24090046')
            );
    }

    public function test_busca_por_razon_social_del_proveedor(): void
    {
        Proveedor::create(['numero' => '229', 'razon_social' => 'LABORATORIOS GAUDIUM S.R.L']);
        $this->partida();
        $this->partida(['codigo_partida' => 'OTRA', 'proveedor_numero' => null]);

        $this->actingAs($this->userWith('partidas.view'))
            ->get('/partidas?search=gaudium')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('partidas.total', 1));
    }

    public function test_filtra_por_estado_de_vencimiento(): void
    {
        $this->partida(['codigo_partida' => 'VENCIDA', 'fecha_vencimiento' => now()->subMonth()]);
        $this->partida(['codigo_partida' => 'PORVENCER', 'fecha_vencimiento' => now()->addDays(30)]);
        $this->partida(['codigo_partida' => 'VIGENTE', 'fecha_vencimiento' => now()->addYears(2)]);
        $this->partida(['codigo_partida' => 'SINFECHA', 'fecha_vencimiento' => null]);

        $user = $this->userWith('partidas.view');

        foreach ([
            'vencidas' => 'VENCIDA',
            'por_vencer' => 'PORVENCER',
            'vigentes' => 'VIGENTE',
            'sin_vencimiento' => 'SINFECHA',
        ] as $filtro => $esperado) {
            $this->actingAs($user)
                ->get("/partidas?vencimiento={$filtro}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('partidas.total', 1)
                    ->where('partidas.data.0.codigo_partida', $esperado)
                );
        }
    }

    public function test_filtra_por_proveedor(): void
    {
        Proveedor::create(['numero' => '229', 'razon_social' => 'GAUDIUM']);
        Proveedor::create(['numero' => '51', 'razon_social' => 'OTRO PROVEEDOR']);
        $this->partida();
        $this->partida(['codigo_partida' => 'OTRA', 'proveedor_numero' => '51']);

        $this->actingAs($this->userWith('partidas.view'))
            ->get('/partidas?proveedor=51')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('partidas.total', 1)
                ->where('partidas.data.0.codigo_partida', 'OTRA')
            );
    }

    /** El select solo ofrece los proveedores que tienen partidas, no el padrón entero. */
    public function test_el_select_de_proveedores_solo_trae_los_que_tienen_partidas(): void
    {
        Proveedor::create(['numero' => '229', 'razon_social' => 'CON PARTIDAS']);
        Proveedor::create(['numero' => '999', 'razon_social' => 'SIN PARTIDAS']);
        $this->partida();

        $this->actingAs($this->userWith('partidas.view'))
            ->get('/partidas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('proveedores', 1)
                ->where('proveedores.0.razon_social', 'CON PARTIDAS')
            );
    }

    public function test_sync_requiere_permiso_propio(): void
    {
        $this->actingAs($this->userWith('partidas.view'))
            ->post('/partidas/sync')
            ->assertStatus(403);
    }

    /** Un lote que no está en el padrón no puede romper el detalle. */
    public function test_el_detalle_funciona_con_un_lote_que_no_esta_en_el_padron(): void
    {
        $observacion = $this->observacion();
        $observacion->productos()->create([
            'producto' => 'Recolector de orina',
            'codigo' => 'RE-4176',
            'cantidad_afectada' => 1,
            'lote' => 'INEXISTENTE',
            'fecha_vencimiento' => '2027-11-30',
        ]);

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('observacion.productos.0.partida', null)
            );
    }
}
