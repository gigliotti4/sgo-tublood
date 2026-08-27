<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\ObservationAttachment;
use App\Models\Proveedor;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        // De este eager-load sale el aviso de a los cuántos días hábiles vence:
        // el plazo es el del sector del responsable, y como se puede asignar a
        // cualquiera (la lista no se filtra por sector) es la única señal de qué
        // plazo va a aplicar.
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
                'estado' => 'cerrada',
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame('cerrada', $observacion->fresh()->estado);
    }

    public function test_update_rechaza_cerrar_a_quien_no_es_super_admin(): void
    {
        $user = $this->userWith('observaciones.view');

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'en_proceso',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $user->id,
                'estado' => 'cerrada',
            ])
            ->assertSessionHasErrors('estado');

        $this->assertSame('en_proceso', $observacion->fresh()->estado);
    }

    public function test_update_rechaza_cancelar_a_quien_no_es_super_admin(): void
    {
        $user = $this->userWith('observaciones.view');

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'en_proceso',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $user->id,
                'estado' => 'cancelada',
                'motivo' => 'Duplicado de otro caso',
            ])
            ->assertSessionHasErrors('estado');

        $this->assertSame('en_proceso', $observacion->fresh()->estado);
    }

    public function test_update_permite_a_no_admin_seguir_editando_un_caso_ya_cerrado(): void
    {
        $user = $this->userWith('observaciones.view');

        $observacion = Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'cerrada',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $user->id,
        ]);

        // Mantener el estado que ya tenía (cerrada) no es una transición: no
        // debería exigir super-admin, aunque el usuario no lo sea.
        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $user->id,
                'estado' => 'cerrada',
                'prioridad' => 'alta',
            ])
            ->assertRedirect(route('observaciones.index'));

        $this->assertSame('alta', $observacion->fresh()->prioridad);
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
                'tipo_caso' => 'Verificación de cumplimiento legal',
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
                'tipo_caso' => 'Desarrollo habitual de actividades',
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
                'tipo_caso' => 'Desarrollo habitual de actividades',
                'datos_especificos' => ['numero_comprobante' => 'FA-1'], // falta tipo_comprobante (required)
            ])
            ->assertSessionHasErrors('datos_especificos.tipo_comprobante');
    }

    public function test_store_interna_exige_numero_cliente_en_tipo_que_lo_requiere(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Facturación', 'slug' => 'facturacion']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'demora_facturacion',
                'titulo' => 'Título',
                'descripcion' => 'Detalle.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Desarrollo habitual de actividades',
            ])
            ->assertSessionHasErrors('contacto_numero_cliente');
    }

    public function test_store_interna_con_numero_cliente_vincula_cliente_existente(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Facturación', 'slug' => 'facturacion']);
        $cliente = Cliente::create(['numero' => '456', 'razon_social' => 'Cliente SA']);

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'demora_facturacion',
                'titulo' => 'Título',
                'descripcion' => 'Detalle.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Desarrollo habitual de actividades',
                'contacto_numero_cliente' => '456',
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion = Observacion::first();
        $this->assertSame($cliente->id, $observacion->cliente_id);
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
                'tipo_caso' => 'Devolución',
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
                'tipo_caso' => 'Devolución',
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

    /**
     * Igual que en el portal: el error de un adjunto vuelve en su índice y no
     * en la clave del array, así que sin mirar `attachments.N` el formulario se
     * negaba a enviarse sin mostrar nada.
     */
    public function test_store_interna_devuelve_el_error_del_adjunto_en_su_indice(): void
    {
        Storage::fake('local');

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
                'tipo_caso' => 'Monitoreo de Servicios',
                'attachments' => [
                    UploadedFile::fake()->create('ok.pdf', 100),
                    UploadedFile::fake()->create('enorme.pdf', 5000),
                ],
            ])
            ->assertSessionHasErrors('attachments.1');

        $this->assertDatabaseCount('observations', 0);
    }

    /**
     * Una falla al guardar vuelve como error de validación y no como 500: es lo
     * que mantiene el formulario cargado del otro lado (Inertia no navega ante
     * un 422). Se fuerza volteando la tabla hija, que se escribe después.
     */
    public function test_store_interna_avisa_si_el_guardado_se_cae(): void
    {
        $user = $this->userWith('observaciones.edit');
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        Schema::drop('observation_products');

        $this->actingAs($user)
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'falla_producto',
                'titulo' => 'Producto con falla',
                'descripcion' => 'El producto llegó dañado.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Devolución',
                'institucion' => 'Clínica Test',
                'provincia' => 'Córdoba',
                'productos' => [[
                    'producto' => 'Guía de infusión',
                    'codigo' => 'GUIA-123',
                    'cantidad_afectada' => 3,
                    'tipo_presentacion' => 'presentacion_venta',
                    'lote' => 'L-123',
                    'fecha_vencimiento' => '2027-01-01',
                ]],
            ])
            ->assertSessionHasErrors('guardado');

        $this->assertDatabaseCount('observations', 0);
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
                'tipo_caso' => 'Monitoreo de Servicios',
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
                'tipo_caso' => 'Monitoreo de Servicios',
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
                'tipo_caso' => 'Desarrollo habitual de actividades',
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
                'tipo_caso' => 'Devolución',
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
                'tipo_caso' => 'Verificación de cumplimiento legal',
            ])
            ->assertRedirect(route('observaciones.index'));

        $observacion->refresh();
        $this->assertSame('clasificada', $observacion->estado);
        $this->assertSame('alta', $observacion->prioridad);
        $this->assertSame('Verificación de cumplimiento legal', $observacion->tipo_caso);
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
                'tipo_caso' => 'Devolución',
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

    public function test_show_muestra_articulo_y_pm_cuando_el_codigo_matchea_el_catalogo(): void
    {
        Articulo::create(['codigo' => 'GUIA-123', 'descripcion' => 'Guía de infusión 2m', 'pm' => 'PM 236-80']);

        $observacion = $this->observacion();
        $observacion->productos()->create([
            'producto' => 'Guía de infusión',
            'codigo' => 'GUIA-123',
            'cantidad_afectada' => 1,
            'lote' => 'L-1',
            'fecha_vencimiento' => '2027-01-01',
            'numero_remito' => 'R-1',
            'tipo_comprobante' => 'remito',
        ]);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get("/observaciones/{$observacion->id}")
            ->assertInertia(fn ($page) => $page
                ->where('observacion.productos.0.articulo.descripcion', 'Guía de infusión 2m')
                ->where('observacion.productos.0.articulo.pm', 'PM 236-80')
            );
    }

    public function test_show_no_rompe_cuando_el_codigo_no_matchea_ningun_articulo(): void
    {
        $observacion = $this->observacion();
        $observacion->productos()->create([
            'producto' => 'Cargado a mano en el portal',
            'codigo' => 'CODIGO-INEXISTENTE',
            'cantidad_afectada' => 1,
            'lote' => 'L-1',
            'fecha_vencimiento' => '2027-01-01',
            'numero_remito' => 'R-1',
            'tipo_comprobante' => 'remito',
        ]);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get("/observaciones/{$observacion->id}")
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('observacion.productos.0.articulo', null)
            );
    }

    public function test_filtro_articulo_codigo_es_exacto_no_texto_libre(): void
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
        $otraObservacion = $this->observacion(['titulo' => 'Otro producto']);
        $otraObservacion->productos()->create([
            'producto' => 'Otra guía',
            'codigo' => 'GUIA-999',
            'cantidad_afectada' => 1,
            'lote' => 'L-2',
            'fecha_vencimiento' => '2027-01-01',
            'numero_remito' => 'R-2',
            'tipo_comprobante' => 'remito',
        ]);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get('/observaciones?'.http_build_query(['articulo_codigo' => ['GUIA-123']]))
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Con producto')
            );
    }

    public function test_export_genera_un_xlsx_con_las_filas_filtradas(): void
    {
        $this->observacion(['titulo' => 'Incluida', 'anio' => 2026]);
        $this->observacion(['titulo' => 'De otro año', 'anio' => 2025]);

        $user = $this->userWith('observaciones.view');

        $response = $this->actingAs($user)
            ->get('/observaciones/export?'.http_build_query(['anio' => [2026]]));

        $response->assertStatus(200);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type')
        );

        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($archivo, $response->streamedContent());

        $hoja = IOFactory::load($archivo)->getActiveSheet();
        $filas = $hoja->toArray();
        unlink($archivo);

        $this->assertSame('N°', $filas[0][0]);
        $this->assertCount(2, $filas); // encabezado + 1 fila de datos
        $this->assertSame('Incluida', $filas[1][3]);
    }

    public function test_export_requiere_permiso_observaciones_view(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/observaciones/export')->assertStatus(403);
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

        // El filtro va en notación de array: es un select múltiple ("cualquiera
        // de estos responsables"), no un único valor.
        $this->actingAs($user)
            ->get("/observaciones?origen=interna&responsable_id[]={$responsable->id}&desde=".now()->toDateString())
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'La buscada')
            );

        // Un rango de fechas que no incluye hoy no devuelve nada.
        $this->actingAs($user)
            ->get('/observaciones?hasta='.now()->subDay()->toDateString())
            ->assertInertia(fn ($page) => $page->has('observaciones.data', 0));
    }

    /** Elegir varios responsables es una unión: aparece lo de cualquiera de ellos. */
    public function test_buscador_filtra_por_varios_responsables_a_la_vez(): void
    {
        $responsableA = User::factory()->create();
        $responsableB = User::factory()->create();
        $this->observacion(['titulo' => 'De A', 'responsable_id' => $responsableA->id]);
        $this->observacion(['titulo' => 'De B', 'responsable_id' => $responsableB->id]);
        $this->observacion(['titulo' => 'De otro', 'responsable_id' => User::factory()->create()->id]);

        $user = $this->userWith('observaciones.view');

        $titulos = [];

        $this->actingAs($user)
            ->get("/observaciones?responsable_id[]={$responsableA->id}&responsable_id[]={$responsableB->id}")
            ->assertInertia(function ($page) use (&$titulos) {
                $page->has('observaciones.data', 2);
                $titulos = collect($page->toArray()['props']['observaciones']['data'])->pluck('titulo')->all();
            });

        $this->assertEqualsCanonicalizing(['De A', 'De B'], $titulos);
    }

    public function test_buscador_filtra_por_creador(): void
    {
        $creador = User::factory()->create();
        $this->observacion(['titulo' => 'Cargada por él', 'created_by' => $creador->id]);
        $this->observacion(['titulo' => 'Del portal']);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)->get("/observaciones?creado_por[]={$creador->id}")
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'Cargada por él')
            );
    }

    /** Mismo criterio de unión que responsable_id. */
    public function test_buscador_filtra_por_varios_creadores_a_la_vez(): void
    {
        $creadorA = User::factory()->create();
        $creadorB = User::factory()->create();
        $this->observacion(['titulo' => 'De A', 'created_by' => $creadorA->id]);
        $this->observacion(['titulo' => 'De B', 'created_by' => $creadorB->id]);
        $this->observacion(['titulo' => 'Del portal']);

        $user = $this->userWith('observaciones.view');

        $this->actingAs($user)
            ->get("/observaciones?creado_por[]={$creadorA->id}&creado_por[]={$creadorB->id}")
            ->assertInertia(fn ($page) => $page->has('observaciones.data', 2));
    }

    /**
     * De quién es el producto que falló. Sale del padrón en vivo
     * (`articulos.proveedor_id`), no de una copia guardada en la observación.
     */
    public function test_show_trae_el_proveedor_del_articulo(): void
    {
        $proveedor = Proveedor::create(['numero' => '1', 'razon_social' => 'PROPATO HNOS SAIC']);
        Articulo::create(['codigo' => 'GUIA-123', 'descripcion' => 'Guía', 'proveedor_id' => $proveedor->id]);

        $observacion = $this->observacion();
        $this->producto($observacion, 'GUIA-123');

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}")
            ->assertInertia(fn ($page) => $page
                ->where('observacion.productos.0.articulo.proveedor.razon_social', 'PROPATO HNOS SAIC'));
    }

    /** El artículo existe pero todavía no tiene proveedor cargado: no rompe. */
    public function test_show_deja_el_proveedor_en_null_si_el_articulo_no_lo_tiene(): void
    {
        Articulo::create(['codigo' => 'GUIA-123', 'descripcion' => 'Guía']);

        $observacion = $this->observacion();
        $this->producto($observacion, 'GUIA-123');

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('observacion.productos.0.articulo.proveedor', null));
    }

    /** Es el filtro al que lleva el ranking de proveedores del Dashboard. */
    public function test_el_listado_filtra_por_proveedor_del_articulo(): void
    {
        $proveedor = Proveedor::create(['numero' => '1', 'razon_social' => 'PROPATO HNOS SAIC']);
        $otro = Proveedor::create(['numero' => '2', 'razon_social' => 'BETA SRL']);

        Articulo::create(['codigo' => 'A-1', 'descripcion' => 'Aguja', 'proveedor_id' => $proveedor->id]);
        Articulo::create(['codigo' => 'B-1', 'descripcion' => 'Gasa', 'proveedor_id' => $otro->id]);

        $suya = $this->observacion(['titulo' => 'La de PROPATO']);
        $this->producto($suya, 'A-1');
        $this->producto($this->observacion(['titulo' => 'La otra']), 'B-1');

        $this->actingAs($this->userWith('observaciones.view'))
            ->get('/observaciones?proveedor_id[]='.$proveedor->id)
            ->assertInertia(fn ($page) => $page
                ->has('observaciones.data', 1)
                ->where('observaciones.data.0.titulo', 'La de PROPATO'));
    }

    private function producto(Observacion $observacion, string $codigo): void
    {
        $observacion->productos()->create([
            'producto' => 'Producto '.$codigo,
            'codigo' => $codigo,
            'cantidad_afectada' => 1,
            'lote' => 'L-1',
            'fecha_vencimiento' => '2027-01-01',
            'numero_remito' => 'R-1',
            'tipo_comprobante' => 'remito',
        ]);
    }

    /**
     * Producción de Apósitos: el tipo de campo `time` es nuevo, así que se
     * cubre de punta a punta — que guarde y que rechace una hora inexistente.
     */
    public function test_store_interna_guarda_las_horas_de_una_falla_de_maquina_de_apositos(): void
    {
        $sector = Sector::create(['nombre' => 'Producción', 'slug' => 'produccion']);

        $this->actingAs($this->userWith('observaciones.edit'))
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'falla_maquina_apositos',
                'titulo' => 'Paró la máquina de apósitos',
                'descripcion' => 'Se detuvo la línea.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Desarrollo habitual de actividades',
                'datos_especificos' => [
                    'motivo' => 'Rotura de la cinta',
                    'fecha' => '2026-08-19',
                    'hora_desde' => '07:30',
                    'hora_hasta' => '11:45',
                ],
            ])
            ->assertRedirect(route('observaciones.index'));

        $datos = Observacion::first()->datos_especificos;

        $this->assertSame('07:30', $datos['hora_desde']);
        $this->assertSame('11:45', $datos['hora_hasta']);
        $this->assertSame('Rotura de la cinta', $datos['motivo']);
    }

    public function test_store_interna_rechaza_una_hora_inexistente(): void
    {
        $sector = Sector::create(['nombre' => 'Producción', 'slug' => 'produccion']);

        $this->actingAs($this->userWith('observaciones.edit'))
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'falla_maquina_apositos',
                'titulo' => 'Paró la máquina',
                'descripcion' => 'Se detuvo la línea.',
                'prioridad' => 'alta',
                'tipo_caso' => 'Desarrollo habitual de actividades',
                'datos_especificos' => [
                    'motivo' => 'Rotura de la cinta',
                    'fecha' => '2026-08-19',
                    'hora_desde' => '25:00',
                ],
            ])
            ->assertSessionHasErrors('datos_especificos.hora_desde');

        $this->assertSame(0, Observacion::count());
    }

    /** Producción de Tubos: el Sí/No de falla de máquina es obligatorio. */
    public function test_store_interna_exige_el_si_no_de_falla_de_maquina_en_doble_etiquetado(): void
    {
        $sector = Sector::create(['nombre' => 'Producción', 'slug' => 'produccion']);

        $this->actingAs($this->userWith('observaciones.edit'))
            ->post('/observaciones', [
                'origen' => 'interna',
                'sector_id' => $sector->id,
                'tipo' => 'doble_etiquetado',
                'titulo' => 'Doble etiquetado en la OP 1234',
                'descripcion' => 'Salieron tubos con dos etiquetas.',
                'prioridad' => 'media',
                'tipo_caso' => 'Desarrollo habitual de actividades',
                'datos_especificos' => [
                    'op' => 'OP-1234',
                    'fecha' => '2026-08-19',
                ],
            ])
            ->assertSessionHasErrors('datos_especificos.falla_maquina');
    }

    /**
     * El PDF incrusta las imagenes adjuntas en base64 (DomPDF corre con
     * enable_remote=false, asi que una <img> con URL saldria vacia).
     */
    public function test_el_pdf_se_genera_con_una_imagen_adjunta(): void
    {
        Storage::fake('local');

        $observacion = $this->observacion();

        $path = UploadedFile::fake()->image('falla.jpg')->store('observaciones', 'local');

        ObservationAttachment::create([
            'observation_id' => $observacion->id,
            'path' => $path,
            'original_name' => 'falla.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
        ]);

        $response = $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /** Un adjunto que no es imagen no se incrusta, pero el PDF sigue saliendo. */
    public function test_el_pdf_se_genera_con_un_adjunto_que_no_es_imagen(): void
    {
        Storage::fake('local');

        $observacion = $this->observacion();

        $path = UploadedFile::fake()->create('informe.pdf', 100)->store('observaciones', 'local');

        ObservationAttachment::create([
            'observation_id' => $observacion->id,
            'path' => $path,
            'original_name' => 'informe.pdf',
            'mime_type' => 'application/pdf',
            'size' => 102400,
        ]);

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}/pdf")
            ->assertOk();
    }

    /**
     * Un adjunto cuya fila quedo en la base pero cuyo archivo ya no esta en
     * disco no puede tumbar la descarga del PDF.
     */
    public function test_el_pdf_no_se_rompe_si_falta_el_archivo_en_disco(): void
    {
        Storage::fake('local');

        $observacion = $this->observacion();

        ObservationAttachment::create([
            'observation_id' => $observacion->id,
            'path' => 'observaciones/no-existe.jpg',
            'original_name' => 'no-existe.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
        ]);

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}/pdf")
            ->assertOk();
    }
}
