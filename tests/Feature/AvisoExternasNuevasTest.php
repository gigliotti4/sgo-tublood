<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Modal que le avisa al equipo de Garantía de Calidad, al entrar al panel, que
 * entraron reclamos nuevos por el portal público.
 */
class AvisoExternasNuevasTest extends TestCase
{
    use RefreshDatabase;

    private function observacionExterna(string $numero = '0001-26'): Observacion
    {
        return Observacion::create([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => 'disconformidad_servicio',
            'estado' => 'pendiente_clasificacion',
            'origen' => 'externa',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Demora en la respuesta',
            'descripcion' => 'Nadie contestó el pedido.',
        ]);
    }

    private function usuarioConRolCalidad(): User
    {
        Role::firstOrCreate(['name' => 'garantia_calidad', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('garantia_calidad');

        return $user;
    }

    private function usuarioDelSectorCalidad(): User
    {
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        return User::factory()->create(['sector_id' => $sector->id]);
    }

    public function test_le_muestra_los_reclamos_sin_ver_a_quien_tiene_el_rol(): void
    {
        $user = $this->usuarioConRolCalidad();
        $observacion = $this->observacionExterna();
        $user->notify(new ObservacionExternaRecibidaNotification($observacion));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.externas', 1)
                ->where('notificaciones.externas.0.data.numero', '0001-26')
                ->where('notificaciones.externas.0.data.observacion_id', $observacion->id)
            );
    }

    /** El sector vale igual que el rol: son dos formas de nombrar al mismo equipo. */
    public function test_le_muestra_los_reclamos_sin_ver_a_quien_esta_en_el_sector(): void
    {
        $user = $this->usuarioDelSectorCalidad();
        $user->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna()));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.externas', 1));
    }

    public function test_no_le_muestra_nada_a_quien_no_es_de_calidad(): void
    {
        $ajeno = User::factory()->create();
        $ajeno->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna()));

        $this->actingAs($ajeno)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.externas', 0));
    }

    /**
     * El aviso en pantalla no puede depender de que haya un worker corriendo:
     * si esperara a la cola, alguien podría entrar al panel y no ver un reclamo
     * que ya está cargado. El mail sí se encola, que es la llamada HTTP a Resend
     * y lo único que justifica el worker.
     */
    public function test_el_aviso_en_pantalla_no_espera_a_la_cola(): void
    {
        // Sin esto la suite corre todo en `sync` y el test no probaría nada.
        config(['queue.default' => 'database']);

        $user = $this->usuarioConRolCalidad();
        $user->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna()));

        $this->assertCount(1, $user->fresh()->unreadNotifications);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_cerrar_el_modal_marca_los_avisos_como_vistos(): void
    {
        $user = $this->usuarioConRolCalidad();
        $user->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna()));

        $this->actingAs($user)
            ->post(route('notificaciones.externas.vistas'))
            ->assertRedirect();

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_cerrar_entrando_a_un_reclamo_lleva_al_detalle(): void
    {
        $user = $this->usuarioConRolCalidad();
        $observacion = $this->observacionExterna();
        $user->notify(new ObservacionExternaRecibidaNotification($observacion));

        $this->actingAs($user)
            ->post(route('notificaciones.externas.vistas'), ['observacion_id' => $observacion->id])
            ->assertRedirect(route('observaciones.show', $observacion));
    }

    /**
     * El aviso es para el momento de entrar al panel: un reclamo que llega
     * después de cerrado el modal espera al próximo ingreso en vez de
     * interrumpir la gestión en curso.
     */
    public function test_una_vez_cerrado_no_reaparece_en_la_misma_sesion(): void
    {
        $user = $this->usuarioConRolCalidad();
        $user->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna()));

        $this->actingAs($user)->post(route('notificaciones.externas.vistas'));

        $user->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna('0002-26')));

        $this->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.externas', 0));
    }

    public function test_el_siguiente_ingreso_vuelve_a_habilitar_el_aviso(): void
    {
        $user = $this->usuarioConRolCalidad();
        $user->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna()));

        $this->actingAs($user)->post(route('notificaciones.externas.vistas'));
        $this->post('/logout');

        $user->notify(new ObservacionExternaRecibidaNotification($this->observacionExterna('0002-26')));

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.externas', 1));
    }
}
