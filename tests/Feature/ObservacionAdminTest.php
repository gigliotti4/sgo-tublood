<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\Sector;
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

    public function test_create_requiere_permiso_observaciones_edit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/observaciones/crear?origen=externa')->assertStatus(403);
    }

    public function test_create_rechaza_origen_invalido(): void
    {
        $user = $this->userWith('observaciones.edit');
        $this->actingAs($user)->get('/observaciones/crear?origen=marciano')->assertStatus(404);
    }

    public function test_store_crea_observacion_externa_con_sector_responsable_y_productos(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);
        $responsable = User::factory()->create();

        $this->actingAs($user)
            ->post('/observaciones', [
                'tipo' => 'falla_producto',
                'contacto_nombre' => 'Clínica Test',
                'contacto_email' => 'contacto@clinicatest.com',
                'titulo' => 'Producto con falla',
                'descripcion' => 'El producto llegó dañado.',
                'sector_id' => $sector->id,
                'responsable_id' => $responsable->id,
                'institucion' => 'Clínica Test',
                'provincia' => 'Córdoba',
                'productos' => [[
                    'producto' => 'Guía de infusión',
                    'codigo' => 'GUIA-123',
                    'cantidad_afectada' => 3,
                    'lote' => 'L-123',
                    'fecha_vencimiento' => '2027-01-01',
                    'numero_remito' => 'R-999',
                    'tipo_comprobante' => 'remito',
                ]],
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion = Observacion::first();
        $this->assertNotNull($observacion);
        $this->assertSame('externa', $observacion->origen);
        $this->assertSame('pendiente_clasificacion', $observacion->estado);
        $this->assertSame($sector->id, $observacion->sector_id);
        $this->assertSame($responsable->id, $observacion->responsable_id);
        $this->assertCount(1, $observacion->productos);
    }

    public function test_store_vincula_cliente_por_numero(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial']);
        $cliente = Cliente::create(['numero' => '777', 'razon_social' => 'Cliente Vinculado SA']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'tipo' => 'disconformidad_servicio',
                'contacto_nombre' => 'Cliente Vinculado SA',
                'contacto_email' => 'cv@example.com',
                'contacto_numero_cliente' => '777',
                'titulo' => 'Demora en la entrega',
                'descripcion' => 'Se demoró el envío.',
                'sector_id' => $sector->id,
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame($cliente->id, Observacion::first()->cliente_id);
    }

    public function test_store_requiere_sector(): void
    {
        $user = $this->userWith('observaciones.edit');

        $this->actingAs($user)
            ->post('/observaciones', [
                'tipo' => 'disconformidad_servicio',
                'contacto_nombre' => 'Sin Sector',
                'contacto_email' => 'ss@example.com',
                'titulo' => 'Reclamo',
                'descripcion' => 'Detalle.',
            ])
            ->assertSessionHasErrors('sector_id');
    }

    public function test_store_interna_crea_observacion_con_datos_especificos(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Facturación', 'slug' => 'facturacion']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'error_facturacion',
                'titulo' => 'Factura con importe mal',
                'descripcion' => 'El importe no coincide.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Documentación',
                'datos_especificos' => [
                    'tipo_comprobante' => 'Factura',
                    'numero_comprobante' => 'FA-0001',
                ],
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion = Observacion::first();
        $this->assertSame('interna', $observacion->origen);
        $this->assertSame('clasificada', $observacion->estado);
        $this->assertSame('error_facturacion', $observacion->tipo);
        $this->assertSame($sector->id, $observacion->sector_id);
        $this->assertSame('FA-0001', $observacion->datos_especificos['numero_comprobante']);
    }

    public function test_store_interna_rechaza_tipo_que_no_es_del_sector(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Facturación', 'slug' => 'facturacion']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'siniestro_chofer', // es de logística, no de facturación
                'titulo' => 'Título',
                'descripcion' => 'Detalle.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Otro',
            ])
            ->assertSessionHasErrors('tipo');
    }

    public function test_store_interna_valida_campo_especifico_obligatorio(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Facturación', 'slug' => 'facturacion']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'error_facturacion',
                'titulo' => 'Título',
                'descripcion' => 'Detalle.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Otro',
                'datos_especificos' => ['numero_comprobante' => 'FA-1'], // falta tipo_comprobante (required)
            ])
            ->assertSessionHasErrors('datos_especificos.tipo_comprobante');
    }

    public function test_update_permite_asignar_sector(): void
    {
        $user = $this->userWith('observaciones.view');
        $sector = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);

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
            ->put("/observaciones/{$observacion->id}", [
                'estado' => 'en_proceso',
                'sector_id' => $sector->id,
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame($sector->id, $observacion->fresh()->sector_id);
    }

    public function test_update_clasifica_al_completar_prioridad_y_tipo_caso(): void
    {
        $superAdmin = User::factory()->create();
        Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->assignRole('super-admin');

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'origen' => 'externa',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $this->actingAs($superAdmin)
            ->put("/observaciones/{$observacion->id}", [
                'estado' => 'pendiente_clasificacion',
                'prioridad' => 'alta',
                'tipo_caso' => 'Documentación',
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion->refresh();
        $this->assertSame('clasificada', $observacion->estado);
        $this->assertSame('alta', $observacion->prioridad);
        $this->assertSame('Documentación', $observacion->tipo_caso);
    }

    public function test_update_no_clasifica_si_falta_tipo_caso(): void
    {
        $superAdmin = User::factory()->create();
        Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->assignRole('super-admin');

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'origen' => 'externa',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $this->actingAs($superAdmin)
            ->put("/observaciones/{$observacion->id}", [
                'estado' => 'pendiente_clasificacion',
                'prioridad' => 'alta',
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame('pendiente_clasificacion', $observacion->fresh()->estado);
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
