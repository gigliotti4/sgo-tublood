<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NotificacionesVencimientoTest extends TestCase
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

    public function test_incluye_clientes_vencidos_y_por_vencer_dentro_de_30_dias(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Vencido', 'fecha_vencimiento' => now()->subDays(5)]);
        Cliente::create(['numero' => '2', 'razon_social' => 'Por vencer', 'fecha_vencimiento' => now()->addDays(15)]);
        Cliente::create(['numero' => '3', 'razon_social' => 'Lejano', 'fecha_vencimiento' => now()->addDays(45)]);
        Cliente::create(['numero' => '4', 'razon_social' => 'Sin vencimiento']);

        $user = $this->userWith('clientes.view');

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('notificaciones.vencimientos', 2)
                ->where('notificaciones.vencimientos.0.razon_social', 'Vencido')
                ->where('notificaciones.vencimientos.1.razon_social', 'Por vencer')
            );
    }

    public function test_vacio_para_usuarios_sin_permiso_clientes_view(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Vencido', 'fecha_vencimiento' => now()->subDays(5)]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->has('notificaciones.vencimientos', 0));
    }
}
