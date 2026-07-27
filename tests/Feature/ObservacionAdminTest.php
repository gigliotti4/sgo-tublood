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

    public function test_los_usuarios_asignables_traen_su_sector_para_mostrar_el_plazo(): void
    {
        $sector = Sector::create(['nombre' => 'Ventas', 'slug' => 'ventas', 'dias_gestion' => 2]);
        $user = $this->userWith('observaciones.view', 'observaciones.edit');
        $user->update(['sector_id' => $sector->id]);

        // De este eager-load salen dos cosas del formulario: el aviso de a los
        // cuántos días hábiles vence, y el filtro de responsables por sector.
        foreach (['/observaciones', '/observaciones/crear'] as $url) {
            $this->actingAs($user)->get($url)
                ->assertStatus(200)
                ->assertInertia(fn ($page) => $page
                    ->where('usuarios.0.sector.dias_gestion', 2)
                    ->where('usuarios.0.sector_id', $sector->id)
                );
        }
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
        $this->actingAs($user)->get('/observaciones/crear')->assertStatus(403);
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

    public function test_store_interna_falla_producto_en_garantia_calidad(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'falla_producto',
                'titulo' => 'Producto con falla',
                'descripcion' => 'El producto llegó dañado.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Producto defectuoso',
                'institucion' => 'Clínica Test',
                'provincia' => 'Córdoba',
                'productos' => [[
                    'producto' => 'Guía de infusión',
                    'codigo' => 'GUIA-123',
                    'cantidad_afectada' => 3,
                    'tipo_presentacion' => 'presentacion_venta',
                    'lote' => 'L-123',
                    'fecha_vencimiento' => '2027-01-01',
                    'numero_remito' => 'R-999',
                    'tipo_comprobante' => 'remito',
                ]],
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion = Observacion::first();
        $this->assertNotNull($observacion);
        $this->assertSame('interna', $observacion->origen);
        $this->assertSame('clasificada', $observacion->estado);
        $this->assertSame('falla_producto', $observacion->tipo);
        $this->assertSame($sector->id, $observacion->sector_id);
        $this->assertSame('Clínica Test', $observacion->institucion);
        $this->assertNull($observacion->contacto_nombre);
        $this->assertSame($user->id, $observacion->created_by);
        $this->assertCount(1, $observacion->productos);
    }

    /**
     * Espeja el equivalente del portal público: un reclamo cargado a mano por
     * teléfono muchas veces no tiene el remito a la vista.
     */
    public function test_store_interna_falla_producto_numero_de_remito_no_es_obligatorio(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'falla_producto',
                'titulo' => 'Producto con falla',
                'descripcion' => 'El producto llegó dañado.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Producto defectuoso',
                'institucion' => 'Clínica Test',
                'provincia' => 'Córdoba',
                'productos' => [[
                    'producto' => 'Guía de infusión',
                    'codigo' => 'GUIA-123',
                    'cantidad_afectada' => 3,
                    'tipo_presentacion' => 'presentacion_venta',
                    'lote' => 'L-123',
                    'fecha_vencimiento' => '2027-01-01',
                    'numero_remito' => '',
                    'tipo_comprobante' => '',
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('observaciones.index'));

        $producto = Observacion::first()->productos->first();
        $this->assertNull($producto->numero_remito);
        $this->assertNull($producto->tipo_comprobante);
    }

    public function test_store_interna_disconformidad_servicio_en_garantia_calidad(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'disconformidad_servicio',
                'titulo' => 'Demora en la entrega',
                'descripcion' => 'Se demoró el envío.',
                'prioridad' => 'media',
                'tipo_caso' => 'Demora logística',
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion = Observacion::first();
        $this->assertSame('disconformidad_servicio', $observacion->tipo);
        $this->assertSame('clasificada', $observacion->estado);
    }

    /** Espeja al portal público: la fila vacía del bloque oculto no puede frenar el alta. */
    public function test_store_interna_disconformidad_servicio_ignora_la_fila_vacia_de_productos(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'disconformidad_servicio',
                'titulo' => 'Demora en la entrega',
                'descripcion' => 'Se demoró el envío.',
                'prioridad' => 'media',
                'tipo_caso' => 'Demora logística',
                'productos' => [[
                    'producto' => '',
                    'codigo' => '',
                    'cantidad_afectada' => null,
                    'tipo_presentacion' => '',
                    'lote' => '',
                    'fecha_vencimiento' => '',
                    'numero_remito' => '',
                    'tipo_comprobante' => '',
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('observaciones.index'));

        $this->assertCount(0, Observacion::first()->productos);
    }

    public function test_store_interna_falla_producto_rechaza_sector_que_no_es_garantia_calidad(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Facturación', 'slug' => 'facturacion']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'falla_producto',
                'titulo' => 'Título',
                'descripcion' => 'Detalle.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Otro',
            ])
            ->assertSessionHasErrors('tipo');
    }

    public function test_store_interna_falla_producto_requiere_productos(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'falla_producto',
                'titulo' => 'Título',
                'descripcion' => 'Detalle.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Producto defectuoso',
                'institucion' => 'Clínica Test',
                'provincia' => 'Córdoba',
            ])
            ->assertSessionHasErrors('productos');
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

    public function test_store_interna_guarda_cliente_y_vincula_por_numero(): void
    {
        $cliente = Cliente::create([
            'numero' => '456',
            'razon_social' => 'Cliente Vinculable SA',
            'mail' => 'contacto@vinculable.com',
        ]);

        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'disconformidad_servicio',
                'titulo' => 'Reclamo cargado a mano',
                'descripcion' => 'Cliente disconforme con la entrega.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Producto defectuoso',
                'contacto_numero_cliente' => '456',
                'contacto_nombre' => 'Cliente Vinculable SA',
                'contacto_email' => 'contacto@vinculable.com',
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion = Observacion::first();
        $this->assertSame('456', $observacion->contacto_numero_cliente);
        $this->assertSame('Cliente Vinculable SA', $observacion->contacto_nombre);
        $this->assertSame('contacto@vinculable.com', $observacion->contacto_email);
        $this->assertSame($cliente->id, $observacion->cliente_id);
    }

    /** Alta mínima para los tests del buscador. */
    private function observacion(array $attrs = []): Observacion
    {
        static $correlativo = 0;
        $correlativo++;

        return Observacion::create([
            'numero' => sprintf('%04d-26', $correlativo),
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            ...$attrs,
        ]);
    }

    public function test_buscador_texto_libre_encuentra_por_codigo_de_producto(): void
    {
        $conProducto = $this->observacion(['titulo' => 'Con producto']);
        $conProducto->productos()->create([
            'producto' => 'Guía de infusión',
            'codigo' => 'GUIA-123',
            'cantidad_afectada' => 1,
            'lote' => 'L-1',
            'fecha_vencimiento' => '2027-01-01',
            'numero_remito' => 'R-1',
            'tipo_comprobante' => 'remito',
        ]);
        $this->observacion(['titulo' => 'Sin producto']);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get('/observaciones?q=GUIA-123')
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Con producto')
                ->where('filters.q', 'GUIA-123')
            );
    }

    public function test_buscador_filtra_abiertas_y_cerradas(): void
    {
        $this->observacion(['titulo' => 'En gestión', 'estado' => 'en_proceso']);
        $this->observacion(['titulo' => 'Terminada', 'estado' => 'cerrada']);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get('/observaciones?apertura=abierta')
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'En gestión')
            );

        $this->actingAs($user)->get('/observaciones?apertura=cerrada')
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Terminada')
            );
    }

    public function test_buscador_filtra_por_responsable_origen_y_fecha(): void
    {
        $responsable = User::factory()->create();
        $this->observacion([
            'titulo' => 'La buscada',
            'origen' => 'interna',
            'responsable_id' => $responsable->id,
        ]);
        $this->observacion(['titulo' => 'Otra', 'origen' => 'externa']);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)
            ->get("/observaciones?origen=interna&responsable_id={$responsable->id}&desde=".now()->toDateString())
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'La buscada')
            );

        // Un rango de fechas que no incluye hoy no devuelve nada.
        $this->actingAs($user)
            ->get('/observaciones?hasta='.now()->subDay()->toDateString())
            ->assertInertia(fn ($page) => $page->has('observaciones.data', 0));
    }

    public function test_buscador_filtra_por_creador(): void
    {
        $creador = User::factory()->create();
        $this->observacion(['titulo' => 'Cargada por él', 'created_by' => $creador->id]);
        $this->observacion(['titulo' => 'Del portal']);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get("/observaciones?creado_por={$creador->id}")
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Cargada por él')
            );
    }
}
