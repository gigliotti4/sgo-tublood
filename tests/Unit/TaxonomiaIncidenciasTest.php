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

    public function test_un_campo_de_hora_valida_el_formato_y_no_acepta_una_fecha(): void
    {
        config()->set('incidencias.sectores.sector_prueba.tipo_prueba.campos', [
            ['id' => 'hora_desde', 'label' => 'Hora desde', 'tipo' => 'time'],
        ]);

        $reglas = TaxonomiaIncidencias::reglasValidacion('sector_prueba', 'tipo_prueba');

        $this->assertSame(['nullable', 'date_format:H:i'], $reglas['datos_especificos.hora_desde']);

        $valida = fn ($valor) => ! Validator::make(
            ['datos_especificos' => ['hora_desde' => $valor]],
            $reglas
        )->fails();

        $this->assertTrue($valida('07:30'));
        $this->assertFalse($valida('25:00'), 'una hora inexistente tiene que fallar');
        // `date` la aceptaría; `date_format:H:i` no, que es el punto de usarlo.
        $this->assertFalse($valida('2026-08-19'), 'una fecha no es una hora');
    }

    /**
     * Invariante de la que dependen `etiquetasTipos()` y `sectorDeTipo()`: si
     * dos sectores comparten una clave, uno de los dos tipos queda invisible y
     * el otro se lo lleva puesto, sin ningún error.
     *
     * Corre contra la taxonomía **real** a propósito — es el archivo que se
     * edita a mano y donde el choque se puede colar.
     */
    public function test_ningun_tipo_de_la_taxonomia_real_se_repite_entre_sectores(): void
    {
        // El setUp inyecta un sector sintético; acá interesa solo el config real.
        $this->refreshApplication();

        $sectores = config('incidencias.sectores');

        // Si `refreshApplication()` dejara el config vacío, lo de abajo pasaría
        // sin mirar nada: este assert es el que hace que el test sirva.
        $this->assertArrayHasKey('produccion', $sectores);

        $vistos = [];
        $repetidos = [];

        foreach ($sectores as $sector => $tipos) {
            foreach (array_keys($tipos) as $tipo) {
                if (isset($vistos[$tipo])) {
                    $repetidos[] = "{$tipo} (en {$vistos[$tipo]} y en {$sector})";
                }

                $vistos[$tipo] = $sector;
            }
        }

        $this->assertSame([], $repetidos, 'hay claves de tipo repetidas entre sectores');
    }
}
