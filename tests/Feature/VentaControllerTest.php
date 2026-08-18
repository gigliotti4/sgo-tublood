<?php

namespace Tests\Feature;

use App\Jobs\SyncVentasJob;
use App\Models\User;
use App\Models\Venta;
use App\Services\RpSistemas\VentaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VentaControllerTest extends TestCase
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

    private function venta(array $attrs = []): Venta
    {
        return Venta::create(array_merge([
            'compro_nro' => 'A-0001',
            'fecha' => '2026-08-01',
            'anio' => 2026,
            'cliente' => 933,
            'razon_social' => 'ANTONIO LUQUIN S A C I F E I',
            'articulo' => 'RE-1631',
            'descrip_arti' => 'AGUJA 40/12 TERUMO',
            'cantidad' => 10,
            'sub_total' => 1500.50,
            'remito_nro' => 4321,
            'vendedor' => 'JUAN PEREZ',
        ], $attrs));
    }

    public function test_el_listado_requiere_permiso_ventas_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/ventas')
            ->assertStatus(403);
    }

    public function test_el_listado_muestra_las_ventas(): void
    {
        $this->venta();
        $user = $this->userWith('ventas.view');

        $this->actingAs($user)
            ->get('/ventas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Ventas/Index')
                ->has('ventas.data', 1)
                ->where('ventas.data.0.compro_nro', 'A-0001'));
    }

    public function test_el_buscador_filtra_por_remito_y_por_articulo(): void
    {
        $this->venta();
        $this->venta(['compro_nro' => 'A-0002', 'remito_nro' => 9999, 'articulo' => 'GASA-1', 'descrip_arti' => 'GASA ESTERIL']);
        $user = $this->userWith('ventas.view');

        $this->actingAs($user)
            ->get('/ventas?search=9999')
            ->assertInertia(fn ($page) => $page->has('ventas.data', 1)
                ->where('ventas.data.0.compro_nro', 'A-0002'));

        $this->actingAs($user)
            ->get('/ventas?search=AGUJA')
            ->assertInertia(fn ($page) => $page->has('ventas.data', 1)
                ->where('ventas.data.0.compro_nro', 'A-0001'));
    }

    public function test_filtra_por_rango_de_fechas_y_vendedor(): void
    {
        $this->venta();
        $this->venta(['compro_nro' => 'A-0002', 'fecha' => '2026-01-15', 'vendedor' => 'MARIA GOMEZ']);
        $user = $this->userWith('ventas.view');

        $this->actingAs($user)
            ->get('/ventas?desde=2026-07-01')
            ->assertInertia(fn ($page) => $page->has('ventas.data', 1)
                ->where('ventas.data.0.compro_nro', 'A-0001'));

        $this->actingAs($user)
            ->get('/ventas?vendedor=MARIA+GOMEZ')
            ->assertInertia(fn ($page) => $page->has('ventas.data', 1)
                ->where('ventas.data.0.compro_nro', 'A-0002'));
    }

    /** El contador del encabezado necesita el total sin filtrar. */
    public function test_manda_el_total_sin_filtrar_y_los_vendedores(): void
    {
        $this->venta();
        $this->venta(['compro_nro' => 'A-0002', 'vendedor' => 'MARIA GOMEZ']);
        $user = $this->userWith('ventas.view');

        $this->actingAs($user)
            ->get('/ventas?search=AGUJA')
            ->assertInertia(fn ($page) => $page
                ->where('total', 2)
                ->where('ventas.total', 2)
                ->has('vendedores', 2));
    }

    // ── Buscador combinado ───────────────────────────────────────────────────

    /** Tres ventas que se distinguen por el cruce cliente × artículo. */
    private function sembrarParaCruce(): void
    {
        $this->venta(['compro_nro' => 'V-BOSO-AGUJA', 'razon_social' => 'BOSO SRL', 'descrip_arti' => 'AGUJA 40/12 TERUMO']);
        $this->venta(['compro_nro' => 'V-BOSO-GASA', 'razon_social' => 'BOSO SRL', 'descrip_arti' => 'GASA ESTERIL']);
        $this->venta(['compro_nro' => 'V-OTRO-AGUJA', 'razon_social' => 'GILPAD S.A.', 'descrip_arti' => 'AGUJA 13/3 CORONET']);
    }

    private function buscar(User $user, string $q, array $extra = []): array
    {
        $response = $this->actingAs($user)->get('/ventas?'.http_build_query(['search' => $q] + $extra));

        return $response->viewData('page')['props']['ventas']['data'];
    }

    public function test_combina_cliente_y_articulo_con_guion(): void
    {
        $this->sembrarParaCruce();
        $user = $this->userWith('ventas.view');

        $data = $this->buscar($user, 'BOSO-AGUJA');

        $this->assertCount(1, $data);
        $this->assertSame('V-BOSO-AGUJA', $data[0]['compro_nro']);
    }

    public function test_el_orden_de_los_terminos_no_importa(): void
    {
        $this->sembrarParaCruce();
        $user = $this->userWith('ventas.view');

        $this->assertCount(1, $this->buscar($user, 'AGUJA-BOSO'));
    }

    public function test_el_espacio_tambien_combina(): void
    {
        $this->sembrarParaCruce();
        $user = $this->userWith('ventas.view');

        $this->assertCount(1, $this->buscar($user, 'BOSO AGUJA'));
    }

    /** Es un AND: si un término no matchea nada, no hay resultado. */
    public function test_un_termino_sin_match_vacia_el_resultado(): void
    {
        $this->sembrarParaCruce();
        $user = $this->userWith('ventas.view');

        $this->assertCount(0, $this->buscar($user, 'BOSO-INEXISTENTE'));
    }

    /**
     * La regresión que más importa: el 77% de los artículos tiene guion. Si el
     * buscador partiera siempre, un código que existe tal cual traería además
     * todo lo que matchea sus fragmentos sueltos.
     */
    public function test_un_codigo_con_guion_que_existe_no_se_parte(): void
    {
        $this->venta(['compro_nro' => 'V-1', 'articulo' => '621280-M', 'descrip_arti' => 'TUBO PP']);
        // Matchea "621280" y "M" por separado, pero no "621280-M" entero.
        $this->venta(['compro_nro' => 'V-2', 'articulo' => '621280-X', 'descrip_arti' => 'MASCARA']);
        $user = $this->userWith('ventas.view');

        $data = $this->buscar($user, '621280-M');

        $this->assertCount(1, $data);
        $this->assertSame('V-1', $data[0]['compro_nro']);
    }

    /**
     * Si la sonda del paso 1 no clonara el builder ya filtrado, decidiría sobre
     * la tabla entera y el cruce no aparecería nunca con un filtro puesto.
     */
    public function test_la_combinacion_respeta_los_filtros_de_fecha(): void
    {
        $this->venta(['compro_nro' => 'V-VIEJA', 'fecha' => '2024-01-10', 'razon_social' => 'BOSO SRL', 'descrip_arti' => 'AGUJA 40/12']);
        $this->venta(['compro_nro' => 'V-NUEVA', 'fecha' => '2026-08-01', 'razon_social' => 'BOSO SRL', 'descrip_arti' => 'AGUJA 40/12']);
        $user = $this->userWith('ventas.view');

        $data = $this->buscar($user, 'BOSO-AGUJA', ['desde' => '2026-01-01']);

        $this->assertCount(1, $data);
        $this->assertSame('V-NUEVA', $data[0]['compro_nro']);
    }

    public function test_sync_requiere_permiso_ventas_sync(): void
    {
        $user = $this->userWith('ventas.view');
        $this->actingAs($user)->post('/ventas/sync')->assertStatus(403);
    }

    public function test_sync_despacha_el_job(): void
    {
        Queue::fake();
        $user = $this->userWith('ventas.view', 'ventas.sync');

        $this->actingAs($user)->post('/ventas/sync')->assertRedirect('/ventas');

        Queue::assertPushed(SyncVentasJob::class);
    }

    // ── Mapeo de la vista ────────────────────────────────────────────────────

    /** Ojo con las mayúsculas: la vista mezcla `CLIENTE` con `fecha`. */
    public function test_mapea_las_columnas_de_la_vista(): void
    {
        $fila = (new VentaSyncService)->mapear([
            'compro_nro' => 'A-0001',
            'fecha' => '2026-08-01 00:00:00',
            'anio' => 2026,
            'CLIENTE' => 933,
            'razon_social' => 'ANTONIO LUQUIN S A C I F E I',
            'nom_fantasia' => 'LUQUIN',
            'articulo' => 'RE-1631',
            'descrip_arti' => 'AGUJA 40/12 TERUMO',
            'cantidad' => '10.0000',
            'sub_total' => '1500.5000',
            'remito_nro' => 4321,
            'vendedor' => 'JUAN PEREZ',
            'deposito' => '  ',
        ], Carbon::now());

        $this->assertSame('2026-08-01', $fila['fecha']);
        $this->assertSame(933, $fila['cliente']);
        $this->assertSame('LUQUIN', $fila['nombre_fantasia']);
        $this->assertSame(10.0, $fila['cantidad']);
        $this->assertSame(4321, $fila['remito_nro']);
        $this->assertNull($fila['deposito']);
    }

    /** El ERP usa 0 para "sin remito" y así se guarda: no es lo mismo que null. */
    public function test_el_remito_en_cero_se_conserva(): void
    {
        $fila = (new VentaSyncService)->mapear(['remito_nro' => 0], Carbon::now());

        $this->assertSame(0, $fila['remito_nro']);
    }
}
