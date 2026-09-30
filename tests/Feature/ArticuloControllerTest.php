<?php

namespace Tests\Feature;

use App\Jobs\SyncArticulosJob;
use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ArticuloControllerTest extends TestCase
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

    public function test_index_requiere_permiso_articulos_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/articulos')->assertStatus(403);
    }

    public function test_index_accesible_con_permiso(): void
    {
        $user = $this->userWith('articulos.view');
        $this->actingAs($user)->get('/articulos')->assertStatus(200);
    }

    public function test_busqueda_filtra_por_descripcion(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA ESTERIL']);

        $user = $this->userWith('articulos.view');
        $response = $this->actingAs($user)->get('/articulos?search=AGUJA');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Admin/Articulos/Index')
            ->has('articulos.data', 1)
            ->where('articulos.data.0.codigo', 'RE-1631')
        );
    }

    /** El contador del encabezado necesita el total sin filtrar, no el del paginador. */
    public function test_el_listado_manda_el_total_sin_filtrar(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA ESTERIL']);

        $user = $this->userWith('articulos.view');

        $this->actingAs($user)
            ->get('/articulos?search=AGUJA')
            ->assertInertia(fn ($page) => $page
                ->where('total', 2)
                ->where('articulos.total', 1));
    }

    public function test_sync_requiere_permiso_articulos_sync(): void
    {
        $user = $this->userWith('articulos.view');
        $this->actingAs($user)->post('/articulos/sync')->assertStatus(403);
    }

    public function test_sync_despacha_job_y_redirige(): void
    {
        Queue::fake();

        $user = $this->userWith('articulos.view', 'articulos.sync');
        $this->actingAs($user)
            ->post('/articulos/sync')
            ->assertRedirect('/articulos');

        Queue::assertPushed(SyncArticulosJob::class);
    }

    // ── Filtro por el tipo que le pone ANMAT (ID_ARTI_TIPO) ─────────────────

    public function test_filtra_por_el_tipo_de_anmat(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA', 'tipo_anmat' => 'PM']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA', 'tipo_anmat' => 'PMV']);
        $user = $this->userWith('articulos.view');

        $this->actingAs($user)
            ->get('/articulos?tipo_anmat=PM')
            ->assertInertia(fn ($page) => $page
                ->has('articulos.data', 1)
                ->where('articulos.data.0.codigo', 'RE-1631'));
    }

    /**
     * ⚠️ "Sin tipo" necesita un centinela propio: con la cadena vacía sería
     * indistinguible de "sin filtro" y devolvería el catálogo entero. En
     * producción son 415 de 738, así que el filtro se usa.
     */
    public function test_el_centinela_sin_tipo_trae_los_que_el_erp_no_clasifico(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA', 'tipo_anmat' => 'PM']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA', 'tipo_anmat' => null]);
        $user = $this->userWith('articulos.view');

        $this->actingAs($user)
            ->get('/articulos?tipo_anmat=sin_tipo')
            ->assertInertia(fn ($page) => $page
                ->has('articulos.data', 1)
                ->where('articulos.data.0.codigo', 'RE-999'));
    }

    /** El catálogo es de RP: un código nuevo tiene que poder filtrarse igual. */
    public function test_un_tipo_fuera_del_catalogo_igual_filtra(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA', 'tipo_anmat' => 'XX']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA', 'tipo_anmat' => 'PM']);
        $user = $this->userWith('articulos.view');

        $this->actingAs($user)
            ->get('/articulos?tipo_anmat=XX')
            ->assertInertia(fn ($page) => $page
                ->has('articulos.data', 1)
                ->where('articulos.data.0.codigo', 'RE-1631'));
    }

    public function test_el_listado_manda_el_catalogo_de_tipos(): void
    {
        $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos')
            ->assertInertia(fn ($page) => $page->where('tiposAnmat.PM', 'Producto Medico'));
    }

    /** El Excel baja lo mismo que se ve: mismo criterio que el resto de filtros. */
    public function test_exportar_respeta_el_filtro_de_tipo(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA', 'tipo_anmat' => 'PM']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA', 'tipo_anmat' => 'PMV']);

        $response = $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos/export?tipo_anmat=PM');

        $response->assertOk();
        $filas = $this->filasDelExcel($response);

        $this->assertCount(2, $filas, 'Encabezado + un solo artículo.');
        $this->assertSame('RE-1631', $filas[1][0]);
    }

    public function test_edit_requiere_permiso_articulos_edit(): void
    {
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view');

        $this->actingAs($user)->get("/articulos/{$articulo->id}/edit")->assertStatus(403);
    }

    public function test_update_setea_los_campos_propios(): void
    {
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view', 'articulos.edit');

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", [
                'fecha_vencimiento' => '2030-10-06',
                'legajo' => '133',
                'observaciones' => 'Revisar con Calidad',
            ])
            ->assertRedirect(route('articulos.index'));

        $articulo->refresh();
        $this->assertSame('2030-10-06', $articulo->fecha_vencimiento->toDateString());
        $this->assertSame('133', $articulo->legajo);
        $this->assertSame('Revisar con Calidad', $articulo->observaciones);
    }

    /**
     * ⚠️ `pm` dejó de ser editable el 30/9/2026: lo escribe la sincronización
     * desde `ARTICULOS.NRO_REGISTRO`. Si volviera a aceptarse por acá, una
     * edición a mano se perdería en la corrida de las 03:00 sin avisarle a
     * nadie — por eso el request lo manda y el test verifica que se ignore.
     */
    public function test_update_ignora_el_pm_porque_ahora_lo_escribe_el_erp(): void
    {
        $articulo = Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'AGUJA 40/12 TERUMO',
            'pm' => 'PM 2243-98',
        ]);
        $user = $this->userWith('articulos.view', 'articulos.edit');

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", [
                'pm' => 'INTENTO DE PISARLO',
                'legajo' => '133',
            ])
            ->assertRedirect(route('articulos.index'));

        $this->assertSame('PM 2243-98', $articulo->fresh()->pm);
    }

    public function test_update_asigna_y_limpia_el_proveedor(): void
    {
        $proveedor = Proveedor::create(['numero' => '1500', 'razon_social' => 'PROPATO HNOS S A I C']);
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view', 'articulos.edit');

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => $proveedor->id])
            ->assertRedirect(route('articulos.index'));

        $this->assertSame($proveedor->id, $articulo->fresh()->proveedor_id);

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => null])
            ->assertRedirect(route('articulos.index'));

        $this->assertNull($articulo->fresh()->proveedor_id);
    }

    public function test_update_rechaza_un_proveedor_inexistente(): void
    {
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view', 'articulos.edit');

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => 99999])
            ->assertSessionHasErrors('proveedor_id');
    }

    public function test_el_listado_trae_la_razon_social_del_proveedor(): void
    {
        $proveedor = Proveedor::create(['numero' => '1500', 'razon_social' => 'PROPATO HNOS S A I C']);
        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'AGUJA 40/12 TERUMO',
            'proveedor_id' => $proveedor->id,
        ]);
        $user = $this->userWith('articulos.view');

        $this->actingAs($user)
            ->get('/articulos')
            ->assertInertia(fn ($page) => $page
                ->where('articulos.data.0.proveedor.razon_social', 'PROPATO HNOS S A I C'));
    }

    // ── Exportación a Excel ──────────────────────────────────────────────────

    public function test_exportar_requiere_permiso_articulos_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/articulos/export')
            ->assertStatus(403);
    }

    public function test_exportar_devuelve_un_xlsx(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view');

        $response = $this->actingAs($user)->get('/articulos/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'articulos-'.now()->format('Y-m-d').'.xlsx',
            $response->headers->get('content-disposition')
        );
    }

    /** Lo que ves es lo que baja: el archivo respeta el buscador y el filtro de estado. */
    public function test_exportar_respeta_los_filtros_del_listado(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA ESTERIL']);
        $user = $this->userWith('articulos.view');

        $filas = $this->filasDelExcel(
            $this->actingAs($user)->get('/articulos/export?search=AGUJA')
        );

        // Encabezado + una sola fila de datos.
        $this->assertCount(2, $filas);
        $this->assertSame('RE-1631', $filas[1][0]);
    }

    /**
     * Las columnas Estado y Origen son el motivo del export: separan "activo
     * según el último sync" de "nunca vino de RP" (Excel de Calidad), que es
     * la distinción que no se ve mirando solo el listado en pantalla.
     */
    public function test_exportar_incluye_estado_y_origen(): void
    {
        Articulo::create([
            'codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO',
            'activo' => true, 'synced_at' => now(),
        ]);
        Articulo::create([
            'codigo' => 'RE-999', 'descripcion' => 'GASA ESTERIL',
            'activo' => false, 'synced_at' => now()->subDay(),
        ]);
        Articulo::create([
            'codigo' => 'EXCEL-1', 'descripcion' => 'Cargado a mano por Calidad',
            'activo' => true, 'synced_at' => null,
        ]);
        $user = $this->userWith('articulos.view');

        $filas = $this->filasDelExcel($this->actingAs($user)->get('/articulos/export'));
        $porCodigo = collect($filas)->skip(1)->keyBy(0);

        // Los índices corrieron uno el 30/9/2026: se sumó "Tipo ANMAT" después
        // de "PM". Se buscan por nombre de encabezado para que el próximo
        // agregado no vuelva a romper este test por una razón que no importa.
        $estado = array_search('Estado', $filas[0], true);
        $origen = array_search('Origen', $filas[0], true);

        $this->assertSame('Activo', $porCodigo['RE-1631'][$estado]);
        $this->assertSame('RP Sistemas', $porCodigo['RE-1631'][$origen]);

        $this->assertSame('Discontinuado', $porCodigo['RE-999'][$estado]);
        $this->assertSame('RP Sistemas', $porCodigo['RE-999'][$origen]);

        $this->assertSame('Activo', $porCodigo['EXCEL-1'][$estado]);
        $this->assertSame('Carga manual (Excel)', $porCodigo['EXCEL-1'][$origen]);
    }

    private function filasDelExcel($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }
}
