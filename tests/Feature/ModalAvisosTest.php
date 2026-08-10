<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los dos bloques del modal de avisos que salen de una consulta viva: los casos
 * abiertos que cada persona tiene **a su cargo** (`asignadas`) y los que sigue
 * sin gestionar por estar en la lista de notificados (`seguimiento`).
 *
 * A diferencia del bloque de reclamos externos, acá no hay ninguna notificación
 * de por medio: se apagan solos cuando el caso llega a un estado terminal. El
 * servidor no tiene ningún "ya lo vi" para estos bloques — son consultas vivas
 * puras — así que insisten en cada entrada al panel (login, F5, pestaña nueva)
 * mientras el caso siga abierto; lo que evita que el modal se reabra al
 * navegar entre pantallas es estado del cliente (`avisosVistos` en
 * `AppLayout.vue`), fuera del alcance de estos tests.
 *
 * El bloque de reclamos externos tiene su propia suite en AvisoExternasNuevasTest.
 */
class ModalAvisosTest extends TestCase
{
    use RefreshDatabase;

    private function observacion(array $attrs = []): Observacion
    {
        static $correlativo = 0;
        $correlativo++;

        return Observacion::create(array_merge([
            'numero' => sprintf('%04d-26', $correlativo),
            'anio' => 2026,
            'tipo' => 'disconformidad_servicio',
            'estado' => 'en_proceso',
            'origen' => 'externa',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Demora en la respuesta',
            'descripcion' => 'Nadie contestó el pedido.',
        ], $attrs));
    }

    public function test_el_responsable_ve_sus_observaciones_abiertas(): void
    {
        $user = User::factory()->create();
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.asignadas', 1)
                ->where('notificaciones.asignadas.0.id', $observacion->id)
                ->where('notificaciones.asignadas.0.estado', 'en_proceso')
            );
    }

    /**
     * El corazón del pedido: el recordatorio se calla solo cuando el caso llega
     * a un estado terminal, sin que nadie tenga que marcar nada.
     */
    public function test_una_cerrada_o_una_cancelada_ya_no_aparecen(): void
    {
        $user = User::factory()->create();

        $this->observacion(['responsable_id' => $user->id, 'estado' => 'cerrada']);
        $this->observacion(['responsable_id' => $user->id, 'estado' => 'cancelada']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.asignadas', 0));
    }

    public function test_los_cuatro_estados_abiertos_aparecen(): void
    {
        $user = User::factory()->create();

        foreach (Observacion::ESTADOS_ABIERTOS as $estado) {
            $this->observacion(['responsable_id' => $user->id, 'estado' => $estado]);
        }

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.asignadas', count(Observacion::ESTADOS_ABIERTOS))
            );
    }

    public function test_solo_ve_las_propias(): void
    {
        $responsable = User::factory()->create();
        $ajeno = User::factory()->create();

        $this->observacion(['responsable_id' => $responsable->id]);

        $this->actingAs($ajeno)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.asignadas', 0));
    }

    /**
     * No hay ningún "ya lo vi" del lado del servidor para este bloque: cada
     * entrada al panel (login, F5, pestaña nueva) vuelve a traer el mismo caso
     * mientras siga abierto. Lo que evita que se repita al navegar entre
     * pantallas es estado del cliente (`avisosVistos` en AppLayout.vue), fuera
     * del alcance de este test — acá solo importa que el servidor no se calle.
     */
    public function test_una_asignacion_abierta_insiste_en_cada_entrada_al_panel(): void
    {
        $user = User::factory()->create();
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.asignadas', 1));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.asignadas', 1));

        // La observación no se tocó: sigue abierta.
        $this->assertSame('en_proceso', $observacion->fresh()->estado);
    }

    public function test_un_notificado_ve_el_caso_en_seguimiento(): void
    {
        $user = User::factory()->create();
        $observacion = $this->observacion(['responsable_id' => User::factory()->create()->id]);
        $observacion->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.seguimiento', 1)
                ->where('notificaciones.seguimiento.0.id', $observacion->id)
                ->has('notificaciones.asignadas', 0)
            );
    }

    /**
     * La regresión del NULL: `responsable_id != X` es falso en SQL cuando la
     * columna es NULL, así que sin el `orWhereNull` del middleware un caso sin
     * responsable se caería del bloque — justo el que más necesita que lo miren.
     */
    public function test_un_caso_sin_responsable_igual_aparece_en_seguimiento(): void
    {
        $user = User::factory()->create();
        $observacion = $this->observacion(['responsable_id' => null]);
        $observacion->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.seguimiento', 1)
                ->where('notificaciones.seguimiento.0.id', $observacion->id)
            );
    }

    /** Si además lo gestiona, va en "A tu cargo" y no se lista dos veces. */
    public function test_el_responsable_que_ademas_es_notificado_lo_ve_una_sola_vez(): void
    {
        $user = User::factory()->create();
        $observacion = $this->observacion(['responsable_id' => $user->id]);
        $observacion->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.asignadas', 1)
                ->has('notificaciones.seguimiento', 0)
            );
    }

    public function test_un_caso_terminado_no_aparece_en_seguimiento(): void
    {
        $user = User::factory()->create();

        foreach (['cerrada', 'cancelada'] as $estado) {
            $this->observacion(['estado' => $estado])->notificados()->attach($user->id);
        }

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.seguimiento', 0));
    }

    public function test_un_seguimiento_abierto_insiste_en_cada_entrada_al_panel(): void
    {
        $user = User::factory()->create();
        $this->observacion()->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.seguimiento', 1));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.seguimiento', 1));
    }

    /**
     * El caso que motivó todo esto: alguien con el panel abierto que recibe una
     * asignación nueva tiene que enterarse de las dos, sin que la vieja se
     * pierda — no hay ningún descarte del lado del servidor.
     */
    public function test_una_asignacion_posterior_se_suma_a_la_ya_asignada(): void
    {
        $user = User::factory()->create();
        $vieja = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)->get('/dashboard');

        // Le asignan otra mientras sigue laburando, sin cerrar sesión.
        $nueva = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.asignadas', 2));

        $this->assertNotSame($vieja->id, $nueva->id);
    }

    public function test_un_seguimiento_posterior_se_suma_al_ya_existente(): void
    {
        $user = User::factory()->create();
        $this->observacion()->notificados()->attach($user->id);

        $this->actingAs($user)->get('/dashboard');

        $nueva = $this->observacion();
        $nueva->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.seguimiento', 2));
    }

    /**
     * Los tres bloques son independientes: alguien de Calidad que además tiene
     * casos a su cargo y otros en seguimiento ve las tres listas en el modal.
     */
    public function test_los_tres_bloques_conviven(): void
    {
        Role::firstOrCreate(['name' => 'garantia_calidad', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('garantia_calidad');

        $sinClasificar = $this->observacion(['estado' => 'pendiente_clasificacion']);
        $user->notify(new ObservacionExternaRecibidaNotification($sinClasificar));

        $this->observacion(['responsable_id' => $user->id, 'estado' => 'clasificada']);

        $this->observacion(['estado' => 'derivada'])->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.externas', 1)
                ->has('notificaciones.asignadas', 1)
                ->has('notificaciones.seguimiento', 1)
            );
    }
}
