<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionVencidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SoftDeletes en observaciones + cancelación con motivo. Las dos rutas de
 * baja (`destroy` y `update` con estado `cancelada`) dejan el motivo en una
 * entrada `ObservationHistory::ACCION_BAJA` — no en una columna propia — así
 * que restaurar no pierde nada y no hay dos versiones del mismo relato.
 */
class ObservacionBajaTest extends TestCase
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

    private function observacion(array $attrs = []): Observacion
    {
        static $correlativo = 0;
        $correlativo++;

        return Observacion::create(array_merge([
            'numero' => sprintf('%04d-26', $correlativo),
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'en_proceso',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ], $attrs));
    }

    // ── Borrado ──────────────────────────────────────────────────────────

    public function test_destroy_requiere_permiso_observaciones_delete(): void
    {
        $user = $this->userWith('observaciones.view');
        $observacion = $this->observacion();

        $this->actingAs($user)
            ->delete("/observaciones/{$observacion->id}", ['motivo' => 'Duplicada, se cargó dos veces.'])
            ->assertStatus(403);

        $this->assertNull($observacion->fresh()->deleted_at);
    }

    public function test_destroy_requiere_motivo(): void
    {
        $user = $this->userWith('observaciones.delete');
        $observacion = $this->observacion();

        $this->actingAs($user)
            ->delete("/observaciones/{$observacion->id}", [])
            ->assertSessionHasErrors('motivo');

        $this->assertNull($observacion->fresh()->deleted_at);
    }

    public function test_destroy_borra_y_registra_el_motivo_en_la_bitacora(): void
    {
        $user = $this->userWith('observaciones.delete');
        $observacion = $this->observacion();

        $this->actingAs($user)
            ->delete("/observaciones/{$observacion->id}", ['motivo' => 'Duplicada, se cargó dos veces.'])
            ->assertRedirect(route('observaciones.index'));

        $this->assertNotNull($observacion->fresh()->deleted_at);

        $entrada = $observacion->historial()->where('accion', ObservationHistory::ACCION_BAJA)->first();
        $this->assertNotNull($entrada);
        $this->assertSame('Duplicada, se cargó dos veces.', $entrada->nota);
        $this->assertSame('borrado', $entrada->cambios['tipo']);
        $this->assertSame($user->id, $entrada->user_id);
    }

    /**
     * El punto crítico del soft delete: `generarNumero()` tiene que seguir
     * contando la borrada, o el próximo alta repite un `numero` que es
     * `unique()` en el schema.
     */
    public function test_generar_numero_no_repite_el_de_una_observacion_borrada(): void
    {
        $borrada = $this->observacion(['numero' => '0001-26', 'anio' => 2026]);
        $borrada->historial()->create(['accion' => ObservationHistory::ACCION_BAJA, 'nota' => 'x', 'cambios' => ['tipo' => 'borrado']]);
        $borrada->delete();

        $this->assertSame('0002-26', Observacion::generarNumero(2026));
    }

    /**
     * El caso que `count()` no cubría: un borrado **definitivo** (fuera del
     * soft delete) deja un hueco en la serie, y contar filas devolvía un
     * correlativo ya usado. Sale del máximo justamente por esto.
     */
    public function test_generar_numero_no_repite_aunque_falte_una_del_medio(): void
    {
        $this->observacion(['numero' => '0001-26', 'anio' => 2026]);
        $delMedio = $this->observacion(['numero' => '0002-26', 'anio' => 2026]);
        $this->observacion(['numero' => '0003-26', 'anio' => 2026]);

        $delMedio->forceDelete();

        $this->assertSame('0004-26', Observacion::generarNumero(2026));
    }

    /** Cada año arranca su propia serie. */
    public function test_generar_numero_arranca_en_uno_para_un_anio_sin_observaciones(): void
    {
        $this->observacion(['numero' => '0007-26', 'anio' => 2026]);

        $this->assertSame('0001-27', Observacion::generarNumero(2027));
    }

    public function test_una_observacion_borrada_desaparece_del_listado_y_de_la_campana(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.delete');
        $observacion = $this->observacion(['estado' => 'pendiente_clasificacion']);

        $this->actingAs($user)
            ->delete("/observaciones/{$observacion->id}", ['motivo' => 'No corresponde.'])
            ->assertRedirect(route('observaciones.index'));

        $this->actingAs($user)->get('/observaciones')
            ->assertInertia(fn ($page) => $page->has('observaciones.data', 0));

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.sinClasificar', 0));
    }

    /**
     * El soft delete es un UPDATE, no dispara los `cascadeOnDelete()` de la
     * base: el historial de la observación borrada tiene que seguir completo,
     * y sus entradas no pueden quedar huérfanas (ver `withTrashed()` en
     * `ObservationHistory::observacion()`).
     */
    public function test_la_bitacora_de_una_borrada_sigue_completa_y_con_la_observacion(): void
    {
        $user = $this->userWith('observaciones.delete', 'bitacora.view');
        $observacion = $this->observacion(['responsable_id' => $user->id]);
        $cantidadPrevia = $observacion->historial()->count();

        $this->actingAs($user)
            ->delete("/observaciones/{$observacion->id}", ['motivo' => 'No corresponde.'])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame($cantidadPrevia + 1, $observacion->historial()->count());

        $this->actingAs($user)->get('/bitacora')
            ->assertInertia(fn ($page) => $page
                ->where('entradas.data.0.observacion.id', $observacion->id)
            );
    }

    // ── Restaurar ────────────────────────────────────────────────────────

    public function test_restore_requiere_permiso(): void
    {
        $user = $this->userWith('observaciones.view');
        $observacion = $this->observacion();
        $observacion->delete();

        $this->actingAs($user)->post("/bajas/{$observacion->id}/restaurar")->assertStatus(403);
    }

    public function test_restore_devuelve_al_listado_y_registra_la_restauracion(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.delete');
        $observacion = $this->observacion();
        $observacion->delete();

        $this->actingAs($user)
            ->post("/bajas/{$observacion->id}/restaurar")
            ->assertRedirect(route('bajas.index'));

        $this->assertNull($observacion->fresh()->deleted_at);

        $this->actingAs($user)->get('/observaciones')
            ->assertInertia(fn ($page) => $page->has('observaciones.data', 1));

        $this->assertTrue(
            $observacion->historial()->where('accion', ObservationHistory::ACCION_RESTAURACION)->exists()
        );
    }

    // ── Cancelación con motivo ───────────────────────────────────────────

    public function test_cancelar_sin_motivo_falla(): void
    {
        $user = $this->userWith('observaciones.view');
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", ['estado' => 'cancelada'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame('en_proceso', $observacion->fresh()->estado);
    }

    public function test_cancelar_con_motivo_registra_la_baja(): void
    {
        // Cancelar queda reservado a super-admin (ver ObservacionAdminTest para
        // el rechazo a quien no lo es). super-admin saltea el Gate entero, así
        // que no hace falta darle ningún permiso aparte.
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'super-admin']);
        $user->assignRole('super-admin');
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'estado' => 'cancelada',
                'motivo' => 'El cliente desistió del reclamo.',
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame('cancelada', $observacion->fresh()->estado);

        $entrada = $observacion->historial()->where('accion', ObservationHistory::ACCION_BAJA)->first();
        $this->assertNotNull($entrada);
        $this->assertSame('El cliente desistió del reclamo.', $entrada->nota);
        $this->assertSame('cancelacion', $entrada->cambios['tipo']);
    }

    /**
     * El bug adyacente que motivó este cambio: antes solo `cerrada` cortaba el
     * reloj, así que una cancelada seguía venciendo y escalando al gerente.
     */
    public function test_una_observacion_cancelada_deja_de_alertar(): void
    {
        Notification::fake();
        $this->travelTo('2026-07-20 09:00:00');

        $sector = Sector::create(['nombre' => 'Sector Test', 'slug' => 'sector-test', 'dias_gestion' => 2]);
        $responsable = User::factory()->create(['sector_id' => $sector->id]);
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $observacion->update(['estado' => 'cancelada']);

        $this->travel(5)->days();
        $this->artisan('observaciones:alertas')->assertSuccessful();

        Notification::assertNotSentTo($responsable, ObservacionVencidaNotification::class);
    }

    // ── Pantalla de Bajas ────────────────────────────────────────────────

    public function test_bajas_index_requiere_permiso_observaciones_delete(): void
    {
        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get('/bajas')->assertStatus(403);
    }

    public function test_bajas_index_lista_canceladas_y_borradas(): void
    {
        $user = $this->userWith('observaciones.delete');

        $cancelada = $this->observacion(['titulo' => 'Cancelada', 'estado' => 'cancelada']);
        $borrada = $this->observacion(['titulo' => 'Borrada']);
        $borrada->delete();
        $this->observacion(['titulo' => 'Abierta, no debería salir']);

        $this->actingAs($user)->get('/bajas')
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 2)
            );
    }

    public function test_bajas_index_filtra_por_tipo(): void
    {
        $user = $this->userWith('observaciones.delete');

        $this->observacion(['titulo' => 'Cancelada', 'estado' => 'cancelada']);
        $borrada = $this->observacion(['titulo' => 'Borrada']);
        $borrada->delete();

        $this->actingAs($user)->get('/bajas?tipo=borrada')
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Borrada')
            );

        $this->actingAs($user)->get('/bajas?tipo=cancelada')
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Cancelada')
            );
    }

    // ── Autorización sobre una borrada ───────────────────────────────────

    /**
     * `observaciones.update`/`bitacora.store` no llevan `withTrashed()` en la
     * ruta (a propósito: gestionar un caso borrado no tiene sentido fuera de
     * restaurarlo), así que el binding implícito ya las corta con 404 antes
     * de llegar a la Policy.
     */
    public function test_las_rutas_de_gestion_no_encuentran_una_observacion_borrada(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.delete');
        $observacion = $this->observacion(['responsable_id' => $user->id]);
        $this->actingAs($user)
            ->delete("/observaciones/{$observacion->id}", ['motivo' => 'No corresponde.'])
            ->assertRedirect(route('observaciones.index'));

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", ['estado' => 'en_proceso'])
            ->assertStatus(404);

        $this->actingAs($user)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'Comentario'])
            ->assertStatus(404);
    }

    /**
     * `observaciones.show` sí lleva `withTrashed()` (se puede abrir el detalle
     * desde Bajas), así que ahí es donde la Policy tiene que devolver `false`
     * de verdad y apagar los botones de editar/comentar.
     */
    public function test_el_detalle_de_una_borrada_no_ofrece_editar_ni_comentar(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.delete');
        $observacion = $this->observacion(['responsable_id' => $user->id]);
        $this->actingAs($user)
            ->delete("/observaciones/{$observacion->id}", ['motivo' => 'No corresponde.'])
            ->assertRedirect(route('observaciones.index'));

        $this->actingAs($user)->get("/observaciones/{$observacion->id}")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('puedeEditar', false)
                ->where('puedeComentar', false)
            );
    }
}
