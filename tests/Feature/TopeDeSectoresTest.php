<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\SectorSaturadoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `sectores:tope` — el aviso por carga de un sector.
 *
 * ⚠️ **La fecha se pincha en un lunes** con `travelTo()`, mismo criterio que
 * `AlertasObservacionTest` y `RecordatoriosNoConformidadTest`: este comando sella
 * `tope_avisado_at`, y un test que depende del día en que se corre falla los
 * viernes y nadie entiende por qué.
 */
class TopeDeSectoresTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes. */
    private const HOY = '2026-07-20 09:00:00';

    private int $creados = 0;

    /** Correlativo propio: `numero` es único y varias llamadas chocarían. */
    private int $observacionesCreadas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(self::HOY);
    }

    private function sector(?int $tope = null): Sector
    {
        $this->creados++;

        return Sector::create([
            'nombre' => "Sector {$this->creados}",
            'slug' => "sector-{$this->creados}",
            'tope_observaciones' => $tope,
        ]);
    }

    /** Un gerente al que reporta la gente del sector. */
    private function gerenteDe(Sector $sector): User
    {
        $gerente = User::factory()->create(['es_gerente' => true]);
        User::factory()->create(['sector_id' => $sector->id, 'gerente_id' => $gerente->id]);

        return $gerente;
    }

    private function observaciones(Sector $sector, int $cuantas, string $estado = 'clasificada'): void
    {
        foreach (range(1, $cuantas) as $i) {
            $this->observacionesCreadas++;

            Observacion::create([
                'numero' => sprintf('%04d-26', $this->observacionesCreadas),
                'anio' => 2026,
                'tipo' => 'falla_producto',
                'estado' => $estado,
                'sector_id' => $sector->id,
                'contacto_nombre' => 'Cliente Test',
                'contacto_email' => 'cliente@example.com',
                'titulo' => "Caso {$i}",
                'descripcion' => 'Descripción de prueba',
            ]);
        }
    }

    // ── El umbral ───────────────────────────────────────────────────────────

    /**
     * ⚠️ El off-by-one que importa: "aguanta 5" significa que 5 está bien.
     * El aviso sale recién con la sexta.
     */
    public function test_justo_en_el_tope_no_avisa(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 5);
        $gerente = $this->gerenteDe($sector);
        $this->observaciones($sector, 5);

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertNothingSentTo($gerente);
        $this->assertNull($sector->fresh()->tope_avisado_at);
    }

    public function test_una_mas_que_el_tope_avisa(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 5);
        $gerente = $this->gerenteDe($sector);
        $this->observaciones($sector, 6);

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertSentTo($gerente, SectorSaturadoNotification::class,
            fn ($n) => $n->abiertas === 6 && $n->sector->is($sector));
        $this->assertNotNull($sector->fresh()->tope_avisado_at);
    }

    /** Sin tope cargado no avisa nunca, por más casos que tenga. */
    public function test_un_sector_sin_tope_nunca_avisa(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: null);
        $gerente = $this->gerenteDe($sector);
        $this->observaciones($sector, 50);

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertNothingSentTo($gerente);
    }

    // ── Qué cuenta ──────────────────────────────────────────────────────────

    /** Solo las abiertas: una cerrada y una cancelada no suman a la carga. */
    public function test_las_cerradas_y_canceladas_no_cuentan(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 2);
        $gerente = $this->gerenteDe($sector);
        $this->observaciones($sector, 2);
        $this->observaciones($sector, 3, estado: 'cerrada');
        $this->observaciones($sector, 3, estado: 'cancelada');

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertNothingSentTo($gerente);
    }

    /**
     * ⚠️ Una observación sin sector no es de nadie todavía: contarla inventaría
     * una saturación que ningún gerente puede resolver.
     */
    public function test_las_observaciones_sin_sector_no_cuentan(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 1);
        $gerente = $this->gerenteDe($sector);
        $this->observaciones($sector, 1);

        Observacion::create([
            'numero' => '9999-26', 'anio' => 2026, 'tipo' => 'falla_producto',
            'estado' => 'clasificada', 'sector_id' => null,
            'contacto_nombre' => 'X', 'contacto_email' => 'x@example.com',
            'titulo' => 'Sin sector', 'descripcion' => 'Sin sector',
        ]);

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertNothingSentTo($gerente);
    }

    /** La carga de un sector no arrastra a otro. */
    public function test_cada_sector_cuenta_lo_suyo(): void
    {
        Notification::fake();
        $saturado = $this->sector(tope: 1);
        $tranquilo = $this->sector(tope: 1);
        $gerenteSaturado = $this->gerenteDe($saturado);
        $gerenteTranquilo = $this->gerenteDe($tranquilo);

        $this->observaciones($saturado, 3);
        $this->observaciones($tranquilo, 1);

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertSentTo($gerenteSaturado, SectorSaturadoNotification::class);
        Notification::assertNothingSentTo($gerenteTranquilo);
    }

    // ── Idempotencia ────────────────────────────────────────────────────────

    public function test_correrlo_dos_veces_avisa_una_sola(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 1);
        $gerente = $this->gerenteDe($sector);
        $this->observaciones($sector, 3);

        $this->artisan('sectores:tope')->assertSuccessful();
        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertSentToTimes($gerente, SectorSaturadoNotification::class, 1);
    }

    /**
     * ⚠️ Lo que hace que el mecanismo sirva más de una vez: al bajar del tope se
     * limpia la marca, y la próxima saturación vuelve a avisar. Sin esto el
     * sector avisaría una sola vez en su vida.
     */
    public function test_al_bajar_del_tope_se_limpia_la_marca_y_vuelve_a_avisar(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 1);
        $gerente = $this->gerenteDe($sector);
        $this->observaciones($sector, 3);

        $this->artisan('sectores:tope')->assertSuccessful();
        $this->assertNotNull($sector->fresh()->tope_avisado_at);

        // Se resuelven y el sector baja del tope.
        Observacion::where('sector_id', $sector->id)->update(['estado' => 'cerrada']);
        $this->artisan('sectores:tope')->assertSuccessful();
        $this->assertNull($sector->fresh()->tope_avisado_at);

        // Y vuelve a saturarse: tiene que avisar de nuevo.
        $this->observaciones($sector, 4);
        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertSentToTimes($gerente, SectorSaturadoNotification::class, 2);
    }

    // ── Destinatarios ───────────────────────────────────────────────────────

    /** El gerente que trabaja en el sector, aunque nadie le reporte todavía. */
    public function test_avisa_al_gerente_cargado_en_el_propio_sector(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 1);
        $gerente = User::factory()->create(['sector_id' => $sector->id, 'es_gerente' => true]);
        $this->observaciones($sector, 2);

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertSentTo($gerente, SectorSaturadoNotification::class);
    }

    /**
     * ⚠️ Sin jerarquía cargada el aviso **no se pierde**: cae a los super-admin.
     * Mismo criterio que el alta del portal y el recordatorio de las NC.
     */
    public function test_sin_gerente_cae_a_los_super_admin(): void
    {
        Notification::fake();
        $sector = $this->sector(tope: 1);
        $admin = User::factory()->create();
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->assignRole('super-admin');

        $this->observaciones($sector, 2);

        $this->artisan('sectores:tope')->assertSuccessful();

        Notification::assertSentTo($admin, SectorSaturadoNotification::class);
    }

    /**
     * ⚠️ El filtro de super-admin va con `whereHas` y nunca con el scope
     * `role()` de Spatie, que tira excepción si el rol no está creado. Una tarea
     * agendada no puede caerse por eso.
     */
    public function test_no_se_cae_si_no_hay_gerente_ni_rol_super_admin(): void
    {
        $sector = $this->sector(tope: 1);
        $this->observaciones($sector, 2);

        $this->artisan('sectores:tope')->assertSuccessful();

        // Nadie a quien avisarle: la marca no se sella, así que cuando alguien
        // cargue la jerarquía el aviso sale igual.
        $this->assertNull($sector->fresh()->tope_avisado_at);
    }

    // ── El contenido del aviso ──────────────────────────────────────────────

    public function test_el_aviso_dice_los_numeros_concretos(): void
    {
        $sector = $this->sector(tope: 5);
        $sector->update(['nombre' => 'Compras']);
        $gerente = $this->gerenteDe($sector);

        $notificacion = new SectorSaturadoNotification($sector->fresh(), 8);

        $this->assertSame(
            'Compras tiene 8 observaciones abiertas y su tope es 5.',
            $notificacion->toArray($gerente)['mensaje']
        );
        $this->assertSame('sector_saturado', $notificacion->toArray($gerente)['tipo']);
        $this->assertStringContainsString('Compras', $notificacion->toMail($gerente)->subject);
    }

    /** La campana tiene que funcionar sin worker: el canal `database` va en sync. */
    public function test_la_campana_no_depende_del_worker(): void
    {
        $sector = $this->sector(tope: 1);
        $notificacion = new SectorSaturadoNotification($sector, 5);

        $this->assertSame(['database', 'mail'], $notificacion->via($sector));
        $this->assertSame('sync', $notificacion->viaConnections()['database']);
    }
}
