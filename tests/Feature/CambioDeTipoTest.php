<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\ObservationProduct;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cambiar el tipo de una observación (punto 17 del pedido del 6/11/2026): lo
 * hacen el responsable, Calidad y el super-admin, y se puede cruzar entre
 * reclamos de cliente (tipos especiales) e incidencias internas.
 */
class CambioDeTipoTest extends TestCase
{
    use RefreshDatabase;

    private Sector $calidad;

    private Sector $logistica;

    private Sector $externos;

    /** Un E.1 válido: el destino de la prueba del cliente (la 0011 pasa de reclamo de cliente a falla de logística tercerizada). */
    private const DATOS_E1 = ['transporte' => 'Sneider', 'motivo' => 'Demora en entrega', 'numero_guia' => 'G-1', 'numero_remito' => 'R-1', 'detalle' => 'Demoró.'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->calidad = Sector::create(['nombre' => 'Calidad', 'slug' => 'garantia_calidad']);
        $this->logistica = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);
        $this->externos = Sector::create(['nombre' => 'Externos', 'slug' => 'externos']);
    }

    /** Un reclamo de cliente (como los que entran por el portal): disconformidad de servicio. */
    private function reclamo(?User $responsable = null, array $attrs = []): Observacion
    {
        return Observacion::create(array_merge([
            'numero' => '0011-26',
            'anio' => 2026,
            'tipo' => 'disconformidad_servicio',
            'estado' => 'clasificada',
            'origen' => 'externa',
            'tipo_caso' => 'Queja/Reclamo de clientes',
            'sector_id' => $this->calidad->id,
            'contacto_nombre' => 'Cliente',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'El transporte demoró la entrega',
            'descripcion' => 'Llegó tarde.',
            'responsable_id' => $responsable?->id,
        ], $attrs));
    }

    private function conRol(string $rol): User
    {
        $user = $this->nuevo();
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        $user->assignRole($rol);

        return $user;
    }

    private function aE1(array $extra = []): array
    {
        return array_merge([
            'sector_id' => $this->externos->id,
            'tipo' => 'falla_logistica_tercerizada',
            'datos_especificos' => self::DATOS_E1,
        ], $extra);
    }

    public function test_el_responsable_cambia_el_tipo_y_sigue_figurando_como_reclamo_de_cliente(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/tipo", $this->aE1())
            ->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('falla_logistica_tercerizada', $o->tipo);
        $this->assertSame($this->externos->id, $o->sector_id);
        // La prueba del cliente: se guarda como 3.5 y sigue siendo reclamo de cliente.
        $this->assertSame('externa', $o->origen);
        $this->assertSame('Queja/Reclamo de clientes', $o->tipo_caso);
        $this->assertSame(self::DATOS_E1, $o->datos_especificos);
    }

    public function test_el_cambio_queda_en_la_bitacora(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable);

        $this->actingAs($responsable)->put("/observaciones/{$o->id}/tipo", $this->aE1());

        $entrada = $o->historial()->where('accion', ObservationHistory::ACCION_TIPO)->first();

        $this->assertNotNull($entrada);
        $this->assertSame($responsable->id, $entrada->user_id);
    }

    public function test_calidad_cambia_el_tipo_sin_ser_la_responsable(): void
    {
        $o = $this->reclamo($this->nuevo());

        $this->actingAs($this->conRol('garantia_calidad'))
            ->put("/observaciones/{$o->id}/tipo", $this->aE1())
            ->assertSessionHasNoErrors();

        $this->assertSame('falla_logistica_tercerizada', $o->fresh()->tipo);
    }

    public function test_los_roles_de_reparto_de_reclamos_tambien_son_calidad(): void
    {
        $o = $this->reclamo($this->nuevo());

        $this->actingAs($this->conRol('calidad_servicio'))
            ->put("/observaciones/{$o->id}/tipo", $this->aE1())
            ->assertSessionHasNoErrors();

        $this->assertSame('falla_logistica_tercerizada', $o->fresh()->tipo);
    }

    public function test_el_super_admin_sigue_pudiendo(): void
    {
        $o = $this->reclamo($this->nuevo());

        $this->actingAs($this->conRol('super-admin'))
            ->put("/observaciones/{$o->id}/tipo", $this->aE1())
            ->assertSessionHasNoErrors();

        $this->assertSame('falla_logistica_tercerizada', $o->fresh()->tipo);
    }

    public function test_alguien_que_no_es_responsable_ni_calidad_no_puede(): void
    {
        $o = $this->reclamo($this->nuevo());

        $this->actingAs($this->nuevo())
            ->put("/observaciones/{$o->id}/tipo", $this->aE1())
            ->assertForbidden();

        $this->assertSame('disconformidad_servicio', $o->fresh()->tipo);
    }

    public function test_alguien_del_sector_destino_o_origen_tampoco_alcanza(): void
    {
        $o = $this->reclamo($this->nuevo());
        $deLogistica = $this->nuevo(['sector_id' => $this->logistica->id]);

        $this->actingAs($deLogistica)
            ->put("/observaciones/{$o->id}/tipo", $this->aE1())
            ->assertForbidden();
    }

    public function test_una_observacion_borrada_no_se_reencuadra(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable);
        $o->delete();

        // El binding de la ruta no resuelve borradas: 404, y la Policy lo
        // refuerza si alguna vez se las resolviera.
        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/tipo", $this->aE1())
            ->assertNotFound();

        $this->assertSame('disconformidad_servicio', $o->fresh()->tipo ?? Observacion::withTrashed()->find($o->id)->tipo);
    }

    public function test_editar_el_texto_sigue_siendo_solo_del_super_admin(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/contenido", ['titulo' => 'Otro', 'descripcion' => 'Otro'])
            ->assertForbidden();

        $this->actingAs($this->conRol('garantia_calidad'))
            ->put("/observaciones/{$o->id}/contenido", ['titulo' => 'Otro', 'descripcion' => 'Otro'])
            ->assertForbidden();
    }

    public function test_pide_los_datos_especificos_del_tipo_nuevo(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/tipo", $this->aE1(['datos_especificos' => ['transporte' => '']]))
            ->assertSessionHasErrors('datos_especificos.transporte');

        $this->assertSame('disconformidad_servicio', $o->fresh()->tipo);
    }

    public function test_el_tipo_tiene_que_ser_del_sector_elegido(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/tipo", $this->aE1(['sector_id' => $this->calidad->id]))
            ->assertSessionHasErrors('tipo');
    }

    public function test_una_incidencia_interna_puede_pasar_a_reclamo_de_cliente_si_tiene_los_productos(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable, [
            'origen' => 'interna',
            'tipo' => 'falla_proceso_logistica',
            'sector_id' => $this->externos->id,
            'datos_especificos' => ['detalle_proceso' => 'x'],
            'contacto_numero_cliente' => '123',
        ]);

        // Sin productos, 1.1 se rechaza: la ficha quedaría vacía.
        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/tipo", ['sector_id' => $this->calidad->id, 'tipo' => 'falla_producto'])
            ->assertSessionHasErrors('tipo');

        ObservationProduct::create([
            'observation_id' => $o->id,
            'producto' => 'Tubo',
            'codigo' => 'KS030',
            'cantidad_afectada' => 2,
            'lote' => 'L1',
            'fecha_vencimiento' => '2027-01-01',
        ]);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/tipo", ['sector_id' => $this->calidad->id, 'tipo' => 'falla_producto'])
            ->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('falla_producto', $o->tipo);
        // Los especiales no tienen "Datos específicos": lo anterior no queda huérfano.
        $this->assertNull($o->datos_especificos);
        // El historial conserva lo que se reemplazó.
        $entrada = $o->historial()->where('accion', ObservationHistory::ACCION_TIPO)->first();
        $this->assertSame(['detalle_proceso' => 'x'], $entrada->cambios['datos_anteriores']);
    }

    public function test_entre_dos_especiales_los_datos_se_conservan(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable, ['datos_especificos' => ['algo' => 'valor']]);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/tipo", ['sector_id' => $this->calidad->id, 'tipo' => 'falla_producto'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['algo' => 'valor'], $o->fresh()->datos_especificos);
    }

    public function test_los_productos_cargados_no_se_tocan(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable, ['tipo' => 'falla_producto']);
        ObservationProduct::create([
            'observation_id' => $o->id,
            'producto' => 'Tubo',
            'codigo' => 'KS030',
            'cantidad_afectada' => 2,
            'lote' => 'L1',
            'fecha_vencimiento' => '2027-01-01',
        ]);

        $this->actingAs($responsable)->put("/observaciones/{$o->id}/tipo", $this->aE1());

        $this->assertSame(1, $o->productos()->count());
    }

    public function test_el_cambio_de_tipo_no_reinicia_el_plazo(): void
    {
        $responsable = $this->nuevo();
        $o = $this->reclamo($responsable);
        $antes = [$o->fresh()->responsable_asignado_at, $o->fresh()->vence_at];

        $this->actingAs($responsable)->put("/observaciones/{$o->id}/tipo", $this->aE1());

        $despues = $o->fresh();
        $this->assertEquals($antes[0], $despues->responsable_asignado_at);
        $this->assertEquals($antes[1], $despues->vence_at);
    }

    public function test_el_listado_manda_la_taxonomia_solo_a_quien_puede_usarla(): void
    {
        $responsable = $this->nuevo();
        $this->reclamo($responsable);

        $this->actingAs($responsable)
            ->get('/observaciones')
            ->assertInertia(fn ($page) => $page->whereNot('taxonomia', null));

        $otro = $this->nuevo();

        $this->actingAs($otro)
            ->get('/observaciones')
            ->assertInertia(fn ($page) => $page->where('taxonomia', null));
    }

    public function test_la_ficha_ofrece_el_cambio_a_calidad_aunque_no_gestione_el_caso(): void
    {
        $o = $this->reclamo($this->nuevo());
        $calidad = $this->conRol('garantia_calidad');

        $this->actingAs($calidad)
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page
                ->where('puedeEditar', false)
                ->where('puedeCambiarTipo', true)
                ->whereNot('taxonomia', null));

        $ajeno = $this->nuevo();

        $this->actingAs($ajeno)
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page
                ->where('puedeCambiarTipo', false)
                ->where('taxonomia', null));
    }

    /**
     * Un usuario común del panel: todas las rutas de observaciones exigen
     * `observaciones.view`, y poder cambiar el tipo no lo reemplaza.
     */
    private function nuevo(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        Permission::firstOrCreate(['name' => 'observaciones.view', 'guard_name' => 'web']);
        $user->givePermissionTo('observaciones.view');

        return $user;
    }
}
