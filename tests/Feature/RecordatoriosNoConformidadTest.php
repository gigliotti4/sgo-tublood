<?php

namespace Tests\Feature;

use App\Models\NoConformidad;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\AccionVencidaNotification;
use App\Notifications\VerificacionPendienteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `nc:recordatorios` — los dos avisos de plazos de una No Conformidad.
 *
 * ⚠️ **La fecha se pincha en un lunes** con `travelTo()`, mismo criterio que
 * `AlertasObservacionTest`: un test que mezcla plazos con `travel()` da
 * distinto según el día de la semana en que se corra, y un test que falla los
 * viernes es peor que no tenerlo.
 *
 * Lo que más importa acá es la **idempotencia**: la tarea corre todos los días
 * y no puede avisar dos veces de lo mismo. Son dos mecanismos distintos —el
 * estado `vencida` en las acciones, `verificacion_avisada_at` en la
 * verificación— y cada uno tiene su test.
 */
class RecordatoriosNoConformidadTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes. */
    private const HOY = '2026-10-19 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(self::HOY);
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
            'estado' => 'en_implementacion',
            'tipo_desvio' => 'interno',
            'fecha_deteccion' => '2026-09-20',
            'motivo' => 'Un resultado de auditoría interna.',
            'sector_id' => $this->sector()->id,
            'descripcion' => 'Descripción de prueba suficientemente larga.',
        ], $attrs));
    }

    // ── Acciones vencidas ───────────────────────────────────────────────────

    public function test_marca_vencida_una_accion_pasada_de_fecha_y_avisa(): void
    {
        Notification::fake();

        $responsableAccion = User::factory()->create();
        $responsableCaso = User::factory()->create();

        $nc = $this->nc(['responsable_id' => $responsableCaso->id]);
        $accion = $nc->acciones()->create([
            'descripcion' => 'Recalibrar el equipo.',
            'responsable_id' => $responsableAccion->id,
            'fecha_prevista' => '2026-10-15',
            'estado' => 'pendiente',
        ]);

        $this->artisan('nc:recordatorios')->assertSuccessful();

        $this->assertSame('vencida', $accion->fresh()->estado);

        // Los dos: quien tiene que hacerla y quien lleva el caso.
        Notification::assertSentTo($responsableAccion, AccionVencidaNotification::class);
        Notification::assertSentTo($responsableCaso, AccionVencidaNotification::class);
    }

    /**
     * ⚠️ La regresión que importa: correrla dos veces no avisa dos veces. Lo
     * garantiza el propio estado `vencida`, que sale del filtro de la tarea.
     */
    public function test_no_vuelve_a_avisar_de_la_misma_accion(): void
    {
        Notification::fake();

        $responsable = User::factory()->create();
        $nc = $this->nc();
        $nc->acciones()->create([
            'descripcion' => 'Recalibrar el equipo.',
            'responsable_id' => $responsable->id,
            'fecha_prevista' => '2026-10-15',
            'estado' => 'pendiente',
        ]);

        $this->artisan('nc:recordatorios');
        $this->artisan('nc:recordatorios');

        Notification::assertSentToTimes($responsable, AccionVencidaNotification::class, 1);
    }

    public function test_no_vence_una_accion_que_todavia_esta_en_fecha(): void
    {
        $nc = $this->nc();
        $accion = $nc->acciones()->create([
            'descripcion' => 'Recalibrar el equipo.',
            'fecha_prevista' => '2026-10-25',
            'estado' => 'pendiente',
        ]);

        $this->artisan('nc:recordatorios');

        $this->assertSame('pendiente', $accion->fresh()->estado);
    }

    /** Una completada o cancelada ya no debe nada, por más que pasara la fecha. */
    public function test_no_vence_una_accion_ya_resuelta(): void
    {
        $nc = $this->nc();

        foreach (['completada', 'cancelada'] as $estado) {
            $accion = $nc->acciones()->create([
                'descripcion' => 'Recalibrar el equipo.',
                'fecha_prevista' => '2026-10-15',
                'estado' => $estado,
            ]);

            $this->artisan('nc:recordatorios');

            $this->assertSame($estado, $accion->fresh()->estado);
        }
    }

    /**
     * Una acción de un desvío ya cerrado no es trabajo pendiente de nadie. Sin
     * este filtro seguiría venciendo para siempre.
     */
    public function test_no_vence_una_accion_de_un_desvio_cerrado(): void
    {
        $nc = $this->nc(['estado' => 'cerrada']);
        $accion = $nc->acciones()->create([
            'descripcion' => 'Recalibrar el equipo.',
            'fecha_prevista' => '2026-10-15',
            'estado' => 'pendiente',
        ]);

        $this->artisan('nc:recordatorios');

        $this->assertSame('pendiente', $accion->fresh()->estado);
    }

    // ── Verificación de eficacia ────────────────────────────────────────────

    public function test_avisa_cuando_llega_la_fecha_de_verificar(): void
    {
        Notification::fake();

        $responsable = User::factory()->create();
        $nc = $this->nc([
            'responsable_id' => $responsable->id,
            'fecha_verificacion_prevista' => '2026-10-19',
        ]);

        $this->artisan('nc:recordatorios')->assertSuccessful();

        Notification::assertSentTo($responsable, VerificacionPendienteNotification::class);
        $this->assertNotNull($nc->fresh()->verificacion_avisada_at);
    }

    /** ⚠️ Idempotencia: acá no cambia ningún estado, la marca es la columna. */
    public function test_no_vuelve_a_avisar_de_la_misma_verificacion(): void
    {
        Notification::fake();

        $responsable = User::factory()->create();
        $this->nc([
            'responsable_id' => $responsable->id,
            'fecha_verificacion_prevista' => '2026-10-19',
        ]);

        $this->artisan('nc:recordatorios');
        $this->artisan('nc:recordatorios');

        Notification::assertSentToTimes($responsable, VerificacionPendienteNotification::class, 1);
    }

    public function test_no_avisa_antes_de_la_fecha(): void
    {
        Notification::fake();

        $responsable = User::factory()->create();
        $this->nc([
            'responsable_id' => $responsable->id,
            'fecha_verificacion_prevista' => '2026-10-30',
        ]);

        $this->artisan('nc:recordatorios');

        Notification::assertNothingSentTo($responsable);
    }

    /** Con el resultado ya cargado no hay nada que recordar. */
    public function test_no_avisa_si_la_eficacia_ya_se_verifico(): void
    {
        Notification::fake();

        $responsable = User::factory()->create();
        $this->nc([
            'responsable_id' => $responsable->id,
            'fecha_verificacion_prevista' => '2026-10-19',
            'resultado_eficacia' => 'eficaz',
        ]);

        $this->artisan('nc:recordatorios');

        Notification::assertNothingSentTo($responsable);
    }

    /**
     * Una NC sin responsable es de Calidad — es el estado en el que nace. Sin
     * este fallback el recordatorio se perdería justo en los casos sin dueño.
     */
    public function test_sin_responsable_le_avisa_a_gestion_de_calidad(): void
    {
        Notification::fake();

        Permission::firstOrCreate(['name' => 'nc.gestionar', 'guard_name' => 'web']);
        $calidad = User::factory()->create();
        $calidad->givePermissionTo('nc.gestionar');

        $this->nc(['fecha_verificacion_prevista' => '2026-10-19']);

        $this->artisan('nc:recordatorios');

        Notification::assertSentTo($calidad, VerificacionPendienteNotification::class);
    }

    /**
     * ⚠️ Se filtra con `whereHas` y nunca con el scope `permission()` de
     * Spatie, que tira excepción si el permiso no está creado. Una tarea
     * agendada no puede caerse por eso.
     */
    public function test_no_se_cae_si_el_permiso_no_existe(): void
    {
        Notification::fake();

        $this->nc(['fecha_verificacion_prevista' => '2026-10-19']);

        $this->artisan('nc:recordatorios')->assertSuccessful();
    }
}
