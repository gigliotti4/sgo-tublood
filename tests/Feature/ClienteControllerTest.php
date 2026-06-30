<?php

namespace Tests\Feature;

use App\Jobs\SyncClientesJob;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClienteControllerTest extends TestCase
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

    public function test_index_requiere_permiso_clientes_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/clientes')->assertStatus(403);
    }

    public function test_index_accesible_con_permiso(): void
    {
        $user = $this->userWith('clientes.view');
        $this->actingAs($user)->get('/clientes')->assertStatus(200);
    }

    public function test_busqueda_filtra_por_razon_social(): void
    {
        Cliente::create([
            'numero' => '1', 'razon_social' => 'Empresa Alpha SA',
            'cuit' => '30-1234-0',
        ]);
        Cliente::create([
            'numero' => '2', 'razon_social' => 'Distribuidora Beta SRL',
            'cuit' => '30-9999-0',
        ]);

        $user     = $this->userWith('clientes.view');
        $response = $this->actingAs($user)->get('/clientes?search=Alpha');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Admin/Clientes/Index')
                 ->has('clientes.data', 1)
                 ->where('clientes.data.0.razon_social', 'Empresa Alpha SA')
        );
    }

    public function test_sync_requiere_permiso_clientes_sync(): void
    {
        $user = $this->userWith('clientes.view');
        $this->actingAs($user)->post('/clientes/sync')->assertStatus(403);
    }

    public function test_sync_despacha_job_y_redirige(): void
    {
        Queue::fake();

        $user = $this->userWith('clientes.view', 'clientes.sync');
        $this->actingAs($user)
             ->post('/clientes/sync')
             ->assertRedirect('/clientes');

        Queue::assertPushed(SyncClientesJob::class);
    }
}
