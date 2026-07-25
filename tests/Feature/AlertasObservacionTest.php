<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
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

    public function test_al_vencer_avisa_al_responsable_y_a_su_supervisor(): void
    {
        Notification::fake();

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

    public function test_pasar_a_resuelta_todavia_no_es_el_aviso_final(): void
    {
        Notification::fake();

        $responsable = $this->responsable();
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $observacion->update(['estado' => 'resuelta']);

        Notification::assertNothingSentTo($responsable->gerente);
    }
}
