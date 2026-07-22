<?php

namespace Tests\Unit;

use App\Support\TaxonomiaIncidencias;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TaxonomiaIncidenciasTest extends TestCase
{
    /**
     * Taxonomía sintética: el test no depende del contenido real de
     * config/incidencias.php, que está pensado para editarse a mano.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('incidencias.sectores.sector_prueba', [
            'tipo_prueba' => [
                'codigo' => '9.9',
                'label' => 'Tipo de prueba',
                'campos' => [
                    ['id' => 'numero_comprobante', 'label' => 'Número de comprobante', 'tipo' => 'text', 'required' => true],
                    ['id' => 'lote_vencimiento', 'label' => 'Lote / Vencimiento', 'tipo' => 'text'],
                ],
            ],
        ]);
    }

    public function test_sector_de_tipo_encuentra_el_sector_dueno_del_tipo(): void
    {
        $this->assertSame('sector_prueba', TaxonomiaIncidencias::sectorDeTipo('tipo_prueba'));
    }

    public function test_sector_de_tipo_de_un_tipo_inexistente_es_null(): void
    {
        // El portal público depende de esto: si no hay sector, se guarda sin él
        // en vez de reventar.
        $this->assertNull(TaxonomiaIncidencias::sectorDeTipo('no_existe'));
    }

    public function test_atributos_validacion_deriva_los_labels_de_la_taxonomia(): void
    {
        $atributos = TaxonomiaIncidencias::atributosValidacion('sector_prueba', 'tipo_prueba');

        $this->assertSame([
            'datos_especificos.numero_comprobante' => 'número de comprobante',
            'datos_especificos.lote_vencimiento' => 'lote / vencimiento',
        ], $atributos);
    }

    public function test_atributos_validacion_de_un_tipo_inexistente_es_vacio(): void
    {
        $this->assertSame([], TaxonomiaIncidencias::atributosValidacion('sector_prueba', 'no_existe'));
    }

    public function test_los_errores_de_datos_especificos_usan_el_label_y_no_la_clave_tecnica(): void
    {
        $errores = Validator::make(
            ['datos_especificos' => []],
            TaxonomiaIncidencias::reglasValidacion('sector_prueba', 'tipo_prueba'),
            [],
            TaxonomiaIncidencias::atributosValidacion('sector_prueba', 'tipo_prueba')
        )->errors()->all();

        $this->assertContains('El campo número de comprobante es obligatorio.', $errores);
        $this->assertStringNotContainsString('datos_especificos', implode(' ', $errores));
    }
}
