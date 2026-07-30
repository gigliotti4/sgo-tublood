<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Qué le muestra la campana a cada uno: los reclamos sin clasificar se recortan
 * al tipo que atiende cada rol, y los vencimientos de clientes tienen permiso
 * propio.
 */
class CampanaPorRolTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol): User
    {
        Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function observacion(string $tipo, string $numero): Observacion
    {
        return Observacion::create([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => $tipo,
            'estado' => 'pendiente_clasificacion',
            'origen' => 'externa',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => "Reclamo {$numero}",
            'descripcion' => 'Descripción de prueba',
        ]);
    }

    private function sinClasificarDe(User $user): array
    {
        $numeros = [];

        $this->actingAs($user)->get('/dashboard')->assertInertia(function ($page) use (&$numeros) {
            $numeros = collect($page->toArray()['props']['notificaciones']['sinClasificar'])
                ->pluck('numero')
                ->all();
        });

        return $numeros;
    }

    public function test_calidad_de_producto_solo_ve_las_fallas_de_producto(): void
    {
        $this->observacion('falla_producto', '0001-26');
        $this->observacion('disconformidad_servicio', '0002-26');

        $this->assertSame(['0001-26'], $this->sinClasificarDe($this->conRol('calidad_producto')));
    }

    public function test_calidad_de_servicio_solo_ve_las_disconformidades(): void
    {
        $this->observacion('falla_producto', '0001-26');
        $this->observacion('disconformidad_servicio', '0002-26');

        $this->assertSame(['0002-26'], $this->sinClasificarDe($this->conRol('calidad_servicio')));
    }

    /**
     * Quien es de Garantía de Calidad "a secas" mantiene la vista global: el
     * reparto por rol no puede dejar reclamos sin que los mire nadie mientras
     * se termina de cargar el equipo.
     */
    public function test_garantia_de_calidad_sigue_viendo_todos_los_tipos(): void
    {
        $this->observacion('falla_producto', '0001-26');
        $this->observacion('disconformidad_servicio', '0002-26');

        $numeros = $this->sinClasificarDe($this->conRol('garantia_calidad'));

        $this->assertEqualsCanonicalizing(['0001-26', '0002-26'], $numeros);
    }

    public function test_quien_no_atiende_reclamos_no_ve_ninguno(): void
    {
        $this->observacion('falla_producto', '0001-26');

        $this->assertSame([], $this->sinClasificarDe(User::factory()->create()));
    }

    /** Vale por sector, no solo por rol: es el criterio de User::esDeCalidad(). */
    public function test_el_sector_de_garantia_de_calidad_tambien_ve_todo(): void
    {
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);
        $user = User::factory()->create(['sector_id' => $sector->id]);

        $this->observacion('falla_producto', '0001-26');
        $this->observacion('disconformidad_servicio', '0002-26');

        $this->assertEqualsCanonicalizing(['0001-26', '0002-26'], $this->sinClasificarDe($user));
    }

    // ── Vencimientos de clientes ─────────────────────────────────────────────

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function clientePorVencer(): void
    {
        Cliente::create([
            'numero' => '123',
            'razon_social' => 'Cliente Por Vencer SA',
            'fecha_vencimiento' => now()->addDays(5),
        ]);
    }

    public function test_con_el_permiso_de_vencimientos_los_ve(): void
    {
        $this->clientePorVencer();

        $this->actingAs($this->userWith('clientes.vencimientos'))
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.vencimientos', 1));
    }

    /**
     * Ver la lista de clientes y seguir sus vencimientos son cosas distintas:
     * el aviso es tarea de una persona puntual.
     */
    public function test_clientes_view_solo_no_alcanza(): void
    {
        $this->clientePorVencer();

        $this->actingAs($this->userWith('clientes.view'))
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.vencimientos', 0));
    }

    public function test_calidad_de_servicio_ve_los_vencimientos(): void
    {
        $this->clientePorVencer();

        // El permiso le llega por el rol, que lo asigna el seeder.
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('calidad_servicio');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.vencimientos', 1));
    }
}
