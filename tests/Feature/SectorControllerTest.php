<?php

namespace Tests\Feature;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SectorControllerTest extends TestCase
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

    public function test_index_requiere_permiso_users_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/sectores')->assertStatus(403);
    }

    public function test_index_lista_los_sectores_con_su_cantidad_de_usuarios(): void
    {
        $sector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial', 'dias_gestion' => 2]);
        User::factory()->create(['sector_id' => $sector->id]);
        $user = $this->userWith('users.view');

        $this->actingAs($user)->get('/sectores')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Sectores/Index')
                ->has('sectores', 1)
                ->where('sectores.0.nombre', 'Comercial')
                ->where('sectores.0.usuarios_count', 1)
            );
    }

    public function test_store_requiere_permiso_users_edit(): void
    {
        $user = $this->userWith('users.view');

        $this->actingAs($user)->post('/sectores', [
            'nombre' => 'Nuevo Sector',
            'dias_gestion' => 3,
        ])->assertStatus(403);
    }

    public function test_store_crea_un_sector(): void
    {
        $admin = $this->userWith('users.edit');

        $this->actingAs($admin)->post('/sectores', [
            'nombre' => 'Nuevo Sector',
            'dias_gestion' => 3,
        ])->assertRedirect(route('sectores.index'));

        $sector = Sector::where('nombre', 'Nuevo Sector')->firstOrFail();
        $this->assertSame('nuevo-sector', $sector->slug);
        $this->assertSame(3, $sector->dias_gestion);
    }

    public function test_update_no_recalcula_el_slug_al_renombrar(): void
    {
        $admin = $this->userWith('users.edit');
        $sector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial', 'dias_gestion' => 2]);

        $this->actingAs($admin)->put("/sectores/{$sector->id}", [
            'nombre' => 'Comercial y Ventas',
            'dias_gestion' => 4,
        ])->assertRedirect(route('sectores.index'));

        $sector->refresh();
        $this->assertSame('Comercial y Ventas', $sector->nombre);
        // El slug es la clave con la que el import y config/incidencias.php
        // reconocen el sector: renombrar no puede romper esa referencia.
        $this->assertSame('comercial', $sector->slug);
        $this->assertSame(4, $sector->dias_gestion);
    }

    public function test_update_rechaza_un_nombre_duplicado(): void
    {
        $admin = $this->userWith('users.edit');
        Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial']);
        $otro = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);

        $this->actingAs($admin)->put("/sectores/{$otro->id}", [
            'nombre' => 'Comercial',
        ])->assertSessionHasErrors('nombre');
    }
}
