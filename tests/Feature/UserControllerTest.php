<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserControllerTest extends TestCase
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

    public function test_create_pasa_las_areas_con_su_plazo_al_formulario(): void
    {
        Area::create(['nombre' => 'Ventas', 'slug' => 'ventas', 'dias_gestion' => 2]);
        $admin = $this->userWith('users.create');

        $this->actingAs($admin)->get('/users/create')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Create')
                ->has('areas', 1)
                // El formulario avisa "vencen a los N días hábiles" con este dato.
                ->where('areas.0.dias_gestion', 2)
                ->has('usuarios')
            );
    }

    public function test_edit_excluye_al_propio_usuario_de_la_lista_de_supervisores(): void
    {
        $admin = $this->userWith('users.edit');
        $otro = User::factory()->create();

        $this->actingAs($admin)->get("/users/{$otro->id}/edit")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Edit')
                ->where('usuarios', fn ($usuarios) => collect($usuarios)->doesntContain('id', $otro->id))
            );
    }

    public function test_store_asigna_area_al_usuario(): void
    {
        $area = Area::create(['nombre' => 'Logística', 'slug' => 'logistica', 'dias_gestion' => 5]);
        $admin = $this->userWith('users.create');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@example.com',
            'password' => 'Password123!',
            'area_id' => $area->id,
            'roles' => [],
        ])->assertRedirect(route('users.index'));

        $this->assertSame($area->id, User::where('email', 'nuevo@example.com')->first()->area_id);
    }

    public function test_store_rechaza_area_inexistente(): void
    {
        $admin = $this->userWith('users.create');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@example.com',
            'password' => 'Password123!',
            'area_id' => 9999,
            'roles' => [],
        ])->assertSessionHasErrors('area_id');
    }

    public function test_update_cambia_el_area_del_usuario(): void
    {
        $areaInicial = Area::create(['nombre' => 'Ventas', 'slug' => 'ventas']);
        $areaNueva = Area::create(['nombre' => 'Compras', 'slug' => 'compras']);
        $admin = $this->userWith('users.edit');
        $usuario = User::factory()->create(['area_id' => $areaInicial->id]);

        $this->actingAs($admin)->put("/users/{$usuario->id}", [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'password' => '',
            'area_id' => $areaNueva->id,
            'roles' => [],
        ])->assertRedirect(route('users.index'));

        $this->assertSame($areaNueva->id, $usuario->fresh()->area_id);
    }

    public function test_update_guarda_supervisor_y_gerente(): void
    {
        $admin = $this->userWith('users.edit');
        $supervisor = User::factory()->create();
        $gerente = User::factory()->create(['es_gerente' => true]);
        $usuario = User::factory()->create();

        $this->actingAs($admin)->put("/users/{$usuario->id}", [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'password' => '',
            'supervisor_id' => $supervisor->id,
            'gerente_id' => $gerente->id,
            'roles' => [],
        ])->assertRedirect(route('users.index'));

        $usuario->refresh();
        $this->assertSame($supervisor->id, $usuario->supervisor_id);
        $this->assertSame($gerente->id, $usuario->gerente_id);
    }

    public function test_update_rechaza_supervisarse_a_si_mismo(): void
    {
        $admin = $this->userWith('users.edit');
        $usuario = User::factory()->create();

        $this->actingAs($admin)->put("/users/{$usuario->id}", [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'password' => '',
            'supervisor_id' => $usuario->id,
            'roles' => [],
        ])->assertSessionHasErrors('supervisor_id');
    }

    public function test_update_rechaza_un_circulo_de_escalamiento(): void
    {
        $admin = $this->userWith('users.edit');
        $jefe = User::factory()->create();
        $empleado = User::factory()->create(['supervisor_id' => $jefe->id]);

        // Poner al empleado como supervisor de su propio jefe cerraría el círculo.
        $this->actingAs($admin)->put("/users/{$jefe->id}", [
            'name' => $jefe->name,
            'email' => $jefe->email,
            'password' => '',
            'supervisor_id' => $empleado->id,
            'roles' => [],
        ])->assertSessionHasErrors('supervisor_id');
    }
}
