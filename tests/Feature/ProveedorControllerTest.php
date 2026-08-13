<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProveedorControllerTest extends TestCase
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

    private function proveedor(array $attrs = []): Proveedor
    {
        return Proveedor::create(array_merge([
            'numero' => '933',
            'razon_social' => 'ANTONIO LUQUIN S A C I F E I',
            'domicilio' => 'GRAL GUEMES 897',
        ], $attrs));
    }

    public function test_el_listado_requiere_permiso_proveedores_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/proveedores')
            ->assertStatus(403);
    }

    public function test_el_listado_muestra_los_proveedores(): void
    {
        $this->proveedor();
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Proveedores/Index')
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.numero', '933')
                ->where('proveedores.data.0.razon_social', 'ANTONIO LUQUIN S A C I F E I'));
    }

    public function test_el_buscador_filtra_por_razon_social_y_por_numero(): void
    {
        $this->proveedor();
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL', 'domicilio' => 'LISANDRO DE LA TORRE 4101']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?search=CRONO')
            ->assertInertia(fn ($page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.razon_social', 'CRONOINK SRL'));

        $this->actingAs($user)
            ->get('/proveedores?search=933')
            ->assertInertia(fn ($page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.numero', '933'));
    }

    /** El contador del encabezado necesita el total sin filtrar, no el del paginador. */
    public function test_el_listado_manda_el_total_sin_filtrar(): void
    {
        $this->proveedor();
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?search=CRONO')
            ->assertInertia(fn ($page) => $page
                ->where('total', 2)
                ->where('proveedores.total', 1));
    }

    public function test_editar_requiere_permiso_proveedores_edit(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)->get("/proveedores/{$proveedor->id}/edit")->assertStatus(403);
        $this->actingAs($user)->put("/proveedores/{$proveedor->id}", [
            'razon_social' => 'OTRA COSA',
        ])->assertStatus(403);
    }

    public function test_update_guarda_los_campos_propios_del_panel(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.edit');

        $this->actingAs($user)->put("/proveedores/{$proveedor->id}", [
            'razon_social' => 'ANTONIO LUQUIN S.A.',
            'domicilio' => 'GRAL GUEMES 897',
            'cuit' => '30-12345678-9',
            'telefono' => '11 4444-5555',
            'mail' => 'compras@luquin.com.ar',
            'localidad' => 'CABA',
            'observaciones' => 'Entrega los martes.',
        ])->assertRedirect("/proveedores/{$proveedor->id}/edit");

        $proveedor->refresh();
        $this->assertSame('ANTONIO LUQUIN S.A.', $proveedor->razon_social);
        $this->assertSame('30-12345678-9', $proveedor->cuit);
        $this->assertSame('compras@luquin.com.ar', $proveedor->mail);
        $this->assertSame('CABA', $proveedor->localidad);
    }

    /** El número es la clave del import: mandarlo en el request no tiene que cambiarlo. */
    public function test_update_no_cambia_el_numero(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.edit');

        $this->actingAs($user)->put("/proveedores/{$proveedor->id}", [
            'numero' => '9999',
            'razon_social' => 'ANTONIO LUQUIN S A C I F E I',
        ])->assertRedirect("/proveedores/{$proveedor->id}/edit");

        $this->assertSame('933', $proveedor->fresh()->numero);
    }

    public function test_el_buscador_requiere_permiso_proveedores_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/proveedores/buscar?q=CRONO')
            ->assertStatus(403);
    }

    public function test_el_buscador_devuelve_id_numero_y_razon_social(): void
    {
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->getJson('/proveedores/buscar?q=CRONO')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([['id', 'numero', 'razon_social']])
            ->assertJsonPath('0.razon_social', 'CRONOINK SRL');
    }

    /** Menos de 2 caracteres no es un error: es "todavía no hay nada que sugerir". */
    public function test_el_buscador_con_un_termino_corto_devuelve_lista_vacia(): void
    {
        $this->proveedor();
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->getJson('/proveedores/buscar?q=C')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_update_exige_razon_social(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.edit');

        $this->actingAs($user)
            ->put("/proveedores/{$proveedor->id}", ['razon_social' => ''])
            ->assertSessionHasErrors('razon_social');
    }
}
