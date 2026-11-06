<?php

namespace Tests\Feature;

use App\Models\Indicador;
use App\Models\IndicadorCarga;
use App\Models\Observacion;
use App\Models\Persona;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Objetivos de gestión (la planilla "OBJETIVOS 2026") y el tablero que los usa.
 *
 * Las cuentas del tablero (semáforo, índice, peor mes) viven en
 * `resources/js/lib/tablero.ts` y se verifican con node; acá se fija lo que es de
 * PHP: el acceso, la subida en dos pasos, el round-trip del export y el dataset.
 */
class ObjetivosTableroTest extends TestCase
{
    use RefreshDatabase;

    private const ENCABEZADOS = ['SECTOR', 'FALLO', 'FUENTE / TIPO SGO', 'OBJETIVO MENSUAL', 'TOLERANCIA MÁXIMA', 'TIPO DE META', 'SEP-26'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function conRol(string $rol): User
    {
        return tap(User::factory()->create())->assignRole($rol);
    }

    private function editor(): User
    {
        return tap(User::factory()->create())->givePermissionTo(['objetivos.view', 'objetivos.edit']);
    }

    /** @param  list<list<mixed>>  $filas */
    private function planilla(array $filas): UploadedFile
    {
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->fromArray([self::ENCABEZADOS, ...$filas], null, 'A1');

        $ruta = tempnam(sys_get_temp_dir(), 'obj').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'OBJETIVOS.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // ── Acceso ──────────────────────────────────────────────────────

    public function test_el_rol_tablero_ve_el_tablero_y_los_objetivos_pero_no_los_edita(): void
    {
        $u = $this->conRol('tablero');

        $this->actingAs($u)->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->component('Dashboard')->has('tablero.observaciones'));
        $this->actingAs($u)->get('/objetivos')->assertOk();
        $this->actingAs($u)->post('/objetivos/previsualizar')->assertForbidden();
        $this->actingAs($u)->post('/objetivos/aplicar')->assertForbidden();
    }

    public function test_los_roles_generales_no_traen_el_tablero(): void
    {
        $this->assertEqualsCanonicalizing(
            ['tablero.view', 'objetivos.view'],
            Role::findByName('tablero')->permissions->pluck('name')->all(),
        );

        foreach (['usuario_interno', 'admin', 'viewer', 'garantia_calidad'] as $rol) {
            $this->assertFalse(Role::findByName($rol)->hasPermissionTo('tablero.view'), "{$rol} no debería ver el tablero");
        }

        // El Dashboard es de todos, pero el tablero adentro no viaja sin el permiso.
        $this->actingAs($this->conRol('usuario_interno'))->get('/dashboard')->assertOk()
            ->assertInertia(fn ($p) => $p->where('tablero', null));
        // La URL vieja redirige al Dashboard.
        $this->actingAs($this->conRol('tablero'))->get('/tablero')->assertRedirect(route('dashboard'));
        $this->actingAs($this->conRol('usuario_interno'))->get('/objetivos')->assertForbidden();
    }

    // ── La planilla, en dos pasos ───────────────────────────────────

    public function test_previsualizar_no_escribe_nada_y_aplicar_si(): void
    {
        $archivo = $this->planilla([['LOGISTICA', 'Falla del transporte', 'SGO · E.1', 1, 3, 'Máximo de fallos', null]]);

        $this->actingAs($this->editor())->post('/objetivos/previsualizar', ['archivo' => $archivo])
            ->assertRedirect(route('objetivos.index'));

        $this->assertSame(0, Indicador::count(), 'la vista previa no puede escribir');

        // La vista previa llega a la pantalla una sola vez (es un flash).
        $this->actingAs($this->editor())->followingRedirects()->post('/objetivos/previsualizar', ['archivo' => $this->planilla([
            ['LOGISTICA', 'Falla del transporte', 'SGO · E.1', 1, 3, 'Máximo de fallos', null],
        ])])->assertInertia(fn ($page) => $page
            ->component('Admin/Objetivos/Index')
            ->where('previa.resumen.nuevos', 1)
            ->where('previa.aplicable', true));

        $this->assertSame(0, Indicador::count());
    }

    public function test_aplicar_crea_los_indicadores_y_deja_el_historial(): void
    {
        $editor = $this->editor();
        $archivo = $this->planilla([
            ['LOGISTICA', 'Falla del transporte', 'SGO · E.1', 1, 3, 'Máximo de fallos', null],
            ['FINANZAS', 'Cobranzas fuera de término', 'Manual', 2, 4, 'Máximo de fallos', 3],
        ]);

        $this->actingAs($editor)->post('/objetivos/previsualizar', ['archivo' => $archivo]);

        // El archivo queda guardado con un token entre los dos pasos.
        $token = collect(Storage::disk('local')->files('objetivos/pendientes'))->first();
        $this->assertNotNull($token);
        $uuid = pathinfo($token, PATHINFO_FILENAME);

        $this->actingAs($editor)->post('/objetivos/aplicar', ['token' => $uuid, 'archivo' => 'OBJETIVOS.xlsx'])
            ->assertRedirect(route('objetivos.index'))
            ->assertSessionHas('success');

        $this->assertSame(2, Indicador::count());

        $sgo = Indicador::where('fallo', 'Falla del transporte')->first();
        $this->assertTrue($sgo->esDelSgo());
        $this->assertSame(['falla_logistica_tercerizada'], $sgo->tipos);

        // El manual guarda el valor del mes; el del SGO no (lo cuenta de las observaciones).
        $manual = Indicador::where('fallo', 'Cobranzas fuera de término')->first();
        $this->assertFalse($manual->esDelSgo());
        $this->assertSame(3.0, (float) $manual->valores()->where('mes', '2026-09')->value('valor'));
        $this->assertSame(0, $sgo->valores()->count());

        $this->assertSame(1, IndicadorCarga::count());
        $this->assertEmpty(Storage::disk('local')->files('objetivos/pendientes'), 'la planilla pendiente se borra al aplicar');
    }

    public function test_una_planilla_con_errores_no_se_puede_aplicar(): void
    {
        $editor = $this->editor();
        // Tolerancia menor que el objetivo en un máximo: error.
        $archivo = $this->planilla([['LOGISTICA', 'Falla del transporte', 'SGO · E.1', 5, 2, 'Máximo de fallos', null]]);

        $this->actingAs($editor)->post('/objetivos/previsualizar', ['archivo' => $archivo]);
        $uuid = pathinfo(collect(Storage::disk('local')->files('objetivos/pendientes'))->first(), PATHINFO_FILENAME);

        $this->actingAs($editor)->get('/objetivos')->assertInertia(fn ($p) => $p->where('previa.aplicable', false));

        $this->actingAs($editor)->post('/objetivos/aplicar', ['token' => $uuid, 'archivo' => 'x.xlsx'])
            ->assertSessionHas('error');

        $this->assertSame(0, Indicador::count());
    }

    public function test_volver_a_subir_la_planilla_no_pisa_los_ajustes_hechos_a_mano(): void
    {
        $editor = $this->editor();
        $filas = [['LOGISTICA', 'Falla del transporte', 'SGO · E.1', 1, 3, 'Máximo de fallos', null]];

        $subir = function () use ($editor, $filas) {
            $this->actingAs($editor)->post('/objetivos/previsualizar', ['archivo' => $this->planilla($filas)]);
            $uuid = pathinfo(collect(Storage::disk('local')->files('objetivos/pendientes'))->first(), PATHINFO_FILENAME);
            $this->actingAs($editor)->post('/objetivos/aplicar', ['token' => $uuid, 'archivo' => 'x.xlsx']);
        };

        $subir();
        // Alguien afina a mano qué cuenta el indicador.
        Indicador::first()->update(['tipos' => ['falla_logistica_tercerizada', 'falla_proveedor_compras']]);
        $subir();

        $this->assertCount(2, Indicador::first()->tipos, 'la misma fuente no se vuelve a deducir');
    }

    public function test_lo_que_baja_el_export_se_puede_volver_a_subir(): void
    {
        $editor = $this->editor();
        Indicador::create([
            'sector' => 'FINANZAS', 'fallo' => 'Cobranzas', 'origen_dato' => 'manual', 'fuente' => 'Manual',
            'objetivo' => 2, 'tolerancia' => 4, 'tipo_meta' => 'maximo', 'estado' => 'aprobado', 'activo' => true,
        ])->valores()->create(['mes' => '2026-09', 'valor' => 3]);

        $respuesta = $this->actingAs($editor)->get('/objetivos/export')->assertOk();

        $ruta = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());
        $subido = new UploadedFile($ruta, 'objetivos.xlsx', null, null, true);

        $this->actingAs($editor)->post('/objetivos/previsualizar', ['archivo' => $subido]);

        $this->actingAs($editor)->get('/objetivos')->assertInertia(fn ($p) => $p
            ->where('previa.resumen.sin_cambios', 1)
            ->where('previa.resumen.nuevos', 0)
            ->where('previa.resumen.con_errores', 0));
    }

