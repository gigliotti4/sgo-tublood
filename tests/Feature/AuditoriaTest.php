<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Vista transversal de la bitácora (`observation_history` de todas las
 * observaciones a la vez), gateada con su propio permiso `auditoria.view`.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function observacion(array $atributos = []): Observacion
    {
        return Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            ...$atributos,
        ]);
    }

    public function test_requiere_el_permiso_auditoria_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/auditoria')
            ->assertStatus(403);
    }

    /**
     * `observaciones.view` es el permiso de otra pantalla (el listado de
     * casos). Ver quién hizo qué en todo el sistema es una capacidad más
     * sensible, con su propio permiso — no alcanza con el de observaciones.
     */
    public function test_observaciones_view_no_alcanza(): void
    {
        $this->actingAs($this->userWith('observaciones.view'))
            ->get('/auditoria')
            ->assertStatus(403);
    }

    public function test_con_el_permiso_lista_entradas_de_todas_las_observaciones(): void
    {
        $this->travelTo(now()->subMinute());
        $a = $this->observacion(['numero' => '0001-26']);
        $a->update(['estado' => 'en_proceso']);

        $this->travelTo(now()->addMinutes(2));
        $b = $this->observacion(['numero' => '0002-26']);
        $b->update(['estado' => 'cerrada']);

        $this->actingAs($this->userWith('auditoria.view'))
            ->get('/auditoria')
            ->assertInertia(fn ($page) => $page
                ->has('entradas.data', 2)
                // Más reciente primero.
                ->where('entradas.data.0.observacion.numero', '0002-26')
                ->where('entradas.data.1.observacion.numero', '0001-26')
            );
    }

    public function test_filtra_por_accion(): void
    {
        $observacion = $this->observacion();
        $observacion->update(['estado' => 'en_proceso']);
        $observacion->update(['responsable_id' => User::factory()->create()->id]);

        $this->actingAs($this->userWith('auditoria.view'))
            ->get('/auditoria?accion=responsable')
            ->assertInertia(fn ($page) => $page
                ->has('entradas.data', 1)
                ->where('entradas.data.0.accion', 'responsable')
            );
    }

    public function test_filtra_por_usuario(): void
    {
        $user = $this->userWith('auditoria.view');
        $otro = User::factory()->create();

        $propia = $this->observacion(['numero' => '0001-26']);
        $this->actingAs($user);
        $propia->update(['estado' => 'en_proceso']);

        $ajena = $this->observacion(['numero' => '0002-26']);
        $this->actingAs($otro);
        $ajena->update(['estado' => 'en_proceso']);

        $this->actingAs($user)
            ->get("/auditoria?user_id={$user->id}")
            ->assertInertia(fn ($page) => $page
                ->has('entradas.data', 1)
                ->where('entradas.data.0.observacion.numero', '0001-26')
            );
    }

    /** Las entradas automáticas (sin sesión iniciada) quedan con user_id null. */
    public function test_filtra_por_usuario_sistema(): void
    {
        $conAutor = $this->observacion(['numero' => '0001-26']);
        $this->actingAs($this->userWith('auditoria.view'));
        $conAutor->update(['estado' => 'en_proceso']);
        Auth::logout();

        $sinAutor = $this->observacion(['numero' => '0002-26']);
        $sinAutor->update(['estado' => 'cerrada']);

        $this->actingAs($this->userWith('auditoria.view'))
            ->get('/auditoria?user_id=sistema')
            ->assertInertia(fn ($page) => $page
                ->has('entradas.data', 1)
                ->where('entradas.data.0.observacion.numero', '0002-26')
            );
    }

    public function test_filtra_por_sector(): void
    {
        $sector = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);
        $otroSector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial']);

        $delSector = $this->observacion(['numero' => '0001-26', 'sector_id' => $sector->id]);
        $delSector->update(['estado' => 'en_proceso']);

        $deOtro = $this->observacion(['numero' => '0002-26', 'sector_id' => $otroSector->id]);
        $deOtro->update(['estado' => 'en_proceso']);

        $this->actingAs($this->userWith('auditoria.view'))
            ->get("/auditoria?sector_id={$sector->id}")
            ->assertInertia(fn ($page) => $page
                ->has('entradas.data', 1)
                ->where('entradas.data.0.observacion.numero', '0001-26')
            );
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $this->travelTo(now()->subDays(10));
        $vieja = $this->observacion(['numero' => '0001-26']);
        $vieja->update(['estado' => 'en_proceso']);

        $this->travelTo(now()->addDays(10));
        $reciente = $this->observacion(['numero' => '0002-26']);
        $reciente->update(['estado' => 'en_proceso']);

        $this->actingAs($this->userWith('auditoria.view'))
            ->get('/auditoria?desde='.now()->subDays(2)->toDateString())
            ->assertInertia(fn ($page) => $page
                ->has('entradas.data', 1)
                ->where('entradas.data.0.observacion.numero', '0002-26')
            );
    }

    public function test_el_texto_libre_busca_en_la_nota_y_en_el_numero_de_observacion(): void
    {
        $user = $this->userWith('auditoria.view', 'observaciones.view');
        $observacion = $this->observacion(['numero' => '0055-26', 'responsable_id' => $user->id]);

        $this->actingAs($user)->post("/observaciones/{$observacion->id}/bitacora", [
            'nota' => 'Reclamo confirmado por el cliente vía telefónica.',
        ]);

        $this->actingAs($user)
            ->get('/auditoria?q=telefónica')
            ->assertInertia(fn ($page) => $page->has('entradas.data', 1));

        $this->actingAs($user)
            ->get('/auditoria?q=0055-26')
            ->assertInertia(fn ($page) => $page->has('entradas.data', 1));

        $this->actingAs($user)
            ->get('/auditoria?q=texto-que-no-aparece')
            ->assertInertia(fn ($page) => $page->has('entradas.data', 0));
    }
}
