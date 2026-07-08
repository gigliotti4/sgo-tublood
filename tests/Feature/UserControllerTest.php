<?php

namespace Tests\Feature;

use App\Models\Sector;
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

    public function test_store_asigna_sector_al_usuario(): void
    {
        $sector = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);
        $admin = $this->userWith('users.create');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@example.com',
            'password' => 'Password123!',
            'sector_id' => $sector->id,
            'roles' => [],
        ])->assertRedirect(route('users.index'));

        $this->assertSame($sector->id, User::where('email', 'nuevo@example.com')->first()->sector_id);
    }

    public function test_store_rechaza_sector_inexistente(): void
    {
        $admin = $this->userWith('users.create');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@example.com',
            'password' => 'Password123!',
            'sector_id' => 9999,
            'roles' => [],
        ])->assertSessionHasErrors('sector_id');
    }

    public function test_update_cambia_el_sector_del_usuario(): void
    {
        $sectorInicial = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial']);
        $sectorNuevo = Sector::create(['nombre' => 'COMEX', 'slug' => 'comex']);
        $admin = $this->userWith('users.edit');
        $usuario = User::factory()->create(['sector_id' => $sectorInicial->id]);

        $this->actingAs($admin)->put("/users/{$usuario->id}", [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'password' => '',
            'sector_id' => $sectorNuevo->id,
            'roles' => [],
        ])->assertRedirect(route('users.index'));

        $this->assertSame($sectorNuevo->id, $usuario->fresh()->sector_id);
    }
}