    // ── Edición a mano ──────────────────────────────────────────────

    public function test_los_valores_mensuales_solo_se_cargan_en_indicadores_manuales(): void
    {
        $editor = $this->editor();
        $datos = ['sector' => 'X', 'fallo' => 'Y', 'fuente' => null, 'objetivo' => 1, 'tolerancia' => 2, 'tipo_meta' => 'maximo', 'estado' => 'aprobado', 'activo' => true];

        $manual = Indicador::create([...$datos, 'fallo' => 'M', 'origen_dato' => 'manual']);
        $sgo = Indicador::create([...$datos, 'fallo' => 'S', 'origen_dato' => 'sgo', 'tipos' => ['falla_logistica_tercerizada']]);

        $this->actingAs($editor)->put("/objetivos/{$manual->id}/valores", ['valores' => ['2026-10' => 4, '2026-09' => null]])->assertRedirect();
        $this->assertSame(4.0, (float) $manual->valores()->where('mes', '2026-10')->value('valor'));

        // Un mes vacío se borra.
        $this->actingAs($editor)->put("/objetivos/{$manual->id}/valores", ['valores' => ['2026-10' => '']]);
        $this->assertSame(0, $manual->valores()->count());

        $this->actingAs($editor)->put("/objetivos/{$sgo->id}/valores", ['valores' => ['2026-10' => 4]])->assertStatus(422);
    }

