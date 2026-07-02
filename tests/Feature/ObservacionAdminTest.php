<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ObservacionAdminTest extends TestCase
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

    public function test_index_requiere_permiso_observaciones_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/observaciones')->assertStatus(403);
    }

    public function test_index_accesible_con_permiso_y_lista_observaciones(): void
    {
        Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get('/observaciones')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Observaciones/Index')
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Título de prueba')
            );
    }

    public function test_index_incluye_usuarios_solo_con_permiso_de_edicion(): void
    {
        $userSinEdicion = $this->userWith('observaciones.view');

        $this->actingAs($userSinEdicion)->get('/observaciones')
            ->assertInertia(fn ($page) => $page->where('usuarios', []));

        $userConEdicion = $this->userWith('observaciones.view', 'observaciones.edit');

        $this->actingAs($userConEdicion)->get('/observaciones')
            ->assertInertia(fn ($page) => $page->has('usuarios', 2));
    }

    public function test_index_incluye_datos_del_cliente_vinculado(): void
    {
        $cliente = Cliente::create([
            'numero' => '123',
            'razon_social' => 'Cliente de Prueba SA',
            'mail' => 'contacto@clienteprueba.com',
            'telefono' => '11-4444-5555',
        ]);

        Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'cliente_id' => $cliente->id,
        ]);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get('/observaciones')
            ->assertInertia(fn ($page) => $page
                ->where('observaciones.data.0.cliente.razon_social', 'Cliente de Prueba SA')
                ->where('observaciones.data.0.cliente.mail', 'contacto@clienteprueba.com')
            );
    }

    public function test_update_requiere_permiso_observaciones_edit(): void
    {
        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->put("/observaciones/{$observacion->id}", [])->assertStatus(403);
    }

    public function test_update_asigna_responsable(): void
    {
        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $user = $this->userWith('observaciones.view', 'observaciones.edit');
        $responsable = User::factory()->create();

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $responsable->id,
                'estado' => 'pendiente_clasificacion',
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame($responsable->id, $observacion->fresh()->responsable_id);
    }

    public function test_update_cambia_el_estado(): void
    {
        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $user = $this->userWith('observaciones.view', 'observaciones.edit');

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => null,
                'estado' => 'en_proceso',
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame('en_proceso', $observacion->fresh()->estado);
    }

    public function test_update_rechaza_estado_invalido(): void
    {
        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $user = $this->userWith('observaciones.view', 'observaciones.edit');

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", ['estado' => 'no_existe'])
            ->assertSessionHasErrors('estado');
    }
}
