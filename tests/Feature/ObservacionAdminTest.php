<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    public function test_index_siempre_incluye_la_lista_de_usuarios(): void
    {
        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get('/observaciones')
            ->assertInertia(fn ($page) => $page->has('usuarios', 1));
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

    public function test_update_rechaza_a_usuario_no_asignado(): void
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

        // Aunque tenga el permiso observaciones.edit, no está asignado como responsable.
        $user = $this->userWith('observaciones.view', 'observaciones.edit');

        $this->actingAs($user)->put("/observaciones/{$observacion->id}", [])->assertStatus(403);
    }

    public function test_update_permite_al_responsable_asignado(): void
    {
        $user = $this->userWith('observaciones.view');

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $user->id,
        ]);

        $otroResponsable = User::factory()->create();

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $otroResponsable->id,
                'estado' => 'en_proceso',
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion->refresh();
        $this->assertSame($otroResponsable->id, $observacion->responsable_id);
        $this->assertSame('en_proceso', $observacion->estado);
    }

    public function test_update_permite_a_super_admin_aunque_no_sea_el_responsable(): void
    {
        $superAdmin = User::factory()->create();
        Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->assignRole('super-admin');

        $responsable = User::factory()->create();

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $responsable->id,
        ]);

        $this->actingAs($superAdmin)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $responsable->id,
                'estado' => 'resuelta',
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame('resuelta', $observacion->fresh()->estado);
    }

    public function test_update_rechaza_estado_invalido(): void
    {
        $user = $this->userWith('observaciones.view');

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", ['estado' => 'no_existe'])
            ->assertSessionHasErrors('estado');
    }
}