    public function test_no_se_guarda_una_tolerancia_incoherente_con_el_tipo_de_meta(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post('/objetivos', [
            'sector' => 'X', 'fallo' => 'Y', 'tipo_meta' => 'maximo', 'objetivo' => 5, 'tolerancia' => 2, 'estado' => 'aprobado', 'activo' => true,
        ])->assertSessionHasErrors('tolerancia');

        $this->assertSame(0, Indicador::count());
    }

    // ── Qué cuenta cada indicador ───────────────────────────────────

    public function test_un_criterio_de_motivo_exige_que_el_caso_tenga_motivo(): void
    {
        $demora = new Indicador(['origen_dato' => 'sgo', 'tipos' => ['t'], 'criterios' => ['motivo' => ['Demora en entrega']]]);
        $calidad = new Indicador(['origen_dato' => 'sgo', 'tipos' => ['t'], 'criterios' => ['motivo_no' => ['Demora en entrega']]]);

        $this->assertTrue($demora->cuenta('t', null, ['motivo' => 'Demora en entrega']));
        $this->assertFalse($demora->cuenta('t', null, ['motivo' => 'Producto no conforme']));
        $this->assertFalse($calidad->cuenta('t', null, ['motivo' => 'Demora en entrega']));
        $this->assertTrue($calidad->cuenta('t', null, ['motivo' => 'Producto no conforme']));
        // Sin motivo no cuenta en ninguno de los dos lados.
        $this->assertFalse($demora->cuenta('t', null, null));
        $this->assertFalse($calidad->cuenta('t', null, null));
    }

