<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionAsignadaNotification;
use App\Notifications\ObservacionCreadaNotification;
use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los super-admin se enteran de **toda** alta, venga del portal o del panel.
 *
 * Lo dispara `ObservacionObserver::created()` y no los controllers porque hay
 * tres caminos de alta; estos tests fijan tanto que el aviso llegue como las
 * tres exclusiones que evitan que la misma persona reciba el mismo caso dos y
 * tres veces.
 */
class AvisoAltaSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => User::ROL_SUPER_ADMIN]);

        return tap(User::factory()->create())->assignRole(User::ROL_SUPER_ADMIN);
    }

    private function observacion(array $attrs = []): Observacion
    {
        return Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'clasificada',
            'origen' => 'interna',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            ...$attrs,
        ]);
    }

    private function datosDelPortal(): array
    {
        return [
            'tipo' => 'disconformidad_servicio',
            'contacto_nombre' => 'Cliente de Prueba SA',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Demora en la entrega',
            'descripcion' => 'El pedido llegó tarde.',
        ];
    }

    public function test_una_interna_le_avisa_a_todos_los_super_admin(): void
    {
        Notification::fake();
        $uno = $this->superAdmin();
        $otro = $this->superAdmin();

        $observacion = $this->observacion();

        foreach ([$uno, $otro] as $admin) {
            Notification::assertSentTo(
                $admin,
                ObservacionCreadaNotification::class,
                fn ($n) => $n->toArray($admin)['tipo'] === 'observacion_creada'
                    && $n->toArray($admin)['observacion_id'] === $observacion->id
                    // Campana + mail, que es lo pedido.
                    && $n->via($admin) === ['database', 'broadcast', 'mail'],
            );
        }
    }

    /** Avisarle a alguien de su propia acción es ruido. */
    public function test_no_le_avisa_al_super_admin_que_cargo_la_observacion(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin);
        $this->observacion(['created_by' => $admin->id]);

        Notification::assertNotSentTo($admin, ObservacionCreadaNotification::class);
    }

    /** Ya recibe el aviso de asignación: dos mails del mismo caso es ruido. */
    public function test_no_le_avisa_al_super_admin_que_es_el_responsable(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $this->observacion(['responsable_id' => $admin->id]);

        Notification::assertNotSentTo($admin, ObservacionCreadaNotification::class);
        Notification::assertSentTo($admin, ObservacionAsignadaNotification::class);
    }

    public function test_el_alta_del_portal_tambien_le_avisa(): void
    {
        Notification::fake();
        Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);
        $admin = $this->superAdmin();

        $this->post(route('observaciones.public.store'), $this->datosDelPortal())
            ->assertRedirect(route('observaciones.public.confirmacion'));

        Notification::assertSentTo($admin, ObservacionCreadaNotification::class);
    }

    /**
     * El super-admin que atiende ese tipo por rol ya recibe "entró un reclamo
     * nuevo": el aviso de alta sería el mismo caso contado dos veces.
     */
    public function test_no_le_avisa_al_super_admin_que_ya_recibe_el_aviso_del_tipo(): void
    {
        Notification::fake();
        Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);
        Role::firstOrCreate(['name' => 'calidad_servicio']);

        $admin = $this->superAdmin();
        $admin->assignRole('calidad_servicio');

        $this->post(route('observaciones.public.store'), $this->datosDelPortal())
            ->assertRedirect(route('observaciones.public.confirmacion'));

        Notification::assertSentTo($admin, ObservacionExternaRecibidaNotification::class);
        Notification::assertNotSentTo($admin, ObservacionCreadaNotification::class);
    }

    /**
     * El portal es público: buscar con el scope `User::role(...)` de Spatie
     * tiraría `RoleDoesNotExist` y devolvería un 500 al cliente. Por eso el
     * filtro va con `whereHas('roles', ...)`.
     */
    public function test_el_alta_del_portal_no_revienta_si_no_existe_el_rol_super_admin(): void
    {
        Notification::fake();
        Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->post(route('observaciones.public.store'), $this->datosDelPortal())
            ->assertRedirect(route('observaciones.public.confirmacion'));

        $this->assertDatabaseCount('observations', 1);
    }

    /**
     * La bandera apaga el aviso de asignación, no el de alta: son de otra gente
     * y de otro hecho. Antes cortaba el método entero con un `return`.
     */
    public function test_la_bandera_de_omitir_aviso_de_asignacion_no_apaga_el_aviso_a_super_admin(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $responsable = User::factory()->create();

        $observacion = new Observacion([
            'numero' => '0002-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'origen' => 'externa',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $responsable->id,
        ]);
        $observacion->omitirAvisoDeAsignacion = true;
        $observacion->save();

        Notification::assertNotSentTo($responsable, ObservacionAsignadaNotification::class);
        Notification::assertSentTo($admin, ObservacionCreadaNotification::class);
    }

    /**
     * `HandleInertiaRequests` excluye `ObservacionExternaRecibidaNotification`
     * de `notificaciones.alertas`. Este test fija que el aviso de alta **no**
     * caiga en esa exclusión y tenga dónde mostrarse.
     */
    public function test_el_aviso_aparece_en_la_campana(): void
    {
        $admin = $this->superAdmin();
        Permission::firstOrCreate(['name' => 'observaciones.view']);

        $this->observacion();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.alertas', 1)
                ->where('notificaciones.alertas.0.data.tipo', 'observacion_creada'));
    }
}
