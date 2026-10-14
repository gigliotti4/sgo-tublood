<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Compras es un rol aparte: el tablero lo ve solo quien tenga el rol `compras`
 * (más los super-admin), no todo el que tenga un rol general.
 *
 * Se prueba contra el seeder real porque es él quien define los roles en cada
 * deploy (`syncPermissions()`): un cambio hecho a mano en la pantalla de Roles
 * no dura.
 */
class RolComprasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_el_rol_compras_tiene_solo_los_permisos_de_compras(): void
    {
        $this->assertEqualsCanonicalizing(
            ['compras.view', 'compras.sync'],
            Role::findByName('compras')->permissions->pluck('name')->all(),
        );
    }

    /** Hasta el 14/10/2026 venía dentro de estos roles y así lo veía media empresa. */
    public function test_los_roles_generales_ya_no_traen_compras(): void
    {
        foreach (['usuario_interno', 'admin', 'viewer'] as $rol) {
            $permisos = Role::findByName($rol)->permissions->pluck('name');

            $this->assertFalse($permisos->contains('compras.view'), "{$rol} no debería ver Compras");
            $this->assertFalse($permisos->contains('compras.sync'), "{$rol} no debería sincronizar Compras");
        }
    }

    public function test_un_usuario_interno_no_entra_a_compras_y_con_el_rol_si(): void
    {
        $interno = tap(User::factory()->create())->assignRole('usuario_interno');
        $deCompras = tap(User::factory()->create())->assignRole(['usuario_interno', 'compras']);

        $this->actingAs($interno)->get('/compras')->assertForbidden();
        $this->actingAs($deCompras)->get('/compras')->assertOk();
    }
}
