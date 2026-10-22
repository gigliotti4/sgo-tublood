<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "SGO Observaciones a modificar 5" (reunión con Tublood del 21/10/2026), lado
 * observaciones:
 * - el super-admin cambia el tipo de una observación;
 * - 6.2 "Falla de proveedor" admite productos opcionales;
 * - "Falla de proceso" es el mismo formulario en todos los sectores.
 */
class ObservacionTipoYProductosTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'super-admin']));

        return $user;
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function sector(string $slug, string $nombre): Sector
    {
        return Sector::firstOrCreate(['slug' => $slug], ['nombre' => $nombre]);
    }

    /** @param array<string, mixed> $attrs */
    private function interna(Sector $sector, array $attrs = []): Observacion
    {
        return Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'origen' => 'interna',
            'estado' => 'clasificada',
            'tipo' => 'falla_proceso_dep',
            'sector_id' => $sector->id,
            'titulo' => 'Demora en salida de pedido',
            'descripcion' => 'El pedido se armó tarde.',
            'datos_especificos' => ['detalle_proceso' => 'Se armó el miércoles.'],
            ...$attrs,
        ]);
    }

    // ── Cambio de tipo ──────────────────────────────────────────────────────

    public function test_el_super_admin_cambia_el_tipo_y_carga_los_datos_del_nuevo(): void
    {
        $deposito = $this->sector('deposito', 'Depósito');
        $facturacion = $this->sector('facturacion', 'Facturación');
        $observacion = $this->interna($deposito);

        $this->actingAs($this->superAdmin())
            ->put("/observaciones/{$observacion->id}/tipo", [
                'sector_id' => $facturacion->id,
                'tipo' => 'error_facturacion',
                'datos_especificos' => ['tipo_comprobante' => 'Factura', 'numero_comprobante' => 'FA-1'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $observacion->refresh();
        $this->assertSame('error_facturacion', $observacion->tipo);
        $this->assertSame($facturacion->id, $observacion->sector_id);
        $this->assertSame('FA-1', $observacion->datos_especificos['numero_comprobante']);

        // La bitácora guarda el cambio y lo que se había cargado para el tipo viejo.
        $entrada = $observacion->historial()->where('accion', ObservationHistory::ACCION_TIPO)->firstOrFail();
        $this->assertSame('4.4 Falla de proceso', $entrada->cambios['de']);
        $this->assertSame('2.1 Error de facturación', $entrada->cambios['a']);
        $this->assertSame(['detalle_proceso' => 'Se armó el miércoles.'], $entrada->cambios['datos_anteriores']);
    }

    public function test_quien_no_es_super_admin_no_cambia_el_tipo(): void
    {
        $deposito = $this->sector('deposito', 'Depósito');
        $responsable = $this->userWith('observaciones.view', 'observaciones.edit');
        $observacion = $this->interna($deposito, ['responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->put("/observaciones/{$observacion->id}/tipo", ['sector_id' => $deposito->id, 'tipo' => 'falla_wms'])
            ->assertForbidden();

        $this->assertSame('falla_proceso_dep', $observacion->fresh()->tipo);
    }

    public function test_el_tipo_tiene_que_ser_del_sector_elegido(): void
    {
        $deposito = $this->sector('deposito', 'Depósito');
        $observacion = $this->interna($deposito);

        $this->actingAs($this->superAdmin())
            ->put("/observaciones/{$observacion->id}/tipo", ['sector_id' => $deposito->id, 'tipo' => 'error_facturacion'])
            ->assertSessionHasErrors('tipo');
    }

    /** Mismas reglas que el alta: un cambio no deja el caso como el formulario no lo habría aceptado. */
    public function test_el_tipo_nuevo_exige_sus_datos_obligatorios(): void
    {
        $deposito = $this->sector('deposito', 'Depósito');
        $observacion = $this->interna($deposito);

        $this->actingAs($this->superAdmin())
            ->put("/observaciones/{$observacion->id}/tipo", ['sector_id' => $deposito->id, 'tipo' => 'falla_wms'])
            ->assertSessionHasErrors(['datos_especificos.numero_remito', 'datos_especificos.descripcion_wms']);

        $this->assertSame('falla_proceso_dep', $observacion->fresh()->tipo);
    }

    /** Un reclamo de cliente tiene otro formulario: no se cruza a una incidencia interna, ni al revés. */
    public function test_no_se_cruza_entre_reclamo_de_cliente_e_incidencia_interna(): void
    {
        $calidad = $this->sector('garantia_calidad', 'Calidad');
        $deposito = $this->sector('deposito', 'Depósito');
        $reclamo = $this->interna($calidad, ['tipo' => 'falla_producto', 'datos_especificos' => []]);
        $interna = $this->interna($deposito, ['numero' => '0002-26']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put("/observaciones/{$reclamo->id}/tipo", [
                'sector_id' => $deposito->id, 'tipo' => 'falla_proceso_dep',
                'datos_especificos' => ['detalle_proceso' => 'x'],
            ])
            ->assertSessionHasErrors('tipo');

        $this->actingAs($admin)
            ->put("/observaciones/{$interna->id}/tipo", ['sector_id' => $calidad->id, 'tipo' => 'falla_producto'])
            ->assertSessionHasErrors('tipo');

        // Entre los dos reclamos de cliente, sí.
        $this->actingAs($admin)
            ->put("/observaciones/{$reclamo->id}/tipo", ['sector_id' => $calidad->id, 'tipo' => 'disconformidad_servicio'])
            ->assertSessionHasNoErrors();

        $this->assertSame('disconformidad_servicio', $reclamo->fresh()->tipo);
    }

    // ── 6.2 Falla de proveedor: productos opcionales ────────────────────────

    /** @param array<string, mixed> $extra */
    private function altaFallaProveedor(array $extra = []): TestResponse
    {
        $compras = $this->sector('comex', 'Compras');

        return $this->actingAs($this->userWith('observaciones.edit'))->post('/observaciones', [
            'origen' => 'interna',
            'sector_id' => $compras->id,
            'tipo' => 'falla_proveedor',
            'titulo' => 'Entrega incompleta',
            'descripcion' => 'El proveedor mandó menos de lo pedido.',
            'prioridad' => 'media',
            'tipo_caso' => 'Verificación de productos comprados (Devolución a proveedores)',
            'datos_especificos' => ['numero_proveedor' => '22', 'descripcion_proveedor' => 'Faltaron cajas.'],
            ...$extra,
        ]);
    }

    public function test_falla_de_proveedor_se_guarda_sin_productos(): void
    {
        $this->altaFallaProveedor(['productos' => [
            // El renglón en blanco del formulario no cuenta como producto.
            ['codigo' => '', 'producto' => '', 'cantidad_afectada' => null, 'lote' => ''],
        ]])->assertRedirect(route('observaciones.index'))->assertSessionHasNoErrors();

        $this->assertSame(0, Observacion::firstOrFail()->productos()->count());
    }

    public function test_falla_de_proveedor_guarda_un_producto_con_codigo_y_cantidad(): void
    {
        $this->altaFallaProveedor(['productos' => [
            ['codigo' => 'RE-1521', 'cantidad_afectada' => 10],
        ]])->assertRedirect(route('observaciones.index'))->assertSessionHasNoErrors();

        $producto = Observacion::firstOrFail()->productos()->firstOrFail();
        $this->assertSame('RE-1521', $producto->codigo);
        $this->assertNull($producto->lote);
        $this->assertNull($producto->fecha_vencimiento);
    }

    public function test_falla_de_proveedor_exige_codigo_o_nombre_y_cantidad_si_se_carga_un_producto(): void
    {
        $this->altaFallaProveedor(['productos' => [['lote' => 'L1']]])
            ->assertSessionHasErrors(['productos.0.codigo', 'productos.0.producto', 'productos.0.cantidad_afectada']);

        $this->assertSame(0, Observacion::count());
    }

    /** La Falla de producto no se ablandó: lote y vencimiento siguen siendo obligatorios. */
    public function test_falla_de_producto_sigue_exigiendo_lote_y_vencimiento(): void
    {
        $calidad = $this->sector('garantia_calidad', 'Calidad');

        $this->actingAs($this->userWith('observaciones.edit'))->post('/observaciones', [
            'origen' => 'interna',
            'sector_id' => $calidad->id,
            'tipo' => 'falla_producto',
            'titulo' => 'Tubo roto',
            'descripcion' => 'Llegó roto.',
            'prioridad' => 'alta',
            'tipo_caso' => 'Devolución',
            'institucion' => 'Hospital', 'provincia' => 'Buenos Aires',
            'productos' => [['codigo' => 'RE-1', 'producto' => 'Tubo', 'cantidad_afectada' => 1, 'tipo_presentacion' => 'unidades']],
        ])->assertSessionHasErrors(['productos.0.lote', 'productos.0.fecha_vencimiento']);
    }

    // ── Falla de proceso unificada ──────────────────────────────────────────

    public function test_todos_los_sectores_tienen_la_misma_falla_de_proceso(): void
    {
        $porSector = [];
        $campos = null;

        foreach (TaxonomiaIncidencias::taxonomia() as $slug => $tipos) {
            foreach ($tipos as $key => $def) {
                if (($def['label'] ?? '') !== 'Falla de proceso') {
                    continue;
                }

                $porSector[$slug][] = $key;
                $campos ??= $def['campos'];
                $this->assertSame($campos, $def['campos'], "{$key} tiene otro formulario");
            }
        }

        $sectores = array_keys(TaxonomiaIncidencias::taxonomia());
        foreach ($sectores as $slug) {
            $esperadas = $slug === 'produccion' ? 2 : 1;
            $this->assertCount($esperadas, $porSector[$slug] ?? [], "{$slug} no tiene su falla de proceso");
        }

        $this->assertContains('finanzas', $sectores, 'Finanzas estrena tipos con la falla de proceso');
    }

    public function test_claves_y_codigos_de_tipo_no_se_repiten(): void
    {
        $claves = [];
        $codigos = [];

        foreach (TaxonomiaIncidencias::taxonomia() as $tipos) {
            foreach ($tipos as $key => $def) {
                $claves[] = $key;
                $codigos[] = $def['codigo'];
            }
        }

        $this->assertSame($claves, array_unique($claves));
        $this->assertSame($codigos, array_unique($codigos));
    }
}
