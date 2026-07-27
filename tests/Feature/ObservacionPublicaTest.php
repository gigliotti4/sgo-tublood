<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use App\Notifications\ObservacionRecibidaClienteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ObservacionPublicaTest extends TestCase
{
    use RefreshDatabase;

    private function datosFallaProducto(): array
    {
        return [
            'tipo' => 'falla_producto',
            'contacto_nombre' => 'Cliente de Prueba SA',
            'contacto_email' => 'cliente@example.com',
            'contacto_numero_cliente' => '123',
            'contacto_telefono' => '11-4444-5555',
            'titulo' => 'Producto llegó dañado',
            'descripcion' => 'El producto presenta un defecto de fábrica.',
            'institucion' => 'Hospital de Prueba',
            'provincia' => 'Buenos Aires',
            'productos' => [
                [
                    'producto' => 'Set de infusión',
                    'codigo' => 'SET-001',
                    'cantidad_afectada' => 5,
                    'tipo_presentacion' => 'unidades',
                    'lote' => 'L-2026-01',
                    'fecha_vencimiento' => '2027-01-01',
                    'numero_remito' => 'R-0001',
                    'tipo_comprobante' => 'remito',
                ],
            ],
        ];
    }

    public function test_formulario_publico_es_accesible_sin_autenticacion(): void
    {
        $this->get('/cargar-observacion')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Portal/CargarObservacion'));
    }

    public function test_envio_valido_crea_observacion_y_adjunto_y_redirige_a_confirmacion(): void
    {
        Storage::fake('local');

        $response = $this->post('/cargar-observacion', [
            ...$this->datosFallaProducto(),
            'attachments' => [UploadedFile::fake()->image('foto.jpg', 10, 10)->size(100)],
        ]);

        $this->assertDatabaseCount('observations', 1);

        $observacion = Observacion::first();
        $this->assertSame('pendiente_clasificacion', $observacion->estado);
        $this->assertSame('externa', $observacion->origen);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $observacion->numero);
        $this->assertCount(1, $observacion->attachments);
        $this->assertCount(1, $observacion->productos);
        $this->assertSame('Set de infusión', $observacion->productos->first()->producto);
        $this->assertSame('SET-001', $observacion->productos->first()->codigo);
        $this->assertSame('unidades', $observacion->productos->first()->tipo_presentacion);

        Storage::disk('local')->assertExists($observacion->attachments->first()->path);

        $response->assertRedirect(route('observaciones.public.confirmacion'));

        $this->followingRedirects()
            ->get(route('observaciones.public.confirmacion'))
            ->assertInertia(fn ($page) => $page
                ->component('Portal/ObservacionEnviada')
                ->where('numero', $observacion->numero)
            );
    }

    public function test_falla_producto_requiere_campos_condicionales(): void
    {
        $data = $this->datosFallaProducto();
        unset($data['productos'][0]['lote']);

        $this->post('/cargar-observacion', $data)
            ->assertSessionHasErrors('productos.0.lote');

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_permite_cargar_multiples_productos(): void
    {
        $data = $this->datosFallaProducto();
        $data['productos'][] = [
            'producto' => 'Catéter venoso',
            'codigo' => 'CAT-002',
            'cantidad_afectada' => 2,
            'tipo_presentacion' => 'bultos',
            'lote' => 'L-2026-02',
            'fecha_vencimiento' => '2027-03-01',
            'numero_remito' => 'R-0002',
            'tipo_comprobante' => 'factura',
        ];

        $this->post('/cargar-observacion', $data);

        $observacion = Observacion::first();
        $this->assertCount(2, $observacion->productos);
        $this->assertSame(['Set de infusión', 'Catéter venoso'], $observacion->productos->pluck('producto')->all());
    }

    public function test_asigna_el_sector_que_la_taxonomia_declara_para_el_tipo(): void
    {
        // La clase no seedea sectores: sin este registro no habría a qué apuntar.
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->post('/cargar-observacion', $this->datosFallaProducto());

        $this->assertSame($sector->id, Observacion::first()->sector_id);
    }

    public function test_disconformidad_de_servicio_tambien_entra_a_garantia_de_calidad(): void
    {
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->post('/cargar-observacion', [
            'tipo' => 'disconformidad_servicio',
            'contacto_nombre' => 'Cliente de Prueba SA',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Demora en la respuesta',
            'descripcion' => 'Nadie contestó el pedido.',
        ]);

        $this->assertSame($sector->id, Observacion::first()->sector_id);
    }

    /**
     * El bloque de productos del formulario se oculta cuando el tipo no es
     * "Falla de Producto", pero la fila vacía sigue viajando en el post. Sin
     * descartarla, `productos.*` la rechazaba campo por campo y el reclamo se
     * perdía: los errores caían en inputs ocultos, así que el cliente no veía
     * nada y creía que el formulario estaba roto.
     */
    public function test_disconformidad_de_servicio_ignora_la_fila_vacia_de_productos(): void
    {
        $this->post('/cargar-observacion', [
            'tipo' => 'disconformidad_servicio',
            'contacto_nombre' => 'Cliente de Prueba SA',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Demora en la respuesta',
            'descripcion' => 'Nadie contestó el pedido.',
            'institucion' => '',
            'provincia' => '',
            'equipamiento' => '',
            'ejecutivo_cuenta' => '',
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
            ->assertRedirect(route('observaciones.public.confirmacion'));

        $observacion = Observacion::first();
        $this->assertNotNull($observacion);
        $this->assertSame('disconformidad_servicio', $observacion->tipo);
        $this->assertCount(0, $observacion->productos);
    }

    public function test_sin_el_sector_cargado_el_reclamo_se_guarda_igual(): void
    {
        // El portal es público: un sector faltante no puede hacer perder un reclamo.
        $this->post('/cargar-observacion', $this->datosFallaProducto())
            ->assertRedirect(route('observaciones.public.confirmacion'));

        $this->assertNull(Observacion::first()->sector_id);
    }

    public function test_le_manda_el_acuse_de_recibo_al_cliente(): void
    {
        Notification::fake();

        $this->post('/cargar-observacion', $this->datosFallaProducto());

        Notification::assertSentOnDemand(
            ObservacionRecibidaClienteNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'cliente@example.com'
        );
    }

    public function test_avisa_al_equipo_de_garantia_de_calidad_por_rol_si_el_sector_no_tiene_a_nadie(): void
    {
        Notification::fake();

        // Sin el sector "garantia_calidad" cargado en la tabla, cae al rol.
        Role::firstOrCreate(['name' => 'garantia_calidad', 'guard_name' => 'web']);
        $calidad = User::factory()->create();
        $calidad->assignRole('garantia_calidad');
        $ajeno = User::factory()->create();

        $this->post('/cargar-observacion', $this->datosFallaProducto());

        Notification::assertSentTo($calidad, ObservacionExternaRecibidaNotification::class);
        Notification::assertNotSentTo($ajeno, ObservacionExternaRecibidaNotification::class);
    }

    public function test_avisa_al_sector_y_al_rol_de_garantia_de_calidad(): void
    {
        Notification::fake();

        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);
        $delSector = User::factory()->create(['sector_id' => $sector->id]);

        // Con el rol pero cargado en otro sector: también se tiene que enterar,
        // porque este mismo aviso alimenta el modal de reclamos nuevos del panel.
        Role::firstOrCreate(['name' => 'garantia_calidad', 'guard_name' => 'web']);
        $porRol = User::factory()->create();
        $porRol->assignRole('garantia_calidad');

        $ajeno = User::factory()->create();

        $this->post('/cargar-observacion', $this->datosFallaProducto());

        Notification::assertSentTo($delSector, ObservacionExternaRecibidaNotification::class);
        Notification::assertSentTo($porRol, ObservacionExternaRecibidaNotification::class);
        Notification::assertNotSentTo($ajeno, ObservacionExternaRecibidaNotification::class);
    }

    public function test_sin_nadie_en_garantia_de_calidad_el_reclamo_entra_igual(): void
    {
        Notification::fake();

        // El portal es público: que no haya destinatarios internos no puede
        // hacer fallar la carga del cliente.
        $this->post('/cargar-observacion', $this->datosFallaProducto())
            ->assertRedirect(route('observaciones.public.confirmacion'));

        $this->assertDatabaseCount('observations', 1);
        Notification::assertNothingSentTo(User::factory()->create());
    }

    public function test_confirmacion_sin_sesion_redirige_al_formulario(): void
    {
        $this->get('/observacion-enviada')
            ->assertRedirect(route('observaciones.public.create'));
    }

    public function test_vincula_cliente_cuando_el_numero_existe_en_la_tabla_local(): void
    {
        $cliente = Cliente::create([
            'numero' => '123',
            'razon_social' => 'Cliente de Prueba SA',
        ]);

        $this->post('/cargar-observacion', $this->datosFallaProducto());

        $this->assertSame($cliente->id, Observacion::first()->cliente_id);
    }

    public function test_no_vincula_cliente_cuando_el_numero_no_existe(): void
    {
        $this->post('/cargar-observacion', $this->datosFallaProducto());

        // El reclamo se guarda igual, pero sin cliente vinculado y conservando
        // el N° tipeado para que el equipo pueda revisarlo desde el admin.
        $observacion = Observacion::first();
        $this->assertNull($observacion->cliente_id);
        $this->assertSame('123', $observacion->contacto_numero_cliente);
    }
}
