<?php

namespace Tests\Feature;

use App\Jobs\SyncArticulosJob;
use App\Models\Articulo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ArticuloControllerTest extends TestCase
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

    public function test_index_requiere_permiso_articulos_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/articulos')->assertStatus(403);
    }

    public function test_index_accesible_con_permiso(): void
    {
        $user = $this->userWith('articulos.view');
        $this->actingAs($user)->get('/articulos')->assertStatus(200);
    }

    public function test_busqueda_filtra_por_descripcion(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA ESTERIL']);

        $user = $this->userWith('articulos.view');
        $response = $this->actingAs($user)->get('/articulos?search=AGUJA');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Admin/Articulos/Index')
            ->has('articulos.data', 1)
            ->where('articulos.data.0.codigo', 'RE-1631')
        );
    }

    public function test_sync_requiere_permiso_articulos_sync(): void
    {
        $user = $this->userWith('articulos.view');
        $this->actingAs($user)->post('/articulos/sync')->assertStatus(403);
    }

    public function test_sync_despacha_job_y_redirige(): void
    {
        Queue::fake();

        $user = $this->userWith('articulos.view', 'articulos.sync');
        $this->actingAs($user)
            ->post('/articulos/sync')
            ->assertRedirect('/articulos');

        Queue::assertPushed(SyncArticulosJob::class);
    }

    public function test_edit_requiere_permiso_articulos_edit(): void
    {
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view');

        $this->actingAs($user)->get("/articulos/{$articulo->id}/edit")->assertStatus(403);
    }

    public function test_update_setea_los_cuatro_campos_propios(): void
    {
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view', 'articulos.edit');

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", [
                'fecha_vencimiento' => '2030-10-06',
                'pm' => '236-80',
                'legajo' => '133',
                'observaciones' => 'Revisar con Calidad',
            ])
            ->assertRedirect(route('articulos.edit', $articulo));

        $articulo->refresh();
        $this->assertSame('2030-10-06', $articulo->fecha_vencimiento->toDateString());
        $this->assertSame('236-80', $articulo->pm);
        $this->assertSame('133', $articulo->legajo);
        $this->assertSame('Revisar con Calidad', $articulo->observaciones);
    }
}
