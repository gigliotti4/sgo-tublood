<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Proveedor;
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

    /**
     * Desde el 6/11/2026 el responsable y Calidad también pueden (ver
     * CambioDeTipoTest). Lo que sigue sin poder es cualquier otra persona,
     * aunque tenga los permisos de ver y editar.
     */
    public function test_quien_no_es_responsable_ni_calidad_no_cambia_el_tipo(): void
    {
        $deposito = $this->sector('deposito', 'Depósito');
        $ajeno = $this->userWith('observaciones.view', 'observaciones.edit');
        $observacion = $this->interna($deposito, ['responsable_id' => User::factory()->create()->id]);

        $this->actingAs($ajeno)
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

    /**
     * Desde el 6/11/2026 SÍ se cruza entre reclamo de cliente e incidencia
     * interna (antes el backend lo rechazaba). La regla que queda: entrar a un
     * reclamo que exige productos sin tenerlos se rechaza.
     */
    public function test_se_cruza_entre_reclamo_de_cliente_e_incidencia_interna(): void
    {
        $calidad = $this->sector('garantia_calidad', 'Calidad');
        $deposito = $this->sector('deposito', 'Depósito');
        $reclamo = $this->interna($calidad, ['tipo' => 'falla_producto', 'datos_especificos' => []]);
        $interna = $this->interna($deposito, ['numero' => '0002-26']);
        $admin = $this->superAdmin();

        // Reclamo de cliente -> incidencia interna.
        $this->actingAs($admin)
            ->put("/observaciones/{$reclamo->id}/tipo", [
                'sector_id' => $deposito->id, 'tipo' => 'falla_proceso_dep',
                'datos_especificos' => ['detalle_proceso' => 'x'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('falla_proceso_dep', $reclamo->fresh()->tipo);

        // Incidencia interna -> reclamo con productos requeridos, sin productos.
        $this->actingAs($admin)
            ->put("/observaciones/{$interna->id}/tipo", ['sector_id' => $calidad->id, 'tipo' => 'falla_producto'])
            ->assertSessionHasErrors('tipo');

        $this->assertSame('falla_proceso_dep', $interna->fresh()->tipo);

        // Entre los dos reclamos de cliente, también.
        $reclamo = $this->interna($calidad, ['numero' => '0003-26', 'tipo' => 'falla_producto', 'datos_especificos' => []]);

        $this->actingAs($admin)
            ->put("/observaciones/{$reclamo->id}/tipo", ['sector_id' => $calidad->id, 'tipo' => 'disconformidad_servicio'])
            ->assertSessionHasNoErrors();

        $this->assertSame('disconformidad_servicio', $reclamo->fresh()->tipo);
    }

    // ── E.2 Falla de proveedor (Compras): productos opcionales ──────────────
    //
    // Antes era 6.2 en Compras; desde el 6/11/2026 es E.2 en el sector Externos y
    // el 6.2 quedó retirado (ver test_los_tipos_retirados_no_admiten_altas_nuevas).

    /** @param array<string, mixed> $extra */
    private function altaFallaProveedor(array $extra = []): TestResponse
    {
        $externos = $this->sector('externos', 'Externos');

        return $this->actingAs($this->userWith('observaciones.edit'))->post('/observaciones', [
            'origen' => 'interna',
            'sector_id' => $externos->id,
            'tipo' => 'falla_proveedor_compras',
            'titulo' => 'Entrega incompleta',
            'descripcion' => 'El proveedor mandó menos de lo pedido.',
            'prioridad' => 'media',
            'tipo_caso' => 'Verificación de productos comprados (Devolución a proveedores)',
            'datos_especificos' => ['proveedor' => 'PROPATO HNOS S.A.I.C.', 'motivo' => 'Faltante', 'detalle' => 'Faltaron cajas.'],
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
            // Externos son fallos de terceros (transporte, proveedor, fabricante):
            // no tiene un proceso interno que fallar.
            if ($slug === 'externos') {
                continue;
            }

            $esperadas = $slug === 'produccion' ? 2 : 1;
            $this->assertCount($esperadas, $porSector[$slug] ?? [], "{$slug} no tiene su falla de proceso");
        }

        $this->assertContains('finanzas', $sectores, 'Finanzas estrena tipos con la falla de proceso');
    }

    // ── Fallos externos E.1, E.2 y E.3 (pedido del 6/11/2026) ───────────────

    /** @param array<string, mixed> $extra */
    private function altaExterna(string $tipo, array $datos, array $extra = []): TestResponse
    {
        return $this->actingAs($this->userWith('observaciones.edit'))->post('/observaciones', [
            'origen' => 'interna',
            'sector_id' => $this->sector('externos', 'Externos')->id,
            'tipo' => $tipo,
            'titulo' => 'Falla de un tercero',
            'descripcion' => 'Detalle.',
            'prioridad' => 'media',
            'tipo_caso' => 'Verificación de productos comprados (Devolución a proveedores)',
            'datos_especificos' => $datos,
            ...$extra,
        ]);
    }

    private function e1(array $datos = []): array
    {
        return array_merge([
            'transporte' => 'Sneider', 'motivo' => 'Demora en entrega', 'numero_guia' => 'G-100',
            'numero_remito' => 'R-200', 'detalle' => 'Llegó tarde.',
        ], $datos);
    }

    public function test_e1_se_guarda_con_transporte_motivo_guia_y_remito(): void
    {
        $this->altaExterna('falla_logistica_tercerizada', $this->e1())
            ->assertSessionHasNoErrors()->assertRedirect(route('observaciones.index'));

        $o = Observacion::firstOrFail();
        $this->assertSame('falla_logistica_tercerizada', $o->tipo);
        $this->assertSame('externos', $o->sector->slug);
        $this->assertSame('Sneider', $o->datos_especificos['transporte']);
    }

    /** "No se puede guardar un E.1 sin transporte" (prueba del cliente). */
    public function test_e1_no_se_guarda_sin_transporte(): void
    {
        $this->altaExterna('falla_logistica_tercerizada', $this->e1(['transporte' => '']))
            ->assertSessionHasErrors('datos_especificos.transporte');

        $this->assertSame(0, Observacion::count());
    }

    public function test_e1_exige_guia_remito_y_motivo_de_la_lista(): void
    {
        $this->altaExterna('falla_logistica_tercerizada', $this->e1(['numero_guia' => '', 'numero_remito' => '', 'motivo' => 'Porque sí']))
            ->assertSessionHasErrors(['datos_especificos.numero_guia', 'datos_especificos.numero_remito', 'datos_especificos.motivo']);
    }

    /** El transporte es "lista + Otro": se puede escribir uno que no esté. */
    public function test_e1_acepta_un_transporte_que_no_esta_en_la_lista(): void
    {
        $this->altaExterna('falla_logistica_tercerizada', $this->e1(['transporte' => 'Expreso Nuevo SRL']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Expreso Nuevo SRL', Observacion::firstOrFail()->datos_especificos['transporte']);
    }

    public function test_e2_exige_proveedor_y_motivo_y_los_productos_son_opcionales(): void
    {
        $this->altaFallaProveedor(['datos_especificos' => ['proveedor' => '', 'motivo' => '', 'detalle' => 'x']])
            ->assertSessionHasErrors(['datos_especificos.proveedor', 'datos_especificos.motivo']);

        $this->altaFallaProveedor()->assertSessionHasNoErrors();

        $this->assertSame('PROPATO HNOS S.A.I.C.', Observacion::firstOrFail()->datos_especificos['proveedor']);
    }

    private function e3(array $extra = []): TestResponse
    {
        return $this->altaExterna('falla_calidad_distribucion', [
            'proveedor' => 'BD', 'muestra_disponible' => 'Sí', 'afecta_paciente' => 'A evaluar (lo define Calidad)',
            'detalle' => 'Tubos con pérdida.',
        ], [
            'contacto_numero_cliente' => '1006',
            'productos' => [['codigo' => 'RE-1521', 'producto' => 'Tubo', 'cantidad_afectada' => 4, 'lote' => 'L9', 'fecha_vencimiento' => '2027-03-01']],
            ...$extra,
        ]);
    }

    public function test_e3_se_guarda_con_cliente_producto_lote_y_vencimiento(): void
    {
        $this->e3()->assertSessionHasNoErrors();

        $o = Observacion::firstOrFail();
        $this->assertSame('1006', $o->contacto_numero_cliente);
        $this->assertSame('L9', $o->productos()->firstOrFail()->lote);
    }

    public function test_e3_exige_cliente_producto_lote_vencimiento_y_las_dos_preguntas(): void
    {
        $this->altaExterna('falla_calidad_distribucion', ['proveedor' => '', 'muestra_disponible' => '', 'afecta_paciente' => '', 'detalle' => 'x'])
            ->assertSessionHasErrors([
                'contacto_numero_cliente', 'productos',
                'datos_especificos.proveedor', 'datos_especificos.muestra_disponible', 'datos_especificos.afecta_paciente',
            ]);

        $this->e3(['productos' => [['codigo' => 'RE-1', 'producto' => 'Tubo', 'cantidad_afectada' => 1]]])
            ->assertSessionHasErrors(['productos.0.lote', 'productos.0.fecha_vencimiento']);
    }

    public function test_los_tipos_retirados_no_admiten_altas_nuevas(): void
    {
        $logistica = $this->sector('logistica', 'Logística');
        $compras = $this->sector('comex', 'Compras');
        $user = $this->userWith('observaciones.edit');

        $base = ['origen' => 'interna', 'titulo' => 'x', 'descripcion' => 'y', 'prioridad' => 'media', 'tipo_caso' => 'Verificación de productos comprados (Devolución a proveedores)'];

        // 3.5 (Logística) y 6.2 (Compras): pasan a E.1 y E.2.
        $this->actingAs($user)->post('/observaciones', $base + [
            'sector_id' => $logistica->id, 'tipo' => 'falla_proveedor_externo',
            'datos_especificos' => ['nombre_proveedor_externo' => 'Demonte'],
        ])->assertSessionHasErrors('tipo');

        $this->actingAs($user)->post('/observaciones', $base + [
            'sector_id' => $compras->id, 'tipo' => 'falla_proveedor',
            'datos_especificos' => ['numero_proveedor' => '22', 'descripcion_proveedor' => 'x'],
        ])->assertSessionHasErrors('tipo');

        $this->assertSame(0, Observacion::count());
    }

    /** Lo ya cargado con un tipo retirado se sigue mostrando con su etiqueta. */
    public function test_los_tipos_retirados_se_siguen_resolviendo_para_lo_ya_cargado(): void
    {
        $etiquetas = TaxonomiaIncidencias::etiquetasTipos();

        $this->assertSame('3.5 Falla de proveedor externo', $etiquetas['falla_proveedor_externo']);
        $this->assertSame('6.2 Falla de proveedor', $etiquetas['falla_proveedor']);
        $this->assertSame('logistica', TaxonomiaIncidencias::sectorDeTipo('falla_proveedor_externo'));
        $this->assertTrue(TaxonomiaIncidencias::esObsoleto('logistica', 'falla_proveedor_externo'));
        $this->assertFalse(TaxonomiaIncidencias::esObsoleto('externos', 'falla_logistica_tercerizada'));
    }

    public function test_no_se_puede_cambiar_a_un_tipo_retirado_pero_si_mover_uno_retirado(): void
    {
        $logistica = $this->sector('logistica', 'Logística');
        $deposito = $this->sector('deposito', 'Depósito');
        $o = $this->interna($deposito);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put("/observaciones/{$o->id}/tipo", [
                'sector_id' => $logistica->id, 'tipo' => 'falla_proveedor_externo',
                'datos_especificos' => ['nombre_proveedor_externo' => 'Demonte'],
            ])
            ->assertSessionHasErrors('tipo');

        $this->assertSame('falla_proceso_dep', $o->fresh()->tipo);
    }

    public function test_se_pasa_de_un_tipo_retirado_a_su_reemplazo_externo(): void
    {
        $logistica = $this->sector('logistica', 'Logística');
        $externos = $this->sector('externos', 'Externos');
        $o = $this->interna($logistica, [
            'tipo' => 'falla_proveedor_externo',
            'datos_especificos' => ['nombre_proveedor_externo' => 'Sneider'],
        ]);

        $this->actingAs($this->superAdmin())
            ->put("/observaciones/{$o->id}/tipo", ['sector_id' => $externos->id, 'tipo' => 'falla_logistica_tercerizada', 'datos_especificos' => $this->e1()])
            ->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('falla_logistica_tercerizada', $o->tipo);
        $this->assertSame($externos->id, $o->sector_id);
        // Lo que se reemplazó queda entero en la bitácora.
        $this->assertSame(
            ['nombre_proveedor_externo' => 'Sneider'],
            $o->historial()->where('accion', 'tipo')->firstOrFail()->cambios['datos_anteriores'],
        );
    }

    // ── 1.1 solo producto propio ────────────────────────────────────────────

    /** @param array<string, mixed> $datos */
    private function alta11(array $datos): TestResponse
    {
        return $this->actingAs($this->userWith('observaciones.edit'))->post('/observaciones', [
            'origen' => 'interna',
            'sector_id' => $this->sector('garantia_calidad', 'Calidad')->id,
            'tipo' => 'falla_producto',
            'titulo' => 'Tubo roto',
            'descripcion' => 'Llegó roto.',
            'prioridad' => 'alta',
            'tipo_caso' => 'Devolución',
            'institucion' => 'Hospital', 'provincia' => 'Buenos Aires',
            'productos' => [['codigo' => 'KS030', 'producto' => 'Tubo', 'cantidad_afectada' => 1, 'tipo_presentacion' => 'unidades', 'lote' => 'L1', 'fecha_vencimiento' => '2027-01-01']],
            'datos_especificos' => $datos,
        ]);
    }

    public function test_la_11_interna_exige_origen_muestra_y_si_puede_afectar_al_paciente(): void
    {
        $this->alta11([])
            ->assertSessionHasErrors(['datos_especificos.origen_producto', 'datos_especificos.muestra_disponible', 'datos_especificos.afecta_paciente']);

        $this->assertSame(0, Observacion::count());
    }

    public function test_la_11_importada_guarda_fabricante_y_despacho(): void
    {
        $this->alta11([
            'origen_producto' => 'Importado (marca propia)', 'fabricante_origen' => 'Carestainer', 'numero_despacho' => 'D-55',
            'muestra_disponible' => 'Sí', 'afecta_paciente' => 'A evaluar',
        ])->assertSessionHasNoErrors();

        $datos = Observacion::firstOrFail()->datos_especificos;
        $this->assertSame('Carestainer', $datos['fabricante_origen']);
        $this->assertSame('D-55', $datos['numero_despacho']);
    }

    /** Se cargó "Importado", después se cambió a "Fabricado": el fabricante no puede quedar guardado. */
    public function test_lo_que_ya_no_corresponde_al_origen_elegido_se_descarta(): void
    {
        $this->alta11([
            'origen_producto' => 'Fabricado por TUBLOOD', 'linea_produccion' => 'Tubos', 'fecha_produccion_op' => 'OP-12',
            'fabricante_origen' => 'Carestainer', 'numero_despacho' => 'D-55',
            'muestra_disponible' => 'No', 'afecta_paciente' => 'No',
        ])->assertSessionHasNoErrors();

        $datos = Observacion::firstOrFail()->datos_especificos;
        $this->assertSame('Tubos', $datos['linea_produccion']);
        $this->assertArrayNotHasKey('fabricante_origen', $datos);
        $this->assertArrayNotHasKey('numero_despacho', $datos);
    }

    public function test_la_disconformidad_de_servicio_no_pide_nada_de_esto(): void
    {
        $this->actingAs($this->userWith('observaciones.edit'))->post('/observaciones', [
            'origen' => 'interna',
            'sector_id' => $this->sector('garantia_calidad', 'Calidad')->id,
            'tipo' => 'disconformidad_servicio',
            'titulo' => 'Demora', 'descripcion' => 'x', 'prioridad' => 'media', 'tipo_caso' => 'Monitoreo de Servicios',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Observacion::firstOrFail()->datos_especificos);
    }

    public function test_el_buscador_de_proveedores_pide_solo_ver_observaciones(): void
    {
        Proveedor::create(['numero' => '22', 'razon_social' => 'PROPATO HNOS S.A.I.C.']);
        Proveedor::create(['numero' => '23', 'razon_social' => 'Otro Proveedor SA']);

        $this->actingAs($this->userWith('observaciones.view'))
            ->getJson('/observaciones/proveedores?q=propato')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.razon_social', 'PROPATO HNOS S.A.I.C.');

        // Un término corto no busca.
        $this->actingAs($this->userWith('observaciones.view'))
            ->getJson('/observaciones/proveedores?q=p')
            ->assertOk()->assertJsonCount(0);

        $this->actingAs(User::factory()->create())
            ->getJson('/observaciones/proveedores?q=propato')
            ->assertForbidden();
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
