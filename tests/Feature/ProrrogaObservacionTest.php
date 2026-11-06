<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Integracion;
use App\Models\Observacion;
use App\Models\ObservationExtension;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionProrrogaResueltaNotification;
use App\Notifications\ObservacionProrrogaSolicitadaNotification;
use App\Notifications\ObservacionVencidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Prórroga de plazo con autorización (punto 18 del pedido del 6/11/2026).
 *
 * Las fechas van fijas: el plazo corre en días hábiles, así que un test que use
 * "hoy" cambia de resultado según el día de la semana en que se corra.
 */
class ProrrogaObservacionTest extends TestCase
{
    use RefreshDatabase;

    private Sector $calidad;

    protected function setUp(): void
    {
        parent::setUp();

        // Lunes. El responsable de Calidad tiene 3 días hábiles: vence el jueves 5/11.
        $this->travelTo('2026-11-02 09:00:00');

        $this->calidad = Sector::create(['nombre' => 'Calidad', 'slug' => 'garantia_calidad', 'dias_gestion' => 3]);
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

    private function responsable(): User
    {
        return $this->usuario(['sector_id' => $this->calidad->id]);
    }

    private function autorizador(): User
    {
        return $this->usuario([], ['observaciones.prorrogas.autorizar']);
    }

    private function observacion(User $responsable, array $attrs = []): Observacion
    {
        return Observacion::create(array_merge([
            'numero' => '0031-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'en_proceso',
            'origen' => 'externa',
            'sector_id' => $this->calidad->id,
            'contacto_nombre' => 'Cliente',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Ensayo pendiente',
            'descripcion' => 'Hay que ensayar el lote.',
            'responsable_id' => $responsable->id,
        ], $attrs));
    }

    private function pedido(array $extra = []): array
    {
        return array_merge([
            'fecha_pedida' => '2026-11-20',
            'motivo' => 'laboratorio',
            'que_se_hace' => 'Ensayo de llenado sobre 20 tubos del lote',
            'justificacion' => 'El ensayo lleva dos semanas.',
        ], $extra);
    }

    // ── Pedir ───────────────────────────────────────────────────────────────

    public function test_el_responsable_pide_la_prorroga_y_queda_en_la_bitacora(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $autorizador = $this->autorizador();
        $o = $this->observacion($responsable);

        $this->actingAs($responsable)
            ->post("/observaciones/{$o->id}/prorrogas", $this->pedido())
            ->assertSessionHasNoErrors();

        $prorroga = $o->prorrogas()->first();
        $this->assertSame('pendiente', $prorroga->estado);
        $this->assertSame($responsable->id, $prorroga->solicitada_por);
        $this->assertSame('2026-11-20', $prorroga->fecha_pedida->toDateString());
        // "Vencimiento actual: se completa solo".
        $this->assertSame('2026-11-05', $prorroga->vencimiento_previo->toDateString());

        $entrada = $o->historial()->where('accion', ObservationHistory::ACCION_PRORROGA_PEDIDA)->first();
        $this->assertNotNull($entrada);
        $this->assertSame('El ensayo lleva dos semanas.', $entrada->nota);

        // Le llega a quien autoriza, no al que pidió.
        Notification::assertSentTo($autorizador, ObservacionProrrogaSolicitadaNotification::class);
        Notification::assertNotSentTo($responsable, ObservacionProrrogaSolicitadaNotification::class);
        // Pedir no mueve el plazo: sigue corriendo hasta que se autorice.
        $this->assertNull($o->fresh()->prorroga_hasta);
    }

    public function test_si_nadie_tiene_el_permiso_el_aviso_cae_en_los_super_admin(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = $this->usuario();
        $admin->assignRole('super-admin');

        $this->actingAs($responsable)->post("/observaciones/{$o->id}/prorrogas", $this->pedido());

        Notification::assertSentTo($admin, ObservacionProrrogaSolicitadaNotification::class);
    }

    public function test_quien_no_es_responsable_no_puede_pedirla(): void
    {
        $o = $this->observacion($this->responsable());

        $this->actingAs($this->usuario())
            ->post("/observaciones/{$o->id}/prorrogas", $this->pedido())
            ->assertForbidden();

        // Ni siquiera quien autoriza: autorizar y pedir son cosas distintas.
        $this->actingAs($this->autorizador())
            ->post("/observaciones/{$o->id}/prorrogas", $this->pedido())
            ->assertForbidden();

        $this->assertSame(0, ObservationExtension::count());
    }

    public function test_el_pedido_se_valida(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);

        $this->actingAs($responsable);

        // Fecha pasada.
        $this->post("/observaciones/{$o->id}/prorrogas", $this->pedido(['fecha_pedida' => '2026-11-01']))
            ->assertSessionHasErrors('fecha_pedida');
        // No estira el plazo vigente (vence el 5/11).
        $this->post("/observaciones/{$o->id}/prorrogas", $this->pedido(['fecha_pedida' => '2026-11-04']))
            ->assertSessionHasErrors('fecha_pedida');
        // Motivo fuera de la lista y campos obligatorios.
        $this->post("/observaciones/{$o->id}/prorrogas", $this->pedido(['motivo' => 'porque si']))
            ->assertSessionHasErrors('motivo');
        $this->post("/observaciones/{$o->id}/prorrogas", $this->pedido(['que_se_hace' => '', 'justificacion' => '']))
            ->assertSessionHasErrors(['que_se_hace', 'justificacion']);

        $this->assertSame(0, ObservationExtension::count());
    }

