<?php

namespace Tests\Feature;

use App\Models\NoConformidad;
use App\Models\NonConformityAction;
use App\Models\NonConformityHistory;
use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\AccionAsignadaNotification;
use App\Notifications\NoConformidadAsignadaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Flujo de No Conformidades — `docs/IT- PARA LA GESTIÓN DE NO CONFORMIDADES EN
 * EL SISTEMA.docx`.
 *
 * El foco está en las reglas que no se ven leyendo el código: qué transiciones
 * existen, quién puede aprobarlas, cuándo se asigna el número y qué queda
 * escrito en la bitácora.
 */
class NoConformidadTest extends TestCase
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

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user->assignRole('super-admin');

        return $user;
    }

    private function sector(): Sector
    {
        return Sector::firstOrCreate(
            ['slug' => 'garantia_calidad'],
            ['nombre' => 'Garantía de Calidad', 'dias_gestion' => 5],
        );
    }

    /** @param array<string, mixed> $attrs */
    private function nc(array $attrs = []): NoConformidad
    {
        return NoConformidad::create(array_merge([
            'anio' => 2026,
            'estado' => 'borrador',
            'tipo_desvio' => 'interno',
            'fecha_deteccion' => '2026-09-20',
            'motivo' => 'Un resultado de auditoría interna.',
            'sector_id' => $this->sector()->id,
            'descripcion' => 'Descripción de prueba suficientemente larga.',
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function datosValidos(array $extra = []): array
    {
        return array_merge([
            'tipo_desvio' => 'interno',
            'fecha_deteccion' => '2026-09-20',
            'motivo' => 'Un resultado de auditoría interna.',
            'sector_id' => $this->sector()->id,
            'descripcion' => 'Descripción de prueba suficientemente larga.',
        ], $extra);
    }

    // ── Acceso ──────────────────────────────────────────────────────────────

    public function test_el_listado_requiere_permiso_nc_view(): void
    {
        $this->actingAs(User::factory()->create())->get('/no-conformidades')->assertStatus(403);
    }

    public function test_crear_requiere_permiso_nc_create(): void
    {
        $this->actingAs($this->userWith('nc.view'))->get('/no-conformidades/crear')->assertStatus(403);
        $this->actingAs($this->userWith('nc.create'))->get('/no-conformidades/crear')->assertOk();
    }

    // ── Alta ────────────────────────────────────────────────────────────────

    /**
     * ⚠️ La regla central del alta: una NC nace en borrador y **sin número**.
     * El número es lo que la vuelve formal, y eso pasa recién al aprobarla.
     */
    public function test_una_nc_nueva_queda_en_borrador_y_sin_numero(): void
    {
        $this->actingAs($this->userWith('nc.create', 'nc.view'))
            ->post('/no-conformidades', $this->datosValidos())
            ->assertRedirect();

        $nc = NoConformidad::sole();

        $this->assertSame('borrador', $nc->estado);
        $this->assertNull($nc->numero);
    }

    public function test_el_alta_valida_los_campos_minimos(): void
    {
        $this->actingAs($this->userWith('nc.create'))
            ->post('/no-conformidades', [])
            ->assertSessionHasErrors(['tipo_desvio', 'fecha_deteccion', 'motivo', 'sector_id', 'descripcion']);
    }

    /**
     * El motivo es texto libre desde el 24/9/2026 (así lo escribe el formulario
     * en papel), pero no cualquier cosa: dos caracteres no describen nada.
     */
    public function test_el_motivo_es_texto_libre_pero_no_vacio(): void
    {
        $usuario = $this->userWith('nc.create');

        $this->actingAs($usuario)
            ->post('/no-conformidades', $this->datosValidos(['motivo' => 'x']))
            ->assertSessionHasErrors('motivo');

        $this->actingAs($usuario)
            ->post('/no-conformidades', $this->datosValidos([
                'motivo' => 'Mala implementación del código de barras',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('non_conformities', [
            'motivo' => 'Mala implementación del código de barras',
        ]);
    }

    // ── Aprobación (§4.3) ───────────────────────────────────────────────────

    public function test_solo_quien_tiene_nc_aprobar_puede_aprobar(): void
    {
        $nc = $this->nc(['estado' => 'pendiente_aprobacion']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/aprobar")
            ->assertStatus(403);

        $this->assertSame('pendiente_aprobacion', $nc->fresh()->estado);
    }

    /** El número aparece acá, y no antes (§4.4). */
    public function test_aprobar_abre_la_nc_y_le_asigna_el_numero(): void
    {
        $nc = $this->nc(['estado' => 'pendiente_aprobacion']);

        $this->actingAs($this->userWith('nc.view', 'nc.aprobar'))
            ->post("/no-conformidades/{$nc->id}/aprobar")
            ->assertRedirect();

        $nc->refresh();

        $this->assertSame('abierta', $nc->estado);
        $this->assertSame('NC-0001-26', $nc->numero);
        $this->assertNotNull($nc->aprobada_at);
        $this->assertNotNull($nc->aprobada_por);
    }

    public function test_el_correlativo_avanza_y_resetea_por_anio(): void
    {
        $aprobador = $this->userWith('nc.view', 'nc.aprobar');

        foreach ([1, 2] as $_) {
            $nc = $this->nc(['estado' => 'pendiente_aprobacion']);
            $this->actingAs($aprobador)->post("/no-conformidades/{$nc->id}/aprobar");
        }

        $delAnioQueViene = $this->nc(['estado' => 'pendiente_aprobacion', 'anio' => 2027]);
        $this->actingAs($aprobador)->post("/no-conformidades/{$delAnioQueViene->id}/aprobar");

        $this->assertSame(
            ['NC-0001-26', 'NC-0002-26', 'NC-0001-27'],
            NoConformidad::orderBy('id')->pluck('numero')->all(),
        );
    }

    public function test_devolver_exige_motivo_y_vuelve_a_borrador(): void
    {
        $nc = $this->nc(['estado' => 'pendiente_aprobacion']);
        $aprobador = $this->userWith('nc.view', 'nc.aprobar');

        $this->actingAs($aprobador)
            ->post("/no-conformidades/{$nc->id}/devolver", [])
            ->assertSessionHasErrors('motivo_decision');

        $this->assertSame('pendiente_aprobacion', $nc->fresh()->estado);

        $this->actingAs($aprobador)
            ->post("/no-conformidades/{$nc->id}/devolver", ['motivo_decision' => 'Falta el informe del laboratorio.'])
            ->assertRedirect();

        $this->assertSame('borrador', $nc->fresh()->estado);
        $this->assertDatabaseHas('non_conformity_history', [
            'non_conformity_id' => $nc->id,
            'accion' => NonConformityHistory::ACCION_DEVOLUCION,
            'nota' => 'Falta el informe del laboratorio.',
        ]);
    }

    /** ⚠️ §4.3: "las NC rechazadas no deberán eliminarse. Permanecerán registradas como antecedente". */
    public function test_una_nc_rechazada_no_se_borra_y_queda_sin_numero(): void
    {
        $nc = $this->nc(['estado' => 'pendiente_aprobacion']);

        $this->actingAs($this->userWith('nc.view', 'nc.aprobar'))
            ->post("/no-conformidades/{$nc->id}/rechazar", ['motivo_decision' => 'No corresponde abrir una NC.'])
            ->assertRedirect();

        $nc->refresh();

        $this->assertSame('rechazada', $nc->estado);
        $this->assertNull($nc->numero);
        $this->assertNull($nc->deleted_at);
        $this->assertDatabaseHas('non_conformities', ['id' => $nc->id]);
    }

    // ── Transiciones (§4.1) ─────────────────────────────────────────────────

    public function test_no_se_puede_aprobar_una_nc_que_no_esta_pendiente(): void
    {
        $nc = $this->nc(['estado' => 'borrador']);

        $this->actingAs($this->userWith('nc.view', 'nc.aprobar'))
            ->post("/no-conformidades/{$nc->id}/aprobar")
            ->assertStatus(403);
    }

    public function test_el_mapa_de_transiciones_refleja_el_flujograma(): void
    {
        $nc = $this->nc();

        $nc->estado = 'borrador';
        $this->assertTrue($nc->puedeTransicionarA('pendiente_aprobacion'));
        $this->assertFalse($nc->puedeTransicionarA('abierta'));

        // ⚠️ Desde verificación solo se cierra. Un resultado ineficaz **no**
        // reabre la NC: abre un desvío nuevo, que es otro registro.
        $nc->estado = 'verificacion_eficacia';
        $this->assertTrue($nc->puedeTransicionarA('cerrada'));
        $this->assertFalse($nc->puedeTransicionarA('abierta'));

        // Los terminales no van a ningún lado por el flujo normal.
        $nc->estado = 'cerrada';
        $this->assertSame([], NoConformidad::TRANSICIONES['cerrada']);
        $nc->estado = 'rechazada';
        $this->assertSame([], NoConformidad::TRANSICIONES['rechazada']);
    }

    public function test_enviar_a_aprobacion_lo_hace_el_creador(): void
    {
        $creador = $this->userWith('nc.view', 'nc.create');
        $ajeno = $this->userWith('nc.view', 'nc.create');
        $nc = $this->nc(['creado_por' => $creador->id]);

        $this->actingAs($ajeno)->post("/no-conformidades/{$nc->id}/enviar-a-aprobacion")->assertStatus(403);

        $this->actingAs($creador)->post("/no-conformidades/{$nc->id}/enviar-a-aprobacion")->assertRedirect();
        $this->assertSame('pendiente_aprobacion', $nc->fresh()->estado);
    }

    // ── Edición (§6) ────────────────────────────────────────────────────────

    public function test_el_creador_edita_su_borrador_pero_un_tercero_no(): void
    {
        $creador = $this->userWith('nc.view', 'nc.create');
        $ajeno = $this->userWith('nc.view', 'nc.create');
        $nc = $this->nc(['creado_por' => $creador->id]);

        $this->actingAs($ajeno)
            ->put("/no-conformidades/{$nc->id}", $this->datosValidos())
            ->assertStatus(403);

        $this->actingAs($creador)
            ->put("/no-conformidades/{$nc->id}", $this->datosValidos(['descripcion' => 'Descripción corregida y más larga.']))
            ->assertRedirect();

        $this->assertSame('Descripción corregida y más larga.', $nc->fresh()->descripcion);
    }

    /** ⚠️ §4.8: "una NC cerrada quedará bloqueada para edición". */
    public function test_una_nc_cerrada_no_se_puede_editar_ni_por_calidad(): void
    {
        $nc = $this->nc(['estado' => 'cerrada']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}", $this->datosValidos())
            ->assertStatus(403);
    }

    public function test_super_admin_puede_todo_via_gate_before(): void
    {
        $nc = $this->nc(['estado' => 'pendiente_aprobacion']);

        $this->actingAs($this->superAdmin())
            ->post("/no-conformidades/{$nc->id}/aprobar")
            ->assertRedirect();

        $this->assertSame('abierta', $nc->fresh()->estado);
    }

    // ── Bitácora (§7) ───────────────────────────────────────────────────────

    public function test_cada_cambio_de_estado_deja_entrada_en_la_bitacora(): void
    {
        $nc = $this->nc(['estado' => 'pendiente_aprobacion']);

        $this->actingAs($this->userWith('nc.view', 'nc.aprobar'))
            ->post("/no-conformidades/{$nc->id}/aprobar");

        $estado = $nc->historial()->where('accion', NonConformityHistory::ACCION_ESTADO)->sole();

        $this->assertSame('Pendiente de aprobación', $estado->cambios['de']);
        $this->assertSame('Abierta / En investigación', $estado->cambios['a']);

        // La aprobación además deja su propia entrada, con el número asignado.
        $aprobacion = $nc->historial()->where('accion', NonConformityHistory::ACCION_APROBACION)->sole();
        $this->assertSame('NC-0001-26', $aprobacion->cambios['numero']);
    }

    public function test_la_bitacora_es_inmutable(): void
    {
        $nc = $this->nc();
        $entrada = $nc->historial()->create(['accion' => NonConformityHistory::ACCION_SISTEMA]);

        $this->expectException(\RuntimeException::class);
        $entrada->update(['nota' => 'editada']);
    }

    public function test_la_bitacora_no_se_puede_borrar(): void
    {
        $nc = $this->nc();
        $entrada = $nc->historial()->create(['accion' => NonConformityHistory::ACCION_SISTEMA]);

        $this->expectException(\RuntimeException::class);
        $entrada->delete();
    }

    // ── Vínculo con observaciones (§5) ──────────────────────────────────────

    public function test_vincular_observaciones_las_registra_en_la_bitacora_por_numero(): void
    {
        $creador = $this->userWith('nc.view', 'nc.create');
        $nc = $this->nc(['creado_por' => $creador->id]);

        $observacion = Observacion::create([
            'numero' => '0007-26', 'anio' => 2026, 'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion', 'titulo' => 'Título', 'descripcion' => 'Descripción',
        ]);

        $this->actingAs($creador)
            ->put("/no-conformidades/{$nc->id}", $this->datosValidos(['observaciones' => [$observacion->id]]))
            ->assertRedirect();

        $this->assertTrue($nc->observaciones()->whereKey($observacion->id)->exists());

        // Se guardan los NÚMEROS, no los ids: la bitácora es un registro histórico.
        $entrada = $nc->historial()->where('accion', NonConformityHistory::ACCION_OBSERVACIONES)->sole();
        $this->assertSame(['0007-26'], $entrada->cambios['sumadas']);
    }

    // ── Dashboard ───────────────────────────────────────────────────────────

    public function test_el_dashboard_cuenta_las_nc_abiertas_sin_las_terminales(): void
    {
        $this->nc(['estado' => 'borrador']);
        $this->nc(['estado' => 'abierta']);
        $this->nc(['estado' => 'cerrada']);
        $this->nc(['estado' => 'rechazada']);

        $this->actingAs($this->userWith('nc.view'))->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.nc', 4)
                ->where('stats.ncAbiertas', 2)
            );
    }

    // ── Investigación (§4.4) ────────────────────────────────────────────────

    public function test_la_investigacion_se_puede_guardar_incompleta(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/investigacion", ['investigacion' => 'Arranqué a revisar el lote.'])
            ->assertRedirect();

        $this->assertSame('Arranqué a revisar el lote.', $nc->fresh()->investigacion);
    }

    /** ⚠️ §4.4: la regla dura es investigación + análisis de causa, nada más. */
    public function test_no_se_avanza_a_plan_de_accion_sin_investigacion_y_causa(): void
    {
        $nc = $this->nc(['estado' => 'abierta', 'investigacion' => 'Algo investigué.']);
        $calidad = $this->userWith('nc.view', 'nc.gestionar');

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/plan-accion")
            ->assertSessionHasErrors('investigacion');

        $this->assertSame('abierta', $nc->fresh()->estado);

        $nc->update(['causa_raiz' => 'Falla de calibración del equipo.']);

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/plan-accion")
            ->assertRedirect();

        $this->assertSame('plan_accion', $nc->fresh()->estado);
    }

    /**
     * Los campos que la etapa pide pero que el instructivo NO pone como
     * condición para avanzar (alcance, afectados, riesgo) no deben trabar.
     */
    public function test_los_campos_no_bloqueantes_no_traban_el_avance(): void
    {
        $nc = $this->nc([
            'estado' => 'abierta',
            'investigacion' => 'Investigación hecha.',
            'causa_raiz' => 'Causa identificada.',
        ]);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/plan-accion")
            ->assertRedirect();

        $this->assertSame('plan_accion', $nc->fresh()->estado);
    }

    public function test_las_6m_guardan_solo_los_factores_del_catalogo_con_texto(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/investigacion", [
                'causa_raiz_factores' => [
                    'hombre' => 'Falta de capacitación.',
                    'maquina' => '',
                    'inventado' => 'Esto no existe en el catálogo.',
                ],
            ])
            ->assertRedirect();

        $this->assertSame(['hombre' => 'Falta de capacitación.'], $nc->fresh()->causa_raiz_factores);
    }

    /** `null` (no se analizó) y `[]` (se analizó, nada aplicó) se leen distinto. */
    public function test_sin_ningun_factor_cargado_queda_en_null(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/investigacion", ['causa_raiz_factores' => ['hombre' => '']])
            ->assertRedirect();

        $this->assertNull($nc->fresh()->causa_raiz_factores);
    }

    // ── Plan de acción (§4.5) ───────────────────────────────────────────────

    public function test_se_cargan_varias_acciones_en_una_nc(): void
    {
        $nc = $this->nc(['estado' => 'plan_accion']);
        $calidad = $this->userWith('nc.view', 'nc.gestionar');

        foreach (['Recalibrar el equipo.', 'Actualizar el procedimiento.'] as $descripcion) {
            $this->actingAs($calidad)
                ->post("/no-conformidades/{$nc->id}/acciones", [
                    'descripcion' => $descripcion,
                    'responsable_id' => $calidad->id,
                    'fecha_prevista' => '2026-10-15',
                ])
                ->assertRedirect();
        }

        $this->assertSame(2, $nc->acciones()->count());
        $this->assertSame('pendiente', $nc->acciones()->first()->estado);
    }

    public function test_una_accion_exige_descripcion_responsable_y_fecha(): void
    {
        $nc = $this->nc(['estado' => 'plan_accion']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/acciones", ['descripcion' => 'Algo'])
            ->assertSessionHasErrors(['descripcion', 'responsable_id', 'fecha_prevista']);
    }

    public function test_no_se_avanza_a_implementacion_sin_ninguna_accion(): void
    {
        $nc = $this->nc(['estado' => 'plan_accion']);
        $calidad = $this->userWith('nc.view', 'nc.gestionar');

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/implementacion")
            ->assertSessionHasErrors('acciones');

        $nc->acciones()->create([
            'descripcion' => 'Recalibrar el equipo.',
            'responsable_id' => $calidad->id,
            'fecha_prevista' => '2026-10-15',
        ]);

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/implementacion")
            ->assertRedirect();

        $this->assertSame('en_implementacion', $nc->fresh()->estado);
    }

    /** Una acción de otra NC no se toca desde esta: lo fuerza `scopeBindings()`. */
    public function test_no_se_puede_tocar_una_accion_de_otra_nc(): void
    {
        $calidad = $this->userWith('nc.view', 'nc.gestionar');
        $propia = $this->nc(['estado' => 'plan_accion']);
        $ajena = $this->nc(['estado' => 'plan_accion']);

        $accionAjena = $ajena->acciones()->create([
            'descripcion' => 'Acción de otra NC.',
            'responsable_id' => $calidad->id,
            'fecha_prevista' => '2026-10-15',
        ]);

        $this->actingAs($calidad)
            ->delete("/no-conformidades/{$propia->id}/acciones/{$accionAjena->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('non_conformity_actions', ['id' => $accionAjena->id]);
    }

    public function test_la_investigacion_y_el_plan_son_de_calidad_no_del_creador(): void
    {
        $creador = $this->userWith('nc.view', 'nc.create');
        $nc = $this->nc(['estado' => 'abierta', 'creado_por' => $creador->id]);

        $this->actingAs($creador)
            ->put("/no-conformidades/{$nc->id}/investigacion", ['investigacion' => 'Intento.'])
            ->assertStatus(403);
    }

    // ── Implementación (§4.6) ───────────────────────────────────────────────

    /** @param array<string, mixed> $attrs */
    private function accion(NoConformidad $nc, array $attrs = []): NonConformityAction
    {
        return $nc->acciones()->create(array_merge([
            'descripcion' => 'Recalibrar el equipo.',
            'responsable_id' => User::factory()->create()->id,
            'fecha_prevista' => '2026-10-15',
        ], $attrs));
    }

    /** Una NC lista para cerrarse, para no repetir el armado en cada test. */
    private function ncVerificada(array $attrs = []): NoConformidad
    {
        $nc = $this->nc(array_merge([
            'estado' => 'verificacion_eficacia',
            'investigacion' => 'Investigación completa.',
            'causa_raiz' => 'Causa identificada.',
            'resultado_eficacia' => 'eficaz',
        ], $attrs));

        $this->accion($nc, ['estado' => 'completada', 'fecha_real' => '2026-10-10']);

        $nc->attachments()->create([
            'path' => 'no-conformidades/NC-0001-26/evidencia.pdf',
            'original_name' => 'evidencia.pdf',
        ]);

        return $nc;
    }

    public function test_completar_una_accion_sin_fecha_real_sella_hoy(): void
    {
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $accion = $this->accion($nc);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", [
                'estado' => 'completada',
                'avance' => 'Se recalibró y se archivó el certificado.',
            ])
            ->assertRedirect();

        $accion->refresh();

        $this->assertSame('completada', $accion->estado);
        $this->assertSame(now()->toDateString(), $accion->fecha_real->toDateString());
    }

    /** §4.6: cancelar una acción exige justificación. */
    public function test_cancelar_una_accion_exige_motivo(): void
    {
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $accion = $this->accion($nc);
        $calidad = $this->userWith('nc.view', 'nc.gestionar');

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", ['estado' => 'cancelada'])
            ->assertSessionHasErrors('motivo_cancelacion');

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", [
                'estado' => 'cancelada',
                'motivo_cancelacion' => 'El equipo se dio de baja, la acción no aplica.',
            ])
            ->assertRedirect();

        $this->assertSame('cancelada', $accion->fresh()->estado);
    }

    /** ⚠️ `vencida` es derivado, no se elige a mano. */
    public function test_no_se_puede_marcar_una_accion_como_vencida_a_mano(): void
    {
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $accion = $this->accion($nc);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", ['estado' => 'vencida'])
            ->assertSessionHasErrors('estado');
    }

    public function test_no_se_verifica_la_eficacia_con_acciones_pendientes(): void
    {
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $pendiente = $this->accion($nc, ['estado' => 'en_curso']);
        $calidad = $this->userWith('nc.view', 'nc.gestionar');

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/verificacion")
            ->assertSessionHasErrors('acciones');

        $pendiente->update(['estado' => 'completada']);

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/verificacion")
            ->assertRedirect();

        $this->assertSame('verificacion_eficacia', $nc->fresh()->estado);
    }

    /** Una acción cancelada con justificación no es una pendiente. */
    public function test_una_accion_cancelada_no_traba_la_verificacion(): void
    {
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $this->accion($nc, ['estado' => 'completada']);
        $this->accion($nc, ['estado' => 'cancelada', 'motivo_cancelacion' => 'No aplica.']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/verificacion")
            ->assertRedirect();

        $this->assertSame('verificacion_eficacia', $nc->fresh()->estado);
    }

    // ── Contención (sección 3 del formulario) ───────────────────────────────

    public function test_se_cargan_varias_acciones_de_contencion(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);
        $calidad = $this->userWith('nc.view', 'nc.gestionar');

        foreach (['Se frena la línea.', 'Se bloquea el lote en depósito.'] as $accion) {
            $this->actingAs($calidad)
                ->post("/no-conformidades/{$nc->id}/contencion", [
                    'fecha' => '2026-03-10',
                    'accion' => $accion,
                ])
                ->assertRedirect();
        }

        $this->assertSame(2, $nc->contenciones()->count());
        $this->assertDatabaseHas('non_conformity_history', [
            'non_conformity_id' => $nc->id,
            'accion' => NonConformityHistory::ACCION_CONTENCION,
        ]);
    }

    /**
     * ⚠️ La regresión que motivó pasar de "guardar la lista entera" a fila por
     * fila el 25/9/2026: con el guardado viejo, el responsable de una fila
     * mandaba el array completo y **reescribía las filas de los demás**.
     */
    public function test_guardar_una_contencion_no_toca_las_de_los_demas(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);
        $ajena = $nc->contenciones()->create(['fecha' => '2026-03-10', 'accion' => 'La de otro.']);
        $propia = $nc->contenciones()->create(['accion' => 'La mía.']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/contencion/{$propia->id}", [
                'accion' => 'La mía, corregida.',
                'fecha' => '2026-03-12',
            ])
            ->assertRedirect();

        $this->assertSame('La mía, corregida.', $propia->fresh()->accion);
        $this->assertSame('La de otro.', $ajena->fresh()->accion);
    }

    public function test_una_contencion_sin_accion_no_se_guarda(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/contencion", ['fecha' => '2026-03-10'])
            ->assertSessionHasErrors('accion');
    }

    public function test_no_se_toca_una_contencion_de_otra_nc(): void
    {
        $propia = $this->nc(['estado' => 'abierta']);
        $ajena = $this->nc(['estado' => 'abierta']);
        $contencionAjena = $ajena->contenciones()->create(['accion' => 'De otra NC.']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->delete("/no-conformidades/{$propia->id}/contencion/{$contencionAjena->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('non_conformity_containments', ['id' => $contencionAjena->id]);
    }

    // ── Delegación: responsable del caso y de cada renglón ──────────────────

    /**
     * ⚠️ El cambio del 25/9/2026: Calidad hace seguimiento y control, pero **no
     * es responsable de todas las NC**. Quien tiene el caso asignado lo gestiona
     * entero sin tener `nc.gestionar`.
     */
    public function test_el_responsable_del_caso_gestiona_sin_permiso_global(): void
    {
        $responsable = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'abierta', 'responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->put("/no-conformidades/{$nc->id}/investigacion", [
                'investigacion' => 'Se revisó el equipo.',
                'causa_raiz' => 'Calibración vencida.',
            ])
            ->assertRedirect();

        $this->actingAs($responsable)
            ->post("/no-conformidades/{$nc->id}/plan-accion")
            ->assertRedirect();

        $this->assertSame('plan_accion', $nc->fresh()->estado);
    }

    /** El cierre también: es el dueño del caso, no Calidad (decisión del cliente). */
    public function test_el_responsable_del_caso_puede_cerrar(): void
    {
        $responsable = $this->userWith('nc.view');
        $nc = $this->ncVerificada(['responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->post("/no-conformidades/{$nc->id}/cerrar", ['resultado_final' => 'Resuelto y verificado.'])
            ->assertRedirect();

        $this->assertSame('cerrada', $nc->fresh()->estado);
    }

    public function test_alguien_sin_asignacion_ni_permiso_no_gestiona(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view'))
            ->put("/no-conformidades/{$nc->id}/investigacion", ['investigacion' => 'Intento.'])
            ->assertStatus(403);
    }

    /** Pasar la posta: el responsable actual deriva sin pasar por Calidad. */
    public function test_el_responsable_puede_pasar_la_posta(): void
    {
        Notification::fake();

        $actual = $this->userWith('nc.view');
        $siguiente = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'abierta', 'responsable_id' => $actual->id]);

        $this->actingAs($actual)
            ->post("/no-conformidades/{$nc->id}/responsable", ['responsable_id' => $siguiente->id])
            ->assertRedirect();

        $this->assertSame($siguiente->id, $nc->fresh()->responsable_id);
        Notification::assertSentTo($siguiente, NoConformidadAsignadaNotification::class);
        // ⚠️ Quien hizo la asignación no recibe aviso de su propio clic.
        Notification::assertNotSentTo($actual, NoConformidadAsignadaNotification::class);
    }

    public function test_un_tercero_no_puede_reasignar_el_caso(): void
    {
        $responsable = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'abierta', 'responsable_id' => $responsable->id]);

        $this->actingAs($this->userWith('nc.view'))
            ->post("/no-conformidades/{$nc->id}/responsable", ['responsable_id' => null])
            ->assertStatus(403);
    }

    /** Autonomía completa sobre el propio renglón: avance, edición y cancelación. */
    public function test_el_responsable_de_una_accion_la_gestiona_entera(): void
    {
        $peon = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $accion = $this->accion($nc, ['responsable_id' => $peon->id]);

        $this->actingAs($peon)
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", [
                'estado' => 'en_curso',
                'avance' => 'Se pidió el turno de calibración.',
            ])
            ->assertRedirect();

        $this->actingAs($peon)
            ->put("/no-conformidades/{$nc->id}/acciones/{$accion->id}", [
                'descripcion' => 'Recalibrar el equipo y dejar certificado.',
                'responsable_id' => $peon->id,
                'fecha_prevista' => '2026-10-20',
            ])
            ->assertRedirect();

        $this->assertSame('en_curso', $accion->fresh()->estado);
        $this->assertSame('Recalibrar el equipo y dejar certificado.', $accion->fresh()->descripcion);
    }

    public function test_el_responsable_de_una_accion_no_toca_la_de_otro(): void
    {
        $peon = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $ajena = $this->accion($nc);

        $this->actingAs($peon)
            ->post("/no-conformidades/{$nc->id}/acciones/{$ajena->id}/avance", ['estado' => 'completada'])
            ->assertStatus(403);
    }

    /** Mover la NC de etapa sigue siendo del dueño del caso, no del peón. */
    public function test_el_responsable_de_una_accion_no_mueve_la_nc_de_etapa(): void
    {
        $peon = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $this->accion($nc, ['responsable_id' => $peon->id, 'estado' => 'completada']);

        $this->actingAs($peon)
            ->post("/no-conformidades/{$nc->id}/verificacion")
            ->assertStatus(403);
    }

    /**
     * ⚠️ Borrar **no** es del responsable del renglón: su salida es cancelar con
     * justificación, que deja rastro. Ver `NonConformityActionPolicy::eliminar()`.
     */
    public function test_el_responsable_de_una_accion_no_la_borra_pero_si_la_cancela(): void
    {
        $peon = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'en_implementacion']);
        $accion = $this->accion($nc, ['responsable_id' => $peon->id]);

        $this->actingAs($peon)
            ->delete("/no-conformidades/{$nc->id}/acciones/{$accion->id}")
            ->assertStatus(403);

        $this->actingAs($peon)
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", [
                'estado' => 'cancelada',
                'motivo_cancelacion' => 'El equipo se dio de baja.',
            ])
            ->assertRedirect();

        $this->assertSame('cancelada', $accion->fresh()->estado);
    }

    public function test_una_nc_cerrada_bloquea_tambien_al_responsable_del_renglon(): void
    {
        $peon = $this->userWith('nc.view');
        $nc = $this->ncVerificada(['estado' => 'cerrada']);
        $accion = $nc->acciones()->first();
        $accion->update(['responsable_id' => $peon->id]);

        $this->actingAs($peon)
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", ['estado' => 'en_curso'])
            ->assertStatus(403);
    }

    public function test_asignar_una_accion_le_avisa_a_quien_queda_a_cargo(): void
    {
        Notification::fake();

        $peon = $this->userWith('nc.view');
        $calidad = $this->userWith('nc.view', 'nc.gestionar');
        $nc = $this->nc(['estado' => 'plan_accion']);

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/acciones", [
                'descripcion' => 'Actualizar el procedimiento.',
                'responsable_id' => $peon->id,
                'fecha_prevista' => '2026-10-15',
            ])
            ->assertRedirect();

        Notification::assertSentTo($peon, AccionAsignadaNotification::class);
        Notification::assertNotSentTo($calidad, AccionAsignadaNotification::class);
    }

    // ── Verificación de eficacia (§4.7) ─────────────────────────────────────

    /**
     * ⚠️ La regla que cambió el 24/9/2026: ineficaz **no** reabre la NC.
     *
     * §4.7 del instructivo decía que "deberá regresar" a investigación, y así
     * estaba implementado. El formulario en papel tiene una celda "Nuevo desvío
     * N°" al lado del resultado, y el cliente confirmó ésa: se abre otro desvío
     * y éste se cierra. Si este test vuelve a esperar `abierta`, alguien
     * deshizo la decisión sin querer.
     */
    public function test_una_verificacion_ineficaz_no_reabre_la_nc(): void
    {
        $nc = $this->nc(['estado' => 'verificacion_eficacia']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/verificacion", [
                'resultado_eficacia' => 'ineficaz',
                'observaciones_verificacion' => 'El problema volvió a repetirse en el lote siguiente.',
            ])
            ->assertRedirect();

        $this->assertSame('verificacion_eficacia', $nc->fresh()->estado);

        $entrada = $nc->historial()->where('accion', NonConformityHistory::ACCION_VERIFICACION)->sole();
        $this->assertSame('Ineficaz', $entrada->cambios['resultado']);
    }

    /** El "Nuevo desvío N°" de la sección 7: nace en borrador y sin número. */
    public function test_una_nc_ineficaz_deriva_en_un_desvio_nuevo(): void
    {
        $nc = $this->ncVerificada(['resultado_eficacia' => 'ineficaz', 'numero' => 'NC-0004-26']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/derivar")
            ->assertRedirect();

        $nueva = NoConformidad::where('reemplaza_a_id', $nc->id)->sole();

        $this->assertSame('borrador', $nueva->estado);
        $this->assertNull($nueva->numero);
        // Hereda el encuadre, nunca la investigación: si esas acciones hubieran
        // servido, no estaríamos abriendo un desvío nuevo.
        $this->assertSame($nc->sector_id, $nueva->sector_id);
        $this->assertNull($nueva->investigacion);
        $this->assertStringContainsString('NC-0004-26', $nueva->motivo);
        // Y la vieja ahora sabe cuál la reemplaza.
        $this->assertSame($nueva->id, $nc->fresh()->reemplazadaPor->id);
    }

    public function test_no_se_deriva_si_la_verificacion_no_dio_ineficaz(): void
    {
        $nc = $this->ncVerificada();

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/derivar")
            ->assertSessionHasErrors('estado');
    }

    public function test_no_se_deriva_dos_veces(): void
    {
        $nc = $this->ncVerificada(['resultado_eficacia' => 'ineficaz']);
        $calidad = $this->userWith('nc.view', 'nc.gestionar');

        $this->actingAs($calidad)->post("/no-conformidades/{$nc->id}/derivar")->assertRedirect();

        $this->actingAs($calidad)
            ->post("/no-conformidades/{$nc->id}/derivar")
            ->assertSessionHasErrors('estado');

        $this->assertSame(1, NoConformidad::where('reemplaza_a_id', $nc->id)->count());
    }

    /**
     * ⚠️ Una NC ineficaz **sí se cierra**, pero recién cuando existe el desvío
     * que la reemplaza. Sin esta regla quedaría colgada para siempre en
     * verificación, y el formulario tiene fecha y responsable de cierre para
     * los dos resultados.
     */
    public function test_una_nc_ineficaz_recien_cierra_con_el_desvio_nuevo_abierto(): void
    {
        $nc = $this->ncVerificada(['resultado_eficacia' => 'ineficaz']);

        $this->assertFalse($nc->puedeCerrarse());

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/derivar")
            ->assertRedirect();

        $this->assertTrue($nc->fresh()->puedeCerrarse());
    }

    /** "Pendiente de evaluación" no mueve la NC: todavía no se puede medir. */
    public function test_pendiente_de_evaluacion_deja_la_nc_en_verificacion(): void
    {
        $nc = $this->nc(['estado' => 'verificacion_eficacia']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/verificacion", ['resultado_eficacia' => 'pendiente_evaluacion'])
            ->assertRedirect();

        $this->assertSame('verificacion_eficacia', $nc->fresh()->estado);
    }

    public function test_no_se_verifica_antes_de_llegar_a_esa_etapa(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/verificacion", ['resultado_eficacia' => 'eficaz'])
            ->assertSessionHasErrors('estado');
    }

    // ── Cierre (§4.8) ───────────────────────────────────────────────────────

    public function test_se_cierra_cuando_se_cumplen_las_siete_condiciones(): void
    {
        $nc = $this->ncVerificada();

        $this->assertTrue($nc->puedeCerrarse());

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/cerrar", [
                'resultado_final' => 'El problema no volvió a repetirse en tres lotes.',
            ])
            ->assertRedirect();

        $nc->refresh();

        $this->assertSame('cerrada', $nc->estado);
        $this->assertNotNull($nc->cerrada_at);
        $this->assertNotNull($nc->cerrada_por);
    }

    public function test_no_se_cierra_si_la_verificacion_no_dio_eficaz(): void
    {
        $nc = $this->ncVerificada(['resultado_eficacia' => 'pendiente_evaluacion']);

        $this->assertFalse($nc->puedeCerrarse());

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/cerrar", ['resultado_final' => 'Cierro igual.'])
            ->assertSessionHasErrors('cierre');

        $this->assertSame('verificacion_eficacia', $nc->fresh()->estado);
    }

    /**
     * ⚠️ Fija la regresión del 28/9/2026: las evidencias **dejaron de ser
     * obligatorias** para cerrar. El cliente lo confirmó por escrito ("NO, no es
     * obligatorio"): hay desvíos que se resuelven sin nada que adjuntar, y
     * exigirlo obligaba a subir un archivo de relleno para poder cerrar.
     *
     * Sigue avisando, eso sí: por eso también se comprueba la advertencia.
     */
    public function test_se_cierra_sin_evidencias_adjuntas_pero_avisa(): void
    {
        $nc = $this->ncVerificada();
        $nc->attachments()->delete();

        $this->assertTrue($nc->fresh()->puedeCerrarse());
        $this->assertContains('No hay ninguna evidencia adjunta.', $nc->fresh()->advertenciasDeCierre());

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/cerrar", ['resultado_final' => 'Resuelto.'])
            ->assertRedirect();

        $this->assertSame('cerrada', $nc->fresh()->estado);
    }

    /**
     * ⚠️ Fija el otro medio de la misma regresión, y es el que evita un
     * **bloqueo mutuo**: desde que una observación derivada se cierra a mano y
     * por separado, exigir que esté cerrada para cerrar el desvío dejaba a los
     * dos esperándose para siempre.
     */
    public function test_se_cierra_con_una_observacion_vinculada_abierta_pero_avisa(): void
    {
        $nc = $this->ncVerificada();

        $observacion = Observacion::create([
            'numero' => '0009-26', 'anio' => 2026, 'tipo' => 'falla_producto',
            'estado' => 'en_proceso', 'titulo' => 'Título', 'descripcion' => 'Descripción',
        ]);
        $nc->observaciones()->attach($observacion->id);

        $this->assertTrue($nc->fresh()->puedeCerrarse());
        $this->assertContains('Queda 1 observación vinculada sin cerrar.', $nc->fresh()->advertenciasDeCierre());

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/cerrar", ['resultado_final' => 'Resuelto.'])
            ->assertRedirect();

        $this->assertSame('cerrada', $nc->fresh()->estado);

        // Y la observación sigue abierta: cerrar el desvío no la cierra sola.
        $this->assertSame('en_proceso', $observacion->fresh()->estado);
    }

    /** Sin nada que señalar, no se inventan avisos. */
    public function test_sin_advertencias_cuando_esta_todo_en_orden(): void
    {
        $this->assertSame([], $this->ncVerificada()->advertenciasDeCierre());
    }

    public function test_el_cierre_exige_resultado_final(): void
    {
        $nc = $this->ncVerificada();

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/cerrar", [])
            ->assertSessionHasErrors('resultado_final');
    }

    /** ⚠️ §4.8: una NC cerrada queda bloqueada para edición. */
    public function test_una_nc_cerrada_no_se_edita_ni_se_le_tocan_las_acciones(): void
    {
        $nc = $this->ncVerificada(['estado' => 'cerrada']);
        $accion = $nc->acciones()->first();

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/acciones/{$accion->id}/avance", ['estado' => 'pendiente'])
            ->assertStatus(403);
    }

    // ── Reapertura y cancelación (§4.8 y §6) ────────────────────────────────

    public function test_solo_super_admin_reabre_y_con_motivo(): void
    {
        $nc = $this->ncVerificada(['estado' => 'cerrada']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/reabrir", ['motivo_decision' => 'Volvió a pasar.'])
            ->assertStatus(403);

        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post("/no-conformidades/{$nc->id}/reabrir", [])
            ->assertSessionHasErrors('motivo_decision');

        $this->actingAs($admin)
            ->post("/no-conformidades/{$nc->id}/reabrir", ['motivo_decision' => 'El problema volvió a repetirse.'])
            ->assertRedirect();

        $nc->refresh();

        $this->assertSame('abierta', $nc->estado);
        // ⚠️ Reabrir limpia el cierre: si no, un segundo cierre conservaría la
        // fecha del primero y mentiría.
        $this->assertNull($nc->cerrada_at);
        $this->assertNull($nc->cerrada_por);
    }

    public function test_cancelar_es_de_super_admin_y_con_motivo(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/cancelar", ['motivo_decision' => 'Duplicada.'])
            ->assertStatus(403);

        $this->actingAs($this->superAdmin())
            ->post("/no-conformidades/{$nc->id}/cancelar", ['motivo_decision' => 'Duplicada de la NC-0003-26.'])
            ->assertRedirect();

        $this->assertSame('cancelada', $nc->fresh()->estado);
    }

    public function test_no_se_reabre_algo_que_no_esta_cerrado(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->superAdmin())
            ->post("/no-conformidades/{$nc->id}/reabrir", ['motivo_decision' => 'Un motivo cualquiera.'])
            ->assertSessionHasErrors('estado');
    }

    // ── Vínculo con observaciones (§5) ──────────────────────────────────────

    /** @param array<string, mixed> $attrs */
    private function observacion(array $attrs = []): Observacion
    {
        return Observacion::create(array_merge([
            'numero' => '0042-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'origen' => 'externa',
            'estado' => 'en_proceso',
            'titulo' => 'El envase llegó roto',
            'descripcion' => 'Descripción del reclamo.',
            'sector_id' => $this->sector()->id,
        ], $attrs));
    }

    public function test_derivar_una_observacion_abre_un_desvio_vinculado(): void
    {
        $usuario = $this->userWith('nc.create', 'nc.view', 'observaciones.view');
        $observacion = $this->observacion(['responsable_id' => $usuario->id]);

        $this->actingAs($usuario)
            ->post("/observaciones/{$observacion->id}/derivar-a-nc")
            ->assertRedirect();

        $nc = NoConformidad::latest('id')->first();

        $this->assertNotNull($nc);
        $this->assertSame('borrador', $nc->estado);
        // Nace sin número, como cualquier otra: el número llega al aprobarla.
        $this->assertNull($nc->numero);
        // Un reclamo externo encuadra como desvío externo.
        $this->assertSame('externo', $nc->tipo_desvio);
        $this->assertTrue($nc->observaciones()->whereKey($observacion->id)->exists());

        // ⚠️ La observación queda derivada pero NO cerrada: se cierra a mano.
        $this->assertSame('derivada_nc', $observacion->fresh()->estado);
    }

    /**
     * ⚠️ El bloqueo mutuo que evita este cambio: la observación derivada sigue
     * abierta, y aun así el desvío tiene que poder cerrarse.
     */
    public function test_una_observacion_derivada_no_traba_el_cierre_del_desvio(): void
    {
        $nc = $this->ncVerificada();
        $nc->observaciones()->attach($this->observacion(['estado' => 'derivada_nc'])->id);

        $this->assertTrue($nc->fresh()->puedeCerrarse());
    }

    /**
     * ⚠️ Vincular es del **responsable del caso**, sin `nc.gestionar`. Va bajo
     * `gestionar` de la Policy y no bajo `update`, que es más angosto: con
     * `update` este test daría 403, que es el bug que fija.
     */
    public function test_el_responsable_del_caso_vincula_observaciones_sin_permiso_global(): void
    {
        $responsable = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'abierta', 'responsable_id' => $responsable->id]);
        $observacion = $this->observacion();

        $this->actingAs($responsable)
            ->put("/no-conformidades/{$nc->id}/observaciones", ['observaciones' => [$observacion->id]])
            ->assertRedirect();

        $this->assertTrue($nc->observaciones()->whereKey($observacion->id)->exists());

        // Y también puede desvincular.
        $this->actingAs($responsable)
            ->put("/no-conformidades/{$nc->id}/observaciones", ['observaciones' => []])
            ->assertRedirect();

        $this->assertSame(0, $nc->observaciones()->count());
    }

    public function test_vincular_observaciones_deja_rastro_en_la_bitacora(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);
        $observacion = $this->observacion();

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/observaciones", ['observaciones' => [$observacion->id]]);

        $entrada = $nc->historial()
            ->where('accion', NonConformityHistory::ACCION_OBSERVACIONES)
            ->first();

        $this->assertNotNull($entrada);
        // Se guardan los NÚMEROS, no los ids: la bitácora es histórica.
        $this->assertSame(['0042-26'], $entrada->cambios['sumadas']);
    }

    public function test_quien_no_gestiona_el_caso_no_vincula_observaciones(): void
    {
        $nc = $this->nc(['estado' => 'abierta']);

        $this->actingAs($this->userWith('nc.view'))
            ->put("/no-conformidades/{$nc->id}/observaciones", ['observaciones' => []])
            ->assertStatus(403);
    }

    public function test_el_buscador_de_observaciones_excluye_las_ya_vinculadas(): void
    {
        $this->observacion(['numero' => '0042-26']);
        $otra = $this->observacion(['numero' => '0043-26']);

        $respuesta = $this->actingAs($this->userWith('observaciones.view'))
            ->getJson('/observaciones/buscar?q=0042&excluir[]='.$otra->id)
            ->assertOk();

        $this->assertCount(1, $respuesta->json());
        $this->assertSame('0042-26', $respuesta->json('0.numero'));
    }

    // ── Fecha de verificación de eficacia ───────────────────────────────────

    public function test_el_responsable_del_caso_carga_la_fecha_de_verificacion(): void
    {
        $responsable = $this->userWith('nc.view');
        $nc = $this->nc(['estado' => 'plan_accion', 'responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->put("/no-conformidades/{$nc->id}/verificacion-prevista", [
                'fecha_verificacion_prevista' => '2026-11-30',
            ])
            ->assertRedirect();

        $this->assertSame('2026-11-30', $nc->fresh()->fecha_verificacion_prevista->toDateString());
    }

    /**
     * ⚠️ Mover la fecha **limpia la marca de avisado**: si se corre para
     * adelante, el recordatorio tiene que volver a salir en la fecha nueva. Sin
     * esto, cambiar la fecha dejaba el aviso apagado para siempre.
     */
    public function test_cambiar_la_fecha_de_verificacion_reabre_el_aviso(): void
    {
        $nc = $this->nc([
            'estado' => 'plan_accion',
            'fecha_verificacion_prevista' => '2026-10-01',
            'verificacion_avisada_at' => now(),
        ]);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->put("/no-conformidades/{$nc->id}/verificacion-prevista", [
                'fecha_verificacion_prevista' => '2026-12-15',
            ]);

        $this->assertNull($nc->fresh()->verificacion_avisada_at);
    }

    /** Un término corto es "todavía no hay nada que sugerir", no un error. */
    public function test_el_buscador_devuelve_vacio_con_un_termino_corto(): void
    {
        $this->observacion();

        $this->actingAs($this->userWith('observaciones.view'))
            ->getJson('/observaciones/buscar?q=0')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_el_alta_vincula_las_observaciones_elegidas(): void
    {
        $observacion = $this->observacion();

        $this->actingAs($this->userWith('nc.create', 'nc.view'))
            ->post('/no-conformidades', $this->datosValidos([
                'observaciones' => [$observacion->id],
            ]))
            ->assertRedirect();

        $nc = NoConformidad::latest('id')->first();

        $this->assertTrue($nc->observaciones()->whereKey($observacion->id)->exists());
        $this->assertSame(
            ['0042-26'],
            $nc->historial()->where('accion', NonConformityHistory::ACCION_OBSERVACIONES)->value('cambios')['sumadas'],
        );
    }

    /**
     * ⚠️ El agujero que se tapó el 29/9/2026: `store()` y `update()` leían
     * `observaciones` **crudo** del request y lo mandaban a `sync()`, sin pasar
     * por ninguna regla. Ahora es un error de validación como cualquier otro.
     */
    public function test_el_alta_rechaza_una_observacion_inexistente(): void
    {
        $this->actingAs($this->userWith('nc.create', 'nc.view'))
            ->post('/no-conformidades', $this->datosValidos(['observaciones' => [99999]]))
            ->assertSessionHasErrors('observaciones.0');

        $this->assertSame(0, NoConformidad::count());
    }

    // ── Contención antes de la aprobación (§3) ──────────────────────────────

    /**
     * ⚠️ Lo que habilitó el cambio del 29/9/2026: quien carga un desvío puede
     * escribir la acción inmediata que él mismo tomó, **sin tener
     * `nc.gestionar`** y sin esperar la aprobación. Antes no podía, que era
     * exactamente al revés de cómo pasa en la planta.
     */
    public function test_quien_creo_el_desvio_carga_contencion_antes_de_aprobar(): void
    {
        $creador = $this->userWith('nc.view', 'nc.create');

        foreach (['borrador', 'pendiente_aprobacion'] as $estado) {
            $nc = $this->nc(['estado' => $estado, 'creado_por' => $creador->id]);

            $this->actingAs($creador)
                ->post("/no-conformidades/{$nc->id}/contencion", [
                    'fecha' => '2026-09-29',
                    'accion' => 'Se segregó el lote afectado.',
                ])
                ->assertRedirect();

            $this->assertSame(1, $nc->contenciones()->count(), "falló en {$estado}");
        }
    }

    public function test_quien_creo_el_desvio_edita_y_borra_su_contencion_antes_de_aprobar(): void
    {
        $creador = $this->userWith('nc.view', 'nc.create');
        $nc = $this->nc(['estado' => 'borrador', 'creado_por' => $creador->id]);
        $fila = $nc->contenciones()->create(['fecha' => '2026-09-29', 'accion' => 'Se segregó el lote.']);

        $this->actingAs($creador)
            ->put("/no-conformidades/{$nc->id}/contencion/{$fila->id}", [
                'fecha' => '2026-09-29',
                'accion' => 'Se segregó el lote y se avisó a Depósito.',
            ])
            ->assertRedirect();

        $this->assertSame('Se segregó el lote y se avisó a Depósito.', $fila->fresh()->accion);

        $this->actingAs($creador)
            ->delete("/no-conformidades/{$nc->id}/contencion/{$fila->id}")
            ->assertRedirect();

        $this->assertSame(0, $nc->contenciones()->count());
    }

    /**
     * ⚠️ **El límite de la excepción.** Una vez aprobada, la contención vuelve a
     * ser de quien gestiona el caso: el creador sin `nc.gestionar` ya no la
     * toca. Sin este test, la regla se puede ensanchar sin que nadie lo note.
     */
    public function test_ya_aprobado_el_creador_sin_permisos_no_carga_contencion(): void
    {
        $creador = $this->userWith('nc.view', 'nc.create');
        $nc = $this->nc(['estado' => 'abierta', 'creado_por' => $creador->id]);

        $this->actingAs($creador)
            ->post("/no-conformidades/{$nc->id}/contencion", [
                'fecha' => '2026-09-29',
                'accion' => 'Tarde.',
            ])
            ->assertStatus(403);
    }

    public function test_un_tercero_no_carga_contencion_en_un_borrador_ajeno(): void
    {
        $nc = $this->nc(['estado' => 'borrador', 'creado_por' => User::factory()->create()->id]);

        $this->actingAs($this->userWith('nc.view', 'nc.create'))
            ->post("/no-conformidades/{$nc->id}/contencion", [
                'fecha' => '2026-09-29',
                'accion' => 'No es mío.',
            ])
            ->assertStatus(403);
    }

    /** Calidad sigue pudiendo, en borrador como en cualquier otra etapa. */
    public function test_calidad_carga_contencion_en_un_borrador_ajeno(): void
    {
        $nc = $this->nc(['estado' => 'borrador', 'creado_por' => User::factory()->create()->id]);

        $this->actingAs($this->userWith('nc.view', 'nc.gestionar'))
            ->post("/no-conformidades/{$nc->id}/contencion", [
                'fecha' => '2026-09-29',
                'accion' => 'Calidad interviene.',
            ])
            ->assertRedirect();

        $this->assertSame(1, $nc->contenciones()->count());
    }
}
