<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionAsignadaNotification;
use App\Notifications\ObservacionCriticaNotification;
use App\Notifications\ObservacionEscaladaNotification;
use App\Notifications\ObservacionFinalizadaNotification;
use App\Notifications\ObservacionVencidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertasObservacionTest extends TestCase
{
    use RefreshDatabase;

    private int $sectoresCreados = 0;

    /** Responsable con su cadena completa: sector con plazo, supervisor y gerente. */
    private function responsable(int $dias = 3): User
    {
        $slug = 'sector-'.(++$this->sectoresCreados);
        $sector = Sector::create(['nombre' => "Sector {$this->sectoresCreados}", 'slug' => $slug, 'dias_gestion' => $dias]);
        $gerente = User::factory()->create(['es_gerente' => true]);
        $supervisor = User::factory()->create(['gerente_id' => $gerente->id]);

        return User::factory()->create([
            'sector_id' => $sector->id,
            'supervisor_id' => $supervisor->id,
            'gerente_id' => $gerente->id,
        ]);
    }

    private function observacion(array $atributos = []): Observacion
    {
        return Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'clasificada',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            ...$atributos,
        ]);
    }

    public function test_asignar_responsable_arranca_el_reloj_con_dias_habiles(): void
    {
        // Lunes: 3 días hábiles caen el jueves, sin cruzar fin de semana.
        $this->travelTo('2026-07-20 09:00:00');

        $observacion = $this->observacion(['responsable_id' => $this->responsable(3)->id]);

        $this->assertNotNull($observacion->responsable_asignado_at);
        $this->assertSame('2026-07-23', $observacion->vence_at->toDateString());
        $this->assertSame(0, $observacion->alerta_nivel);
    }

    public function test_crear_con_responsable_le_avisa_en_el_panel(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        Notification::assertSentTo(
            $responsable,
            ObservacionAsignadaNotification::class,
            fn ($notificacion) => $notificacion->toArray($responsable)['tipo'] === 'observacion_asignada'
                && $notificacion->toArray($responsable)['observacion_id'] === $observacion->id
                && $notificacion->via($responsable) === ['database', 'broadcast'],
        );
    }

    // ── Paso a prioridad crítica ─────────────────────────────────────────────

    public function test_pasar_a_critica_le_avisa_al_responsable(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id, 'prioridad' => 'alta']);

        $observacion->update(['prioridad' => 'critica']);

        Notification::assertSentTo(
            $responsable,
            ObservacionCriticaNotification::class,
            fn ($n) => $n->toArray($responsable)['prioridad'] === 'critica'
                && $n->via($responsable) === ['database', 'broadcast'],
        );
    }

    /** Solo se avisa al entrar en crítica, no ante cualquier movimiento. */
    public function test_bajar_de_critica_no_avisa(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id, 'prioridad' => 'critica']);

        $observacion->update(['prioridad' => 'alta']);

        Notification::assertNotSentTo($responsable, ObservacionCriticaNotification::class);
    }

    public function test_guardar_sin_tocar_la_prioridad_no_reavisa(): void
    {
        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id, 'prioridad' => 'critica']);

        Notification::fake();
        $observacion->update(['estado' => 'en_proceso']);

        Notification::assertNotSentTo($responsable, ObservacionCriticaNotification::class);
    }

    /** El aviso de asignación ya viaja en rojo con la prioridad nueva. */
    public function test_si_ademas_cambia_el_responsable_solo_avisa_la_asignacion(): void
    {
        Notification::fake();
        $observacion = $this->observacion(['responsable_id' => $this->responsable()->id, 'prioridad' => 'alta']);
        $nuevo = $this->responsable();

        $observacion->update(['prioridad' => 'critica', 'responsable_id' => $nuevo->id]);

        Notification::assertSentTo($nuevo, ObservacionAsignadaNotification::class);
        Notification::assertNotSentTo($nuevo, ObservacionCriticaNotification::class);
    }

    /** Avisarle a alguien de su propia acción es ruido. */
    public function test_no_le_avisa_a_quien_hizo_el_cambio(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id, 'prioridad' => 'alta']);

        $this->actingAs($responsable);
        $observacion->update(['prioridad' => 'critica']);

        Notification::assertNotSentTo($responsable, ObservacionCriticaNotification::class);
    }

    public function test_sin_responsable_no_avisa_a_nadie(): void
    {
        Notification::fake();
        $observacion = $this->observacion(['prioridad' => 'alta']);

        $observacion->update(['prioridad' => 'critica']);

        Notification::assertNothingSent();
    }

    /** El toast y la campana pintan en rojo desde este campo del payload. */
    public function test_el_payload_del_aviso_lleva_la_prioridad(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $this->observacion(['responsable_id' => $responsable->id, 'prioridad' => 'critica']);

        Notification::assertSentTo(
            $responsable,
            ObservacionAsignadaNotification::class,
            fn ($notificacion) => $notificacion->toArray($responsable)['prioridad'] === 'critica',
        );
    }

    public function test_el_payload_lleva_la_prioridad_en_null_si_no_esta_clasificada(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $this->observacion(['responsable_id' => $responsable->id]);

        Notification::assertSentTo(
            $responsable,
            ObservacionAsignadaNotification::class,
            fn ($notificacion) => array_key_exists('prioridad', $notificacion->toArray($responsable))
                && $notificacion->toArray($responsable)['prioridad'] === null,
        );
    }

    public function test_observacion_critica_marca_el_asunto_del_mail(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id, 'prioridad' => 'critica']);

        Notification::assertSentTo(
            $responsable,
            ObservacionAsignadaNotification::class,
            fn ($notificacion) => str_starts_with($notificacion->toMail($responsable)->subject, 'Prioridad crítica: '),
        );
    }

    public function test_observacion_no_critica_no_marca_el_asunto_del_mail(): void
    {
        Notification::fake();
        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id, 'prioridad' => 'alta']);

        Notification::assertSentTo(
            $responsable,
            ObservacionAsignadaNotification::class,
            fn ($notificacion) => ! str_contains($notificacion->toMail($responsable)->subject, 'Prioridad crítica'),
        );
    }

    public function test_el_broadcast_no_necesita_un_worker_permanente(): void
    {
        $responsable = User::factory()->create();
        $observacion = $this->observacion();
        $notificacion = new ObservacionAsignadaNotification($observacion);

        $this->assertSame('deferred', $notificacion->viaConnections()['broadcast']);
        $this->assertSame('sync', $notificacion->toBroadcast($responsable)->connection);
    }

    public function test_reasignar_le_avisa_solo_al_nuevo_responsable(): void
    {
        Notification::fake();
        $anterior = $this->responsable();
        $nuevo = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $anterior->id]);

        Notification::fake();
        $observacion->update(['responsable_id' => $nuevo->id]);

        Notification::assertSentTo(
            $nuevo,
            ObservacionAsignadaNotification::class,
            fn ($notificacion) => $notificacion->toArray($nuevo)['tipo'] === 'observacion_reasignada',
        );
        Notification::assertNotSentTo($anterior, ObservacionAsignadaNotification::class);
    }

    public function test_el_plazo_saltea_el_fin_de_semana(): void
    {
        // Jueves + 3 días hábiles = martes de la semana siguiente.
        $this->travelTo('2026-07-23 09:00:00');

        $observacion = $this->observacion(['responsable_id' => $this->responsable(3)->id]);

        $this->assertSame('2026-07-28', $observacion->vence_at->toDateString());
    }

    public function test_responsable_sin_sector_no_genera_vencimiento(): void
    {
        $observacion = $this->observacion(['responsable_id' => User::factory()->create()->id]);

        $this->assertNotNull($observacion->responsable_asignado_at);
        $this->assertNull($observacion->vence_at);
    }

    public function test_reasignar_reinicia_el_reloj_y_el_nivel_de_alerta(): void
    {
        $observacion = $this->observacion(['responsable_id' => $this->responsable(2)->id]);
        $observacion->update(['alerta_nivel' => 1]);

        $this->travel(10)->days();
        $observacion->update(['responsable_id' => $this->responsable(5)->id]);

        $observacion->refresh();
        $this->assertSame(0, $observacion->alerta_nivel);
        $this->assertTrue($observacion->vence_at->isAfter(now()));
    }

    public function test_desasignar_al_responsable_apaga_el_reloj(): void
    {
        $observacion = $this->observacion(['responsable_id' => $this->responsable()->id]);

        $observacion->update(['responsable_id' => null]);

        $observacion->refresh();
        $this->assertNull($observacion->vence_at);
        $this->assertNull($observacion->responsable_asignado_at);
    }

    /**
     * La fecha va fija (un lunes) en todos los tests que combinan el plazo en
     * días hábiles con `travel()` en días corridos: corriendo un jueves, dos
     * días hábiles caen el lunes siguiente y avanzar 3 días corridos llega al
     * domingo, así que la observación todavía no estaba vencida y el test
     * fallaba solo según el día en que se corriera.
     */
    public function test_al_vencer_avisa_al_responsable_y_a_su_supervisor(): void
    {
        Notification::fake();
        $this->travelTo('2026-07-20 09:00:00');

        $responsable = $this->responsable(2);
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $this->travel(3)->days();
        $this->artisan('observaciones:alertas')->assertSuccessful();

        Notification::assertSentTo($responsable, ObservacionVencidaNotification::class);
        Notification::assertSentTo($responsable->supervisor, ObservacionVencidaNotification::class);
        Notification::assertNotSentTo($responsable->gerente, ObservacionVencidaNotification::class);

        $this->assertSame(1, $observacion->fresh()->alerta_nivel);
    }

    public function test_correr_el_comando_dos_veces_no_duplica_el_aviso(): void
    {
        Notification::fake();
        $this->travelTo('2026-07-20 09:00:00');

        $responsable = $this->responsable(2);
        $this->observacion(['responsable_id' => $responsable->id]);

        $this->travel(3)->days();
        $this->artisan('observaciones:alertas');
        $this->artisan('observaciones:alertas');

        Notification::assertSentToTimes($responsable, ObservacionVencidaNotification::class, 1);
    }

    public function test_pasado_otro_plazo_sin_gestion_escala_al_gerente(): void
    {
        Notification::fake();
        $this->travelTo('2026-07-20 09:00:00');

        $responsable = $this->responsable(2);
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $this->travel(3)->days();
        $this->artisan('observaciones:alertas');

        // Todavía no pasó el segundo plazo: no escala.
        $this->artisan('observaciones:alertas');
        Notification::assertNothingSentTo($responsable->gerente);

        $this->travel(5)->days();
        $this->artisan('observaciones:alertas');

        Notification::assertSentTo($responsable->gerente, ObservacionEscaladaNotification::class);
        $this->assertSame(2, $observacion->fresh()->alerta_nivel);
    }

    public function test_una_observacion_finalizada_no_alerta(): void
    {
        Notification::fake();

        $responsable = $this->responsable(2);
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $this->travel(3)->days();
        $observacion->update(['estado' => 'cerrada']);
        $this->artisan('observaciones:alertas');

        Notification::assertNotSentTo($responsable, ObservacionVencidaNotification::class);
    }

    public function test_cerrar_la_observacion_le_avisa_al_gerente_del_responsable(): void
    {
        Notification::fake();

        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $observacion->update(['estado' => 'cerrada']);

        Notification::assertSentTo($responsable->gerente, ObservacionFinalizadaNotification::class);
    }

    /**
     * El aviso final sale de `config('incidencias.estados_finales')`, no de
     * cualquier cambio de estado: avanzar el caso sin terminarlo no le avisa
     * a nadie.
     */
    public function test_pasar_a_un_estado_no_final_todavia_no_es_el_aviso_final(): void
    {
        Notification::fake();

        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $observacion->update(['estado' => 'en_proceso']);

        Notification::assertNothingSentTo($responsable->gerente);
    }

    /**
     * Los mails llevan la casilla del sector que atiende el tipo de caso: si
     * salieran solo de no-reply@ las respuestas se pierden, y ademas es una
     * senal que penalizan los filtros de spam.
     */
    public function test_el_aviso_lleva_el_reply_to_del_tipo_de_caso(): void
    {
        $responsable = $this->responsable();

        $falla = $this->observacion(['numero' => '0001-26', 'tipo' => 'falla_producto']);
        $servicio = $this->observacion(['numero' => '0002-26', 'tipo' => 'disconformidad_servicio']);

        $this->assertSame(
            [['calidad@tublood.com', null]],
            (new ObservacionAsignadaNotification($falla))->toMail($responsable)->replyTo
        );

        // A proposito distinto del rol que clasifica (calidad_servicio):
        // quien responde el mail no es quien clasifica el caso.
        $this->assertSame(
            [['asuntosregulatorios@tublood.com', null]],
            (new ObservacionAsignadaNotification($servicio))->toMail($responsable)->replyTo
        );
    }

    /** Un tipo sin casilla declarada no lleva Reply-To en vez de romper. */
    public function test_un_tipo_sin_reply_to_declarado_no_lleva_ninguno(): void
    {
        $responsable = $this->responsable();
        $interna = $this->observacion(['tipo' => 'doble_etiquetado']);

        $this->assertSame(
            [],
            (new ObservacionAsignadaNotification($interna))->toMail($responsable)->replyTo
        );
    }
}
