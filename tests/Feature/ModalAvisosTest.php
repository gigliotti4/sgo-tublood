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
 * Se diferencian del bloque de reclamos externos en dos cosas: no hay ninguna
 * notificación de por medio (por eso se apagan solos cuando el caso termina) y
 * la marca de "ya lo vi" vive en la **sesión**, para que el recordatorio vuelva
 * en el próximo login mientras el caso siga abierto.
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
     * Cerrar el modal lo calla por el resto de la sesión, pero no toca la
     * observación: sigue abierta y va a volver a avisar en el próximo login.
     */
    public function test_cerrar_el_modal_lo_calla_en_la_misma_sesion(): void
    {
        $user = User::factory()->create();
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.asignadas', 1));

        $this->actingAs($user)->post(route('notificaciones.externas.vistas'));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.asignadas', 0));

        // La observación no se tocó: el aviso se calló, el caso sigue abierto.
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

    public function test_cerrar_el_modal_tambien_calla_el_seguimiento(): void
    {
        $user = User::factory()->create();
        $this->observacion()->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.seguimiento', 1));

        $this->actingAs($user)->post(route('notificaciones.externas.vistas'));

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.seguimiento', 0));
    }

    /**
     * El caso que motivó todo esto: alguien con el panel abierto que ya cerró el
     * modal y **después** recibe una asignación tiene que enterarse igual, sin
     * re-loguearse. Es lo que no pasaba cuando la marca de sesión era un
     * booleano ("ya abrí el modal") en vez del conjunto de IDs vistos.
     */
    public function test_una_asignacion_posterior_al_cierre_aparece_en_la_misma_sesion(): void
    {
        $user = User::factory()->create();
        $vieja = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)->get('/dashboard');
        $this->actingAs($user)->post(route('notificaciones.externas.vistas'));

        // Le asignan otra mientras sigue laburando, sin cerrar sesión.
        $nueva = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.asignadas', 1)
                ->where('notificaciones.asignadas.0.id', $nueva->id)
            );

        // Y la que ya había visto sigue callada: esa es la mitad que no hay que romper.
        $this->assertNotSame($vieja->id, $nueva->id);
    }

    public function test_un_seguimiento_posterior_al_cierre_tambien_aparece(): void
    {
        $user = User::factory()->create();
        $this->observacion()->notificados()->attach($user->id);

        $this->actingAs($user)->get('/dashboard');
        $this->actingAs($user)->post(route('notificaciones.externas.vistas'));

        $nueva = $this->observacion();
        $nueva->notificados()->attach($user->id);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.seguimiento', 1)
                ->where('notificaciones.seguimiento.0.id', $nueva->id)
            );
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
