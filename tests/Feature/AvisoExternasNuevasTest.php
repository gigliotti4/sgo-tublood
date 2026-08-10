<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use App\Notifications\ObservacionVencidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dos capas independientes para el equipo de Garantía de Calidad:
 *
 * - "Sin clasificar" en la campana: consulta viva contra `observations`, se
 *   autolimpia sola cuando alguien clasifica el caso.
 * - El modal: el subconjunto de arriba de los que a este usuario le avisaron,
 *   que **insiste hasta clasificar** — cerrarlo (del lado del cliente, no hay
 *   endpoint) no marca nada en el servidor.
 */
class AvisoExternasNuevasTest extends TestCase
{
    use RefreshDatabase;

    private function observacionExterna(string $numero = '0001-26', string $estado = 'pendiente_clasificacion'): Observacion
    {
        return Observacion::create([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => 'disconformidad_servicio',
            'estado' => $estado,
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
                ->where('notificaciones.externas.0.id', $observacion->id)
                ->where('notificaciones.externas.0.numero', '0001-26')
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
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.externas', 0)
                ->has('notificaciones.sinClasificar', 0)
            );
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

    /**
     * El corazón de este rediseño: antes, cerrar el modal marcaba el aviso
     * leído en la base y lo apagaba para siempre. Ahora el "ya lo vi" es
     * estado del cliente (dura una carga de la app, ver AppLayout.vue) — del
     * lado del servidor el aviso insiste sin importar `read_at`, así que un
     * reclamo sin clasificar sigue entrando al modal en cada login/F5 aunque
     * su notificación ya esté leída.
     */
    public function test_una_notificacion_leida_igual_dispara_el_modal(): void
    {
        $user = $this->usuarioConRolCalidad();
        $observacion = $this->observacionExterna();
        $user->notify(new ObservacionExternaRecibidaNotification($observacion));

        $user->unreadNotifications->markAsRead();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.externas', 1)
                ->where('notificaciones.externas.0.id', $observacion->id)
            );
    }

    /**
     * Clasificar es lo único que de verdad resuelve el pendiente: tiene que
     * sacar la observación de la campana y del modal, sin tocar la
     * notificación (que ya puede estar leída o no, da igual).
     */
    public function test_clasificar_la_observacion_la_saca_de_la_campana_y_del_modal(): void
    {
        $user = $this->usuarioConRolCalidad();
        $observacion = $this->observacionExterna();
        $user->notify(new ObservacionExternaRecibidaNotification($observacion));

        $observacion->update(['estado' => 'clasificada']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.externas', 0)
                ->has('notificaciones.sinClasificar', 0)
            );
    }

    /**
     * Una observación puede haber sido borrada sin que su aviso se haya
     * marcado leído. Como "Sin clasificar" sale de una consulta viva contra
     * `observations`, esto se cumple estructuralmente: no hay ningún filtro
     * aparte que se pueda olvidar.
     */
    public function test_una_observacion_borrada_no_se_comparte(): void
    {
        $user = $this->usuarioConRolCalidad();
        $observacion = $this->observacionExterna();
        $user->notify(new ObservacionExternaRecibidaNotification($observacion));

        $observacion->delete();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.externas', 0)
                ->has('notificaciones.sinClasificar', 0)
            );
    }

    /**
     * El bloque de alertas de vencimiento/escalamiento no puede repetir lo que
     * ya muestra "Sin clasificar": son las mismas notificaciones si no se
     * excluyen por tipo.
     */
    public function test_la_campana_no_duplica_el_reclamo_en_alertas(): void
    {
        $user = $this->usuarioConRolCalidad();
        $externa = $this->observacionExterna();
        // Ya clasificada: una `ObservacionVencidaNotification` real solo se manda
        // sobre un caso con responsable asignado, que ya dejó `pendiente_clasificacion`.
        $vencida = $this->observacionExterna('0002-26', 'clasificada');

        $user->notify(new ObservacionExternaRecibidaNotification($externa));
        $user->notify(new ObservacionVencidaNotification($vencida));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.sinClasificar', 1)
                ->has('notificaciones.alertas', 1)
                ->where('notificaciones.alertas.0.data.numero', '0002-26')
            );
    }

    /**
     * "Marcar leídas" (el botón del bloque de alertas de vencimiento) y el
     * cierre del modal son mecanismos independientes: vaciar uno no puede
     * apagar de paso el otro.
     */
    public function test_marcar_leidas_no_afecta_el_aviso_de_reclamos_externos(): void
    {
        $user = $this->usuarioConRolCalidad();
        $externa = $this->observacionExterna();
        $vencida = $this->observacionExterna('0002-26', 'clasificada');

        $user->notify(new ObservacionExternaRecibidaNotification($externa));
        $user->notify(new ObservacionVencidaNotification($vencida));

        $this->actingAs($user)
            ->post(route('notificaciones.leidas'))
            ->assertRedirect();

        $this->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.alertas', 0)
                ->has('notificaciones.externas', 1)
                ->has('notificaciones.sinClasificar', 1)
            );
    }
}