    public function test_no_se_prorroga_una_observacion_cerrada(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable, ['estado' => 'cerrada']);

        $this->actingAs($responsable)
            ->post("/observaciones/{$o->id}/prorrogas", $this->pedido())
            ->assertSessionHasErrors('fecha_pedida');
    }

    public function test_solo_hay_una_prorroga_pendiente_por_vez(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);

        $this->actingAs($responsable)->post("/observaciones/{$o->id}/prorrogas", $this->pedido());
        $this->post("/observaciones/{$o->id}/prorrogas", $this->pedido(['fecha_pedida' => '2026-11-25']))
            ->assertSessionHasErrors('fecha_pedida');

        $this->assertSame(1, $o->prorrogas()->count());
    }

    // ── Resolver ────────────────────────────────────────────────────────────

    private function pendiente(Observacion $o, User $responsable): ObservationExtension
    {
        $this->actingAs($responsable)->post("/observaciones/{$o->id}/prorrogas", $this->pedido());

        return $o->prorrogas()->firstOrFail();
    }

    public function test_aprobar_estira_el_plazo_y_deja_constancia(): void
    {
        $responsable = $this->responsable();
        $autorizador = $this->autorizador();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);
        $o->update(['alerta_nivel' => 1]);

        Notification::fake();
        $this->actingAs($autorizador)
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", [
                'decision' => 'aprobar',
                'fecha_autorizada' => '2026-11-20',
                'comentario' => 'Dale, con el ensayo.',
            ])
            ->assertSessionHasNoErrors();

        $o->refresh();
        // Fin del día autorizado, no su comienzo.
        $this->assertSame('2026-11-20 23:59:59', $o->prorroga_hasta->format('Y-m-d H:i:s'));
        // El plazo original no se pisa: se ve cuánto se estiró el caso.
        $this->assertSame('2026-11-05', $o->vence_at->toDateString());
        $this->assertSame(0, $o->alerta_nivel);
        $this->assertTrue($o->enProrroga());

        $prorroga->refresh();
        $this->assertSame('aprobada', $prorroga->estado);
        $this->assertSame($autorizador->id, $prorroga->decidida_por);

        $entrada = $o->historial()->where('accion', ObservationHistory::ACCION_PRORROGA_RESUELTA)->first();
        $this->assertSame('aprobada', $entrada->cambios['decision']);
        $this->assertSame('Dale, con el ensayo.', $entrada->nota);

        Notification::assertSentTo($responsable, ObservacionProrrogaResueltaNotification::class);
    }

    public function test_se_puede_aprobar_con_otra_fecha(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);

        $this->actingAs($this->autorizador())
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", [
                'decision' => 'aprobar',
                'fecha_autorizada' => '2026-11-12',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-11-12', $o->fresh()->prorroga_hasta->toDateString());
        $this->assertSame('2026-11-12', $prorroga->fresh()->fecha_autorizada->toDateString());
        // La pedida queda como se pidió.
        $this->assertSame('2026-11-20', $prorroga->fresh()->fecha_pedida->toDateString());
    }

    public function test_rechazar_deja_el_vencimiento_original(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);

        Notification::fake();
        $this->actingAs($this->autorizador())
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", [
                'decision' => 'rechazar',
                'comentario' => 'Cerrala con lo que hay.',
            ])
            ->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertNull($o->prorroga_hasta);
        $this->assertFalse($o->enProrroga());
        $this->assertSame('rechazada', $prorroga->fresh()->estado);
        Notification::assertSentTo($responsable, ObservacionProrrogaResueltaNotification::class);
    }

    public function test_aprobar_exige_una_fecha_que_extienda_el_plazo(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);
        $autorizador = $this->autorizador();

        $this->actingAs($autorizador)
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar'])
            ->assertSessionHasErrors('fecha_autorizada');

        // El plazo vence el 5/11: una fecha anterior no es una prórroga.
        $this->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar', 'fecha_autorizada' => '2026-11-04'])
            ->assertSessionHasErrors('fecha_autorizada');

        $this->assertSame('pendiente', $prorroga->fresh()->estado);
    }

    public function test_el_responsable_no_se_aprueba_a_si_mismo(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);

        $this->actingAs($responsable)
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar', 'fecha_autorizada' => '2026-11-20'])
            ->assertForbidden();

        $this->actingAs($this->usuario())
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar', 'fecha_autorizada' => '2026-11-20'])
            ->assertForbidden();

        $this->assertSame('pendiente', $prorroga->fresh()->estado);
    }

    public function test_una_prorroga_resuelta_no_se_resuelve_de_nuevo(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);
        $autorizador = $this->autorizador();

        $this->actingAs($autorizador)
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'rechazar']);

        $this->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar', 'fecha_autorizada' => '2026-11-20'])
            ->assertSessionHasErrors('decision');

        $this->assertSame('rechazada', $prorroga->fresh()->estado);
    }

    public function test_la_prorroga_tiene_que_ser_de_esa_observacion(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $otra = $this->observacion($responsable, ['numero' => '0032-26']);
        $prorroga = $this->pendiente($o, $responsable);

        $this->actingAs($this->autorizador())
            ->put("/observaciones/{$otra->id}/prorrogas/{$prorroga->id}", ['decision' => 'rechazar'])
            ->assertNotFound();
    }

    // ── El plazo efectivo ───────────────────────────────────────────────────

    /** La prueba del cliente: prórroga aprobada hasta el 20/11, no figura vencida el 20/11 y sí el 21/11. */
    public function test_con_prorroga_hasta_el_20_no_vence_el_20_y_si_el_21(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);
        $this->actingAs($this->autorizador())
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar', 'fecha_autorizada' => '2026-11-20']);

        // Sin la prórroga ya estaría vencida (vencía el 5/11).
        $this->travelTo('2026-11-10 10:00:00');
        $this->assertSame(0, Observacion::vencidas()->count());
        $this->assertTrue($o->fresh()->enProrroga());

        $this->travelTo('2026-11-20 23:00:00');
        $this->assertSame(0, Observacion::vencidas()->count());

        $this->travelTo('2026-11-21 00:30:00');
        $this->assertSame(1, Observacion::vencidas()->count());
        $this->assertFalse($o->fresh()->enProrroga());
    }

    public function test_las_alertas_esperan_a_la_fecha_autorizada(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);
        $this->actingAs($this->autorizador())
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar', 'fecha_autorizada' => '2026-11-20']);

        Notification::fake();

        // El plazo original pasó hace rato, pero está prorrogada: no avisa.
        $this->travelTo('2026-11-10 10:00:00');
        $this->artisan('observaciones:alertas')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertSame(0, $o->fresh()->alerta_nivel);

        // Pasada la fecha autorizada, avisa como cualquier vencida.
        $this->travelTo('2026-11-21 08:00:00');
        $this->artisan('observaciones:alertas')->assertSuccessful();
        Notification::assertSentTo($responsable, ObservacionVencidaNotification::class);
    }

    public function test_pasada_la_fecha_autorizada_se_puede_pedir_otra(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $prorroga = $this->pendiente($o, $responsable);
        $this->actingAs($this->autorizador())
            ->put("/observaciones/{$o->id}/prorrogas/{$prorroga->id}", ['decision' => 'aprobar', 'fecha_autorizada' => '2026-11-20']);

        $this->travelTo('2026-11-23 09:00:00');

        // La segunda tiene que estirar la primera, no el plazo original.
        $this->actingAs($responsable)
            ->post("/observaciones/{$o->id}/prorrogas", $this->pedido(['fecha_pedida' => '2026-11-19']))
            ->assertSessionHasErrors('fecha_pedida');

        $this->post("/observaciones/{$o->id}/prorrogas", $this->pedido(['fecha_pedida' => '2026-12-04']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $o->prorrogas()->count());
    }

    public function test_el_aviso_de_atraso_cuenta_desde_el_plazo_vigente(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $o->update(['prorroga_hasta' => '2026-11-20 23:59:59']);

        $this->assertSame('2026-11-20', $o->fresh()->vencimiento()->toDateString());
    }

    // ── Pantallas y API ─────────────────────────────────────────────────────

    public function test_la_ficha_manda_las_prorrogas_y_quien_puede_resolverlas(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $this->pendiente($o, $responsable);

        $this->actingAs($this->autorizador())
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page
                ->where('puedeAutorizarProrroga', true)
                ->where('puedeEditar', false)
                ->has('observacion.prorrogas', 1)
                ->where('observacion.prorrogas.0.estado', 'pendiente')
                ->has('motivosProrroga'));

        $this->actingAs($responsable)
            ->get("/observaciones/{$o->id}")
            ->assertInertia(fn ($page) => $page->where('puedeAutorizarProrroga', false)->where('puedeEditar', true));
    }

    public function test_el_excel_y_la_api_muestran_el_plazo_vigente(): void
    {
        $responsable = $this->responsable();
        $o = $this->observacion($responsable);
        $o->update(['prorroga_hasta' => '2026-11-20 23:59:59']);

        $this->assertSame('20/11/2026', $o->fresh()->vencimiento()->format('d/m/Y'));

        // La API pública devuelve el plazo vigente.
        $cliente = Cliente::create(['numero' => '100', 'razon_social' => 'Cliente SA']);
        $o->update(['cliente_id' => $cliente->id]);
        $token = Integracion::create(['nombre' => 'Sistema X'])
            ->createToken('api', ['observaciones:leer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/clientes/100/observaciones')
            ->assertOk()
            ->assertJsonPath('data.observaciones.0.vence_at', fn ($v) => str_starts_with($v, '2026-11-20'));
    }
}
