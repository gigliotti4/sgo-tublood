<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'cantidad_afectada' => 5,
            'lote' => 'L-2026-01',
            'fecha_vencimiento' => '2027-01-01',
            'numero_remito' => 'R-0001',
            'tipo_comprobante' => 'remito',
            'institucion' => 'Hospital de Prueba',
            'provincia' => 'Buenos Aires',
            'producto' => 'Set de infusión',
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
        unset($data['lote']);

        $this->post('/cargar-observacion', $data)
            ->assertSessionHasErrors('lote');

        $this->assertDatabaseCount('observations', 0);
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

        $this->assertNull(Observacion::first()->cliente_id);
    }
}
