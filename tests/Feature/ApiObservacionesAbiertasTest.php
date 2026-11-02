<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Integracion;
use App\Models\Observacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiObservacionesAbiertasTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $abilities = ['observaciones:leer']): string
    {
        return Integracion::firstOrCreate(['nombre' => 'Sistema X'])
            ->createToken('api', $abilities)->plainTextToken;
    }

    private function conToken(?string $token = null)
    {
        return $this->withToken($token ?? $this->token());
    }

    private function observacion(string $numero, ?Cliente $cliente, array $attrs = []): Observacion
    {
        return Observacion::create(array_merge([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'en_proceso',
            'contacto_nombre' => 'Persona Reclamante',
            'contacto_email' => 'reclamante@example.com',
            'contacto_telefono' => '11-5555-0000',
            'titulo' => 'Titulo '.$numero,
            'descripcion' => 'Descripcion secreta '.$numero,
            'cliente_id' => $cliente?->id,
        ], $attrs));
    }

    public function test_sin_token_es_401(): void
    {
        $this->getJson('/api/v1/clientes-con-observaciones')->assertUnauthorized();
        $this->getJson('/api/v1/clientes/1/observaciones')->assertUnauthorized();
    }

    public function test_token_inventado_es_401(): void
    {
        $this->withToken('1|inventado')->getJson('/api/v1/clientes-con-observaciones')->assertUnauthorized();
    }

    public function test_token_revocado_es_401(): void
    {
        $integracion = Integracion::create(['nombre' => 'Sistema X']);
        $plano = $integracion->createToken('api', ['observaciones:leer'])->plainTextToken;

        $integracion->tokens()->delete();

        $this->withToken($plano)->getJson('/api/v1/clientes-con-observaciones')->assertUnauthorized();
    }

    public function test_token_vencido_es_401(): void
    {
        $plano = Integracion::create(['nombre' => 'Sistema X'])
            ->createToken('api', ['observaciones:leer'], now()->subMinute())->plainTextToken;

        $this->withToken($plano)->getJson('/api/v1/clientes-con-observaciones')->assertUnauthorized();
    }

    public function test_token_sin_la_ability_es_403(): void
    {
        $this->conToken($this->token(['otra:cosa']))
            ->getJson('/api/v1/clientes-con-observaciones')
            ->assertForbidden();
    }

    public function test_la_sesion_del_panel_no_alcanza(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/clientes-con-observaciones')
            ->assertUnauthorized();
    }

    public function test_la_lista_trae_solo_clientes_con_abiertas_y_cuenta_bien(): void
    {
        $con = Cliente::create(['numero' => '100', 'razon_social' => 'Con Abiertas SA']);
        $soloCerradas = Cliente::create(['numero' => '200', 'razon_social' => 'Solo Cerradas SA']);
        Cliente::create(['numero' => '300', 'razon_social' => 'Sin Nada SA']);

        $this->observacion('0001-26', $con, ['estado' => 'en_proceso']);
        $this->observacion('0002-26', $con, ['estado' => 'derivada_nc']);
        $this->observacion('0003-26', $con, ['estado' => 'cerrada']);
        $this->observacion('0004-26', $soloCerradas, ['estado' => 'cerrada']);
        $this->observacion('0005-26', $soloCerradas, ['estado' => 'cancelada']);
        $this->observacion('0006-26', $con, ['estado' => 'en_proceso'])->delete();

        $r = $this->conToken()->getJson('/api/v1/clientes-con-observaciones')->assertOk();

        $this->assertSame([
            ['numero' => '100', 'razon_social' => 'Con Abiertas SA', 'observaciones_abiertas' => 2],
        ], $r->json('data'));
    }

    public function test_el_detalle_trae_solo_las_abiertas_y_solo_lo_basico(): void
    {
        $cliente = Cliente::create(['numero' => '100', 'razon_social' => 'Con Abiertas SA', 'mail' => 'interno@cliente.com']);
        $this->observacion('0001-26', $cliente, ['estado' => 'clasificada', 'prioridad' => 'critica']);
        $this->observacion('0002-26', $cliente, ['estado' => 'cerrada']);
        $this->observacion('0003-26', Cliente::create(['numero' => '999', 'razon_social' => 'Otro SA']));

        $r = $this->conToken()->getJson('/api/v1/clientes/100/observaciones')->assertOk();

        $this->assertSame('100', $r->json('data.cliente.numero'));
        $this->assertCount(1, $r->json('data.observaciones'));

        $o = $r->json('data.observaciones.0');
        $this->assertSame('0001-26', $o['numero']);
        $this->assertSame('clasificada', $o['estado']);
        $this->assertSame('Clasificada', $o['estado_label']);

        // Nada de lo que no se decidio exponer.
        $this->assertEqualsCanonicalizing(
            ['numero', 'tipo', 'tipo_label', 'estado', 'estado_label', 'prioridad', 'prioridad_label', 'titulo', 'creada_at', 'vence_at'],
            array_keys($o),
        );
        $crudo = $r->getContent();
        foreach (['Descripcion secreta', 'reclamante@example.com', '11-5555-0000', 'Persona Reclamante', 'interno@cliente.com'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo);
        }
    }

    public function test_cliente_sin_abiertas_devuelve_lista_vacia(): void
    {
        Cliente::create(['numero' => '300', 'razon_social' => 'Sin Nada SA']);

        $this->conToken()->getJson('/api/v1/clientes/300/observaciones')
            ->assertOk()
            ->assertJsonPath('data.observaciones', []);
    }

    public function test_numero_inexistente_es_404_json(): void
    {
        $this->conToken()->getJson('/api/v1/clientes/nope/observaciones')
            ->assertNotFound()
            ->assertJsonPath('message', 'Cliente no encontrado.');
    }

    public function test_el_comando_crea_un_token_con_la_ability_y_el_de_revocar_lo_borra(): void
    {
        $this->artisan('api:token-crear', ['nombre' => 'Sistema Y'])->assertSuccessful();

        $token = Integracion::where('nombre', 'Sistema Y')->firstOrFail()->tokens()->firstOrFail();
        $this->assertTrue($token->can('observaciones:leer'));

        $this->artisan('api:token-revocar', ['nombre' => 'Sistema Y'])->assertSuccessful();

        $this->assertSame(0, Integracion::where('nombre', 'Sistema Y')->firstOrFail()->tokens()->count());
    }

    public function test_revocar_una_integracion_inexistente_falla(): void
    {
        $this->artisan('api:token-revocar', ['nombre' => 'Fantasma'])->assertFailed();
    }

    public function test_vence_invalido_no_crea_nada(): void
    {
        $this->artisan('api:token-crear', ['nombre' => 'Sistema Z', '--vence' => 'mucho'])->assertFailed();

        $this->assertSame(0, Integracion::count());
    }
}