    // ── Dataset del tablero ─────────────────────────────────────────

    private function observacion(array $extra = []): Observacion
    {
        return Observacion::create([
            'numero' => '0001-26', 'anio' => 2026, 'tipo' => 'falla_logistica_tercerizada', 'estado' => 'cerrada',
            'origen' => 'interna', 'contacto_nombre' => 'Test', 'contacto_email' => 'test@example.com',
            'titulo' => 'Título', 'descripcion' => 'Descripción', ...$extra,
        ]);
    }

    public function test_el_tablero_manda_las_observaciones_y_los_indicadores_compactos(): void
    {
        $user = $this->conRol('tablero');
        $obs = $this->observacion(['tipo_caso' => 'Queja/Reclamo de clientes']);
        Indicador::create([
            'sector' => 'LOGISTICA', 'fallo' => 'Transporte', 'origen_dato' => 'sgo', 'tipos' => ['falla_logistica_tercerizada'],
            'objetivo' => 1, 'tolerancia' => 3, 'tipo_meta' => 'maximo', 'estado' => 'aprobado', 'activo' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Dashboard')
            ->has('tablero.observaciones', 1)
            ->where('tablero.observaciones.0.i', $obs->id)
            ->where('tablero.observaciones.0.t', 'falla_logistica_tercerizada')
            ->where('tablero.observaciones.0.l', 'E.1 Falla de logística tercerizada')
            ->where('tablero.observaciones.0.m', $obs->created_at->format('Y-m'))
            ->where('tablero.observaciones.0.tc', 'Queja/Reclamo de clientes')
            ->has('tablero.indicadores', 1)
            ->where('tablero.indicadores.0.tipos', ['falla_logistica_tercerizada'])
            ->where('tablero.indicadores.0.codigos', 'E.1'));
    }

    /** "Externo" es el TIPO de fallo (E.1/E.2/E.3), no el origen de la carga: una interna puede ser externa. */
    public function test_un_fallo_externo_es_el_que_cuelga_del_sector_externos(): void
    {
        $this->seed(SectorSeeder::class);
        $this->observacion(['numero' => '0001-26']);
        $this->observacion(['numero' => '0002-26', 'tipo' => 'falla_proceso_dep']);

        $this->actingAs($this->conRol('tablero'))->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('tablero.observaciones.0.x', 1)
            ->where('tablero.observaciones.1.x', 0));
    }

    public function test_la_prorroga_manda_sobre_el_vencimiento_original_en_el_dataset(): void
    {
        $this->observacion(['vence_at' => '2026-11-04 10:00:00', 'prorroga_hasta' => '2026-11-20 23:59:59']);

        $this->actingAs($this->conRol('tablero'))->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('tablero.observaciones.0.v', '2026-11-20'));
    }

    /** El dataset viaja entero al navegador: esconderlo en la pantalla no alcanza. */
    public function test_el_responsable_del_fallo_solo_viaja_a_quien_puede_verlo(): void
    {
        $persona = Persona::create(['nombre' => 'Raúl Arce', 'activo' => true]);
        $this->observacion(['responsable_fallo_id' => $persona->id]);

        $comun = $this->conRol('tablero');
        $this->actingAs($comun)->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('tablero.puedeVerResponsableFallo', false)
            ->where('tablero.observaciones.0.rf', null));
        $this->assertStringNotContainsString('Raúl Arce', $this->actingAs($comun)->get('/dashboard')->getContent());

        $calidad = tap(User::factory()->create())->assignRole(['tablero', 'garantia_calidad']);
        $this->actingAs($calidad)->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('tablero.puedeVerResponsableFallo', true)
            ->where('tablero.observaciones.0.rf', 'Raúl Arce'));
    }
}
