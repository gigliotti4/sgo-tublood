<?php

namespace Tests\Feature;

use App\Jobs\SyncClientesJob;
use App\Models\User;
use App\Notifications\JobsFallandoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `cache:podar` y `colas:revisar-fallos` — las dos tareas de higiene que
 * salieron de inspeccionar producción el 29/9/2026.
 *
 * ⚠️ **La fecha se pincha en un lunes** con `travelTo()`, mismo criterio que
 * `AlertasObservacionTest` y `RecordatoriosNoConformidadTest`: los dos comandos
 * miran ventanas de tiempo, y un test que depende del día en que se corre falla
 * los viernes y nadie entiende por qué.
 */
class HigieneDeColasTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes. */
    private const HOY = '2026-10-19 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(self::HOY);
    }

    /**
     * ⚠️ Los tests corren con `CACHE_STORE=array` (`phpunit.xml`), donde no hay
     * tabla que podar. Para probar la poda de verdad hay que pararse en el
     * driver `database`, que es el que usan dev y producción.
     */
    private function usandoCacheEnBase(): void
    {
        config()->set('cache.default', 'database');
    }

    private function entradaDeCache(string $clave, int $expiraEnMinutos): void
    {
        DB::table('cache')->insert([
            'key' => $clave,
            'value' => 'lo que sea',
            'expiration' => now()->addMinutes($expiraEnMinutos)->getTimestamp(),
        ]);
    }

    private function jobFallido(string $clase, int $haceHoras = 1): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $clase, 'job' => $clase]),
            'exception' => 'Algo salió mal',
            'failed_at' => now()->subHours($haceHoras),
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $user->assignRole('super-admin');

        return $user;
    }

    // ── cache:podar ─────────────────────────────────────────────────────────

    /**
     * ⚠️ La regresión que importa: borrar de más obliga a recalcular el dataset
     * del tablero de Compras (~1 MB, varios segundos) para nada. Por eso esto
     * no es un `cache:clear`.
     */
    public function test_podar_borra_lo_vencido_y_deja_lo_vigente(): void
    {
        $this->usandoCacheEnBase();
        $this->entradaDeCache('compras:reposicion:v2:vieja', -60);
        $this->entradaDeCache('compras:reposicion:v2:tambien-vieja', -1);
        $this->entradaDeCache('compras:reposicion:v2:viva', 60);

        $this->artisan('cache:podar')->assertSuccessful();

        $quedan = DB::table('cache')->pluck('key')->all();

        $this->assertSame(['compras:reposicion:v2:viva'], $quedan);
    }

    public function test_podar_no_rompe_con_la_cache_vacia(): void
    {
        $this->usandoCacheEnBase();

        $this->artisan('cache:podar')->assertSuccessful();

        $this->assertSame(0, DB::table('cache')->count());
    }

    /**
     * Con otro driver no hay nada que podar y el comando **no toca la tabla**.
     * Importa: si alguna vez se mueve la caché a redis, esta tarea tiene que
     * volverse inofensiva sola, no borrar filas que ya no le corresponden.
     */
    public function test_con_otro_driver_no_toca_nada(): void
    {
        config()->set('cache.default', 'array');
        $this->entradaDeCache('quedate', -60);

        $this->artisan('cache:podar')->assertSuccessful();

        $this->assertSame(1, DB::table('cache')->count());
    }

    // ── colas:revisar-fallos ────────────────────────────────────────────────

    public function test_avisa_cuando_una_clase_pasa_su_umbral(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        // El umbral por defecto es 3.
        for ($i = 0; $i < 3; $i++) {
            $this->jobFallido('App\Jobs\SyncArticulosJob');
        }

        $this->artisan('colas:revisar-fallos')->assertSuccessful();

        Notification::assertSentTo($admin, JobsFallandoNotification::class);
    }

    public function test_no_avisa_por_debajo_del_umbral(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $this->jobFallido('App\Jobs\SyncArticulosJob');
        $this->jobFallido('App\Jobs\SyncArticulosJob');

        $this->artisan('colas:revisar-fallos')->assertSuccessful();

        Notification::assertNothingSentTo($admin);
    }

    /**
     * ⚠️ El umbral propio de los ruidosos conocidos. `SyncClientesJob` falla
     * ~9 veces por día contra el ERP y se recupera solo: avisar con 3 sería
     * ruido diario garantizado, y en una semana nadie mira la campana.
     */
    public function test_un_job_ruidoso_conocido_tiene_su_propio_umbral(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        // 10 fallos: pasa largo el umbral por defecto (3), pero no el suyo (30).
        for ($i = 0; $i < 10; $i++) {
            $this->jobFallido(SyncClientesJob::class);
        }

        $this->artisan('colas:revisar-fallos')->assertSuccessful();

        Notification::assertNothingSentTo($admin);
    }

    public function test_el_job_ruidoso_si_avisa_cuando_se_dispara_de_verdad(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        for ($i = 0; $i < 30; $i++) {
            $this->jobFallido(SyncClientesJob::class);
        }

        $this->artisan('colas:revisar-fallos')->assertSuccessful();

        Notification::assertSentTo($admin, JobsFallandoNotification::class);
    }

    /** Lo viejo no cuenta: la ventana es de 24 h. */
    public function test_ignora_los_fallos_fuera_de_la_ventana(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->jobFallido('App\Jobs\SyncArticulosJob', haceHoras: 48);
        }

        $this->artisan('colas:revisar-fallos')->assertSuccessful();

        Notification::assertNothingSentTo($admin);
    }

    /**
     * ⚠️ Se filtra con `whereHas` y nunca con el scope `role()` de Spatie, que
     * tira excepción si el rol no existe. Una tarea agendada no puede caerse
     * por eso — y menos justo la que avisa de que algo anda mal.
     */
    public function test_no_se_cae_si_no_hay_super_admin_ni_rol(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->jobFallido('App\Jobs\SyncArticulosJob');
        }

        $this->artisan('colas:revisar-fallos')->assertSuccessful();
    }
}
