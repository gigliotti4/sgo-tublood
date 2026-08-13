<?php

namespace Tests\Feature;

use App\Jobs\SyncArticulosJob;
use App\Models\Articulo;
use App\Models\Proveedor;
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

    public function test_update_asigna_y_limpia_el_proveedor(): void
    {
        $proveedor = Proveedor::create(['numero' => '1500', 'razon_social' => 'PROPATO HNOS S A I C']);
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view', 'articulos.edit');

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => $proveedor->id])
            ->assertRedirect(route('articulos.edit', $articulo));

        $this->assertSame($proveedor->id, $articulo->fresh()->proveedor_id);

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => null])
            ->assertRedirect(route('articulos.edit', $articulo));

        $this->assertNull($articulo->fresh()->proveedor_id);
    }

    public function test_update_rechaza_un_proveedor_inexistente(): void
    {
        $articulo = Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        $user = $this->userWith('articulos.view', 'articulos.edit');

        $this->actingAs($user)
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => 99999])
            ->assertSessionHasErrors('proveedor_id');
    }

    public function test_el_listado_trae_la_razon_social_del_proveedor(): void
    {
        $proveedor = Proveedor::create(['numero' => '1500', 'razon_social' => 'PROPATO HNOS S A I C']);
        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'AGUJA 40/12 TERUMO',
            'proveedor_id' => $proveedor->id,
        ]);
        $user = $this->userWith('articulos.view');

        $this->actingAs($user)
            ->get('/articulos')
            ->assertInertia(fn ($page) => $page
                ->where('articulos.data.0.proveedor.razon_social', 'PROPATO HNOS S A I C'));
    }
}
