<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Persona;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Responsable del fallo" y personal por sector (puntos 10 y 10b del pedido del
 * 6/11/2026).
 *
 * Es quién, del sector que falló, cometió el error: no el responsable del caso.
 * Solo lo ven y lo filtran los jefes y Calidad, así que lo central acá es que
 * **no se filtre por ninguna puerta**: las props, el Excel, el PDF y la bitácora.
 */
class ResponsableDelFalloTest extends TestCase
{
    use RefreshDatabase;

    private Sector $deposito;

    private Sector $comercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deposito = Sector::create(['nombre' => 'Depósito', 'slug' => 'deposito', 'dias_gestion' => 3]);
        $this->comercial = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial', 'dias_gestion' => 2]);
        Sector::create(['nombre' => 'Calidad', 'slug' => 'garantia_calidad', 'dias_gestion' => 3]);
    }

    private function usuario(array $attrs = [], array $permisos = []): User
    {
        $user = User::factory()->create($attrs);

        foreach (['observaciones.view', ...$permisos] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
            $user->givePermissionTo($permiso);
        }

        return $user;
    }

    private function persona(array $attrs = []): Persona
    {
        return Persona::create(array_merge([
            'nombre' => 'Raúl Arce',
            'sector_id' => $this->deposito->id,
            'puesto' => 'Operario de depósito',
            'activo' => true,
        ], $attrs));
    }

    /** Un fallo de Depósito cargado por alguien de Comercial, con su responsable del fallo. */
    private function fallo(?User $responsable = null, ?Persona $persona = null, array $attrs = []): Observacion
    {
        return Observacion::create(array_merge([
            'numero' => '0069-26',
            'anio' => 2026,
            'tipo' => 'falla_proceso_dep',
            'estado' => 'clasificada',
            'origen' => 'interna',
            'sector_id' => $this->deposito->id,
            'contacto_nombre' => 'Cliente',
            'contacto_email' => 'c@example.com',
            'titulo' => 'Control de bultos',
            'descripcion' => 'Salió mal identificado.',
            'responsable_id' => $responsable?->id,
            'responsable_fallo_id' => $persona?->id,
        ], $attrs));
    }

    // ── Quién lo ve ─────────────────────────────────────────────────────────

    public function test_quien_ve_el_responsable_del_fallo(): void
    {
        $this->assertFalse($this->usuario()->veResponsableDelFallo());

        // Un gerente.
        $this->assertTrue($this->usuario(['es_gerente' => true])->veResponsableDelFallo());

        // Un supervisor: alguien lo tiene como supervisor.
        $jefe = $this->usuario();
        User::factory()->create(['supervisor_id' => $jefe->id]);
        $this->assertTrue($jefe->veResponsableDelFallo());

        // Calidad, por rol o por sector.
        Role::firstOrCreate(['name' => 'garantia_calidad', 'guard_name' => 'web']);
        $calidad = $this->usuario();
        $calidad->assignRole('garantia_calidad');
        $this->assertTrue($calidad->veResponsableDelFallo());

        $this->assertTrue($this->usuario(['sector_id' => Sector::where('slug', 'garantia_calidad')->first()->id])->veResponsableDelFallo());

        // Delegado a una persona puntual con el permiso.
        $this->assertTrue($this->usuario([], ['observaciones.responsable_fallo'])->veResponsableDelFallo());

        // Super-admin.
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = $this->usuario();
        $admin->assignRole('super-admin');
        $this->assertTrue($admin->veResponsableDelFallo());
    }

    /** La prueba del cliente: un usuario de Comercial no ve el responsable de un fallo de Depósito. */
    public function test_un_usuario_de_comercial_no_ve_el_responsable_de_un_fallo_de_deposito(): void
    {
        $o = $this->fallo(null, $this->persona());
        $comercial = $this->usuario(['sector_id' => $this->comercial->id]);

        $this->actingAs($comercial)
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page
                ->where('puedeVerResponsableFallo', false)
                ->where('personas', [])
                ->missing('observacion.responsable_fallo')
                ->missing('observacion.responsable_fallo_id'));
    }

    public function test_el_listado_tampoco_lo_manda_a_quien_no_lo_ve(): void
    {
        $this->fallo(null, $this->persona());

        $this->actingAs($this->usuario())
            ->get('/observaciones')
            ->assertInertia(fn ($page) => $page
                ->where('puedeVerResponsableFallo', false)
                ->where('personas', [])
                ->missing('observaciones.data.0.responsable_fallo')
                ->missing('observaciones.data.0.responsable_fallo_id'));
    }

    public function test_un_jefe_lo_ve_en_la_ficha_y_en_el_listado(): void
    {
        $persona = $this->persona();
        $o = $this->fallo(null, $persona);
        $gerente = $this->usuario(['es_gerente' => true]);

        $this->actingAs($gerente)
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page
                ->where('puedeVerResponsableFallo', true)
                ->where('observacion.responsable_fallo.nombre', 'Raúl Arce')
                ->where('observacion.responsable_fallo_id', $persona->id)
                ->has('personas', 1));

        $this->actingAs($gerente)
            ->get('/observaciones')
            ->assertInertia(fn ($page) => $page
                ->where('observaciones.data.0.responsable_fallo.nombre', 'Raúl Arce')
                ->where('observaciones.data.0.responsable_fallo_id', $persona->id));
    }

    public function test_los_inactivos_no_se_ofrecen_para_elegir(): void
    {
        $this->persona(['nombre' => 'Activa']);
        $this->persona(['nombre' => 'Se fue', 'activo' => false]);

        $this->actingAs($this->usuario(['es_gerente' => true]))
            ->get('/observaciones')
            ->assertInertia(fn ($page) => $page->has('personas', 1)->where('personas.0.nombre', 'Activa'));
    }

    // ── La bitácora ─────────────────────────────────────────────────────────

    public function test_el_cambio_queda_en_la_bitacora_pero_solo_la_ven_jefes_y_calidad(): void
    {
        $persona = $this->persona();
        $responsable = $this->usuario(['es_gerente' => true]);
        $o = $this->fallo($responsable);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}", ['estado' => 'clasificada', 'responsable_fallo_id' => $persona->id])
            ->assertSessionHasNoErrors();

        $entrada = $o->historial()->where('accion', ObservationHistory::ACCION_RESPONSABLE_FALLO)->first();
        $this->assertNotNull($entrada);
        $this->assertSame('Raúl Arce', $entrada->cambios['a']);
        $this->assertSame('Sin cargar', $entrada->cambios['de']);

        // Un jefe la ve en la ficha...
        $this->actingAs($responsable)
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page->where('observacion.historial', fn ($h) => collect($h)->contains('accion', 'responsable_fallo')));

        // ...y alguien común no: la bitácora es otra puerta al mismo dato.
        $this->actingAs($this->usuario())
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page->where('observacion.historial', fn ($h) => ! collect($h)->contains('accion', 'responsable_fallo')));
    }

    public function test_la_seccion_bitacora_tambien_la_esconde(): void
    {
        $o = $this->fallo(null, null);
        $o->historial()->create([
            'user_id' => null,
            'accion' => ObservationHistory::ACCION_RESPONSABLE_FALLO,
            'cambios' => ['de' => 'Sin cargar', 'a' => 'Raúl Arce'],
        ]);

        $sinPermiso = $this->usuario([], ['bitacora.view']);
        $this->actingAs($sinPermiso)
            ->get('/bitacora')
            ->assertInertia(fn ($page) => $page->has('entradas.data', 0));

        $jefe = $this->usuario(['es_gerente' => true], ['bitacora.view']);
        $this->actingAs($jefe)
            ->get('/bitacora')
            ->assertInertia(fn ($page) => $page->has('entradas.data', 1));
    }

    // ── Quién lo carga ──────────────────────────────────────────────────────

    public function test_quien_no_lo_ve_tampoco_puede_cargarlo(): void
    {
        $persona = $this->persona();
        $responsable = $this->usuario();
        $o = $this->fallo($responsable);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}", ['estado' => 'clasificada', 'responsable_fallo_id' => $persona->id])
            ->assertSessionHasNoErrors();

        // Se descarta en silencio: la clave ni se valida.
        $this->assertNull($o->fresh()->responsable_fallo_id);
    }

    public function test_se_puede_quitar_el_responsable_del_fallo(): void
    {
        $responsable = $this->usuario(['es_gerente' => true]);
        $o = $this->fallo($responsable, $this->persona());

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}", ['estado' => 'clasificada', 'responsable_fallo_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($o->fresh()->responsable_fallo_id);
    }

    public function test_no_se_elige_a_una_persona_inactiva_pero_si_se_conserva_la_que_ya_estaba(): void
    {
        $responsable = $this->usuario(['es_gerente' => true]);
        $inactiva = $this->persona(['nombre' => 'Se fue', 'activo' => false]);

        $o = $this->fallo($responsable);
        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}", ['estado' => 'clasificada', 'responsable_fallo_id' => $inactiva->id])
            ->assertSessionHasErrors('responsable_fallo_id');

        // Si ya la tenía, guardar otros cambios no obliga a sacarla.
        $o2 = $this->fallo($responsable, $inactiva, ['numero' => '0070-26']);
        $this->actingAs($responsable)
            ->put("/observaciones/{$o2->id}", ['estado' => 'en_proceso', 'responsable_fallo_id' => $inactiva->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($inactiva->id, $o2->fresh()->responsable_fallo_id);
    }

    public function test_el_alta_interna_lo_guarda_para_quien_lo_ve_y_lo_ignora_para_el_resto(): void
    {
        $persona = $this->persona();
        $datos = [
            'origen' => 'interna', 'sector_id' => $this->deposito->id, 'tipo' => 'falla_proceso_dep',
            'titulo' => 'Falla', 'descripcion' => 'Detalle.', 'prioridad' => 'media',
            'tipo_caso' => 'Verificación de productos comprados (Devolución a proveedores)',
            'datos_especificos' => ['detalle_proceso' => 'x'],
            'responsable_fallo_id' => $persona->id,
        ];

        $this->actingAs($this->usuario(['es_gerente' => true], ['observaciones.edit']))
            ->post('/observaciones', $datos)->assertSessionHasNoErrors();

        $this->actingAs($this->usuario([], ['observaciones.edit']))
            ->post('/observaciones', $datos)->assertSessionHasNoErrors();

        $conFallo = Observacion::orderBy('id')->get();
        $this->assertSame($persona->id, $conFallo[0]->responsable_fallo_id);
        $this->assertNull($conFallo[1]->responsable_fallo_id);
    }

    // ── Filtro, Excel y PDF ─────────────────────────────────────────────────

    public function test_el_filtro_funciona_para_jefes_y_se_ignora_para_el_resto(): void
    {
        $raul = $this->persona();
        $otra = $this->persona(['nombre' => 'Otra']);
        $this->fallo(null, $raul, ['numero' => '0001-26']);
        $this->fallo(null, $otra, ['numero' => '0002-26']);

        $this->actingAs($this->usuario(['es_gerente' => true]))
            ->get('/observaciones?responsable_fallo_id[]='.$raul->id)
            ->assertInertia(fn ($page) => $page->has('observaciones.data', 1)->where('observaciones.data.0.numero', '0001-26'));

        // Quien no lo ve no puede deducir quién falló probando ids: el filtro se descarta.
        $this->actingAs($this->usuario())
            ->get('/observaciones?responsable_fallo_id[]='.$raul->id)
            ->assertInertia(fn ($page) => $page->has('observaciones.data', 2)->where('filters', fn ($f) => ! isset($f['responsable_fallo_id'])));
    }

    private function columnasDelExcel(User $user): array
    {
        $contenido = $this->actingAs($user)->get('/observaciones/export')->assertOk()->streamedContent();
        $ruta = tempnam(sys_get_temp_dir(), 'obs').'.xlsx';
        file_put_contents($ruta, $contenido);

        $hoja = IOFactory::load($ruta)->getActiveSheet();
        $encabezado = $hoja->rangeToArray('A1:'.$hoja->getHighestColumn().'1')[0];
        $datos = $hoja->toArray();
        @unlink($ruta);

        return [$encabezado, $datos];
    }

    public function test_el_excel_trae_la_columna_solo_para_quien_lo_ve(): void
    {
        $this->fallo(null, $this->persona());

        [$encabezado, $datos] = $this->columnasDelExcel($this->usuario(['es_gerente' => true]));
        $this->assertContains('Responsable del fallo', $encabezado);
        $this->assertSame('Raúl Arce', $datos[1][array_search('Responsable del fallo', $encabezado)]);

        // Para los demás la columna no existe (no va vacía), y el nombre no está en ninguna celda.
        [$encabezado, $datos] = $this->columnasDelExcel($this->usuario());
        $this->assertNotContains('Responsable del fallo', $encabezado);
        $this->assertStringNotContainsString('Raúl Arce', json_encode($datos));
    }

    public function test_el_pdf_responde_para_los_dos(): void
    {
        $o = $this->fallo(null, $this->persona());

        $this->actingAs($this->usuario(['es_gerente' => true]))->get("/observaciones/{$o->id}/pdf")->assertOk();
        $this->actingAs($this->usuario())->get("/observaciones/{$o->id}/pdf")->assertOk();
    }

    // ── Personal por sector ─────────────────────────────────────────────────

    public function test_el_personal_se_carga_sin_usuario_ni_contrasena(): void
    {
        $this->actingAs($this->usuario([], ['users.view', 'users.edit']))
            ->post('/personal', ['nombre' => 'Chofer de Logística', 'sector_id' => $this->deposito->id, 'puesto' => 'Chofer', 'activo' => true])
            ->assertSessionHasNoErrors();

        $persona = Persona::firstOrFail();
        $this->assertSame('Chofer de Logística', $persona->nombre);
        $this->assertNull($persona->user_id);
        // No se creó ningún usuario para él.
        $this->assertSame(1, User::count());
    }

    public function test_se_marca_inactivo_y_se_conserva_en_el_historial(): void
    {
        $admin = $this->usuario([], ['users.view', 'users.edit']);
        $persona = $this->persona();
        $o = $this->fallo(null, $persona);

        $this->actingAs($admin)
            ->put("/personal/{$persona->id}", ['nombre' => 'Raúl Arce', 'sector_id' => $this->deposito->id, 'activo' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($persona->fresh()->activo);
        // Sigue figurando en lo que ya tenía.
        $this->assertSame($persona->id, $o->fresh()->responsable_fallo_id);
    }

    public function test_no_hay_borrado_de_personal(): void
    {
        $persona = $this->persona();

        $this->actingAs($this->usuario([], ['users.view', 'users.edit']))
            ->delete("/personal/{$persona->id}")
            ->assertStatus(405);

        $this->assertModelExists($persona);
    }

    public function test_el_personal_pide_los_permisos_de_usuarios(): void
    {
        $this->actingAs($this->usuario())->get('/personal')->assertForbidden();
        $this->actingAs($this->usuario([], ['users.view']))->get('/personal')->assertOk();
        $this->actingAs($this->usuario([], ['users.view']))
            ->post('/personal', ['nombre' => 'X', 'sector_id' => $this->deposito->id])
            ->assertForbidden();
    }

    public function test_el_nombre_y_el_sector_son_obligatorios(): void
    {
        $this->actingAs($this->usuario([], ['users.view', 'users.edit']))
            ->post('/personal', ['nombre' => '', 'sector_id' => ''])
            ->assertSessionHasErrors(['nombre', 'sector_id']);
    }
}
