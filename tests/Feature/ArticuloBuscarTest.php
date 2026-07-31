<?php

namespace Tests\Feature;

use App\Models\Articulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint del selector de productos. Es público (el portal de carga no tiene
 * login), así que lo que devuelve está deliberadamente acotado.
 */
class ArticuloBuscarTest extends TestCase
{
    use RefreshDatabase;

    private function articulo(array $attrs = []): Articulo
    {
        return Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'AGUJA 40/12 TERUMO',
            'codigo_barras' => '011011528-1',
            'stock' => 12,
            'stock_disponible' => 10,
            'codigo_proveedor' => 'PRO1',
            ...$attrs,
        ]);
    }

    public function test_busca_sin_estar_logueado(): void
    {
        $this->articulo();

        $this->getJson('/articulos/buscar?q=aguja')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['codigo' => 'RE-1631']);
    }

    public function test_encuentra_por_codigo_y_por_codigo_de_barras(): void
    {
        $this->articulo();

        $this->getJson('/articulos/buscar?q=RE-1631')->assertOk()->assertJsonCount(1);
        $this->getJson('/articulos/buscar?q=011011528')->assertOk()->assertJsonCount(1);
    }

    /**
     * Solo código y descripción: el endpoint es público y no tiene por qué
     * exponer stock, proveedor ni nada operativo.
     */
    public function test_no_expone_stock_ni_proveedor(): void
    {
        $this->articulo();

        $respuesta = $this->getJson('/articulos/buscar?q=aguja');

        $respuesta->assertOk();
        $this->assertSame(['codigo', 'descripcion'], array_keys($respuesta->json()[0]));
    }

    /**
     * Con menos de dos caracteres devolvería medio catálogo. Responde lista
     * vacía y no 422: para un autocompletado no es un error, es que todavía no
     * hay nada que sugerir.
     */
    public function test_con_menos_de_dos_caracteres_no_sugiere_nada(): void
    {
        $this->articulo();

        $this->getJson('/articulos/buscar?q=a')->assertOk()->assertExactJson([]);
        $this->getJson('/articulos/buscar?q=')->assertOk()->assertExactJson([]);
        $this->getJson('/articulos/buscar')->assertOk()->assertExactJson([]);
    }

    public function test_corta_en_veinte_resultados(): void
    {
        foreach (range(1, 25) as $i) {
            $this->articulo(['codigo' => "RE-{$i}", 'codigo_barras' => null]);
        }

        $this->getJson('/articulos/buscar?q=aguja')->assertOk()->assertJsonCount(20);
    }

    public function test_sin_resultados_devuelve_lista_vacia(): void
    {
        $this->articulo();

        $this->getJson('/articulos/buscar?q=inexistente')->assertOk()->assertExactJson([]);
    }
}
