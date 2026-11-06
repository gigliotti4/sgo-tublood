<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\ObservationProduct;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `observaciones:reclasificar`: pasa los casos ya cargados a los fallos externos.
 * Escribe sobre producción, así que lo que más importa es que simule por defecto.
 */
class ReclasificarObservacionesTest extends TestCase
{
    use RefreshDatabase;

    private Sector $calidad;

    private Sector $externos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calidad = Sector::create(['nombre' => 'Calidad', 'slug' => 'garantia_calidad']);
        $this->externos = Sector::create(['nombre' => 'Externos', 'slug' => 'externos']);
        Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);
        Sector::create(['nombre' => 'Compras', 'slug' => 'comex']);
        Sector::create(['nombre' => 'Depósito', 'slug' => 'deposito']);
    }

    private function caso(string $numero, string $tipo, array $attrs = []): Observacion
    {
        return Observacion::create(array_merge([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => $tipo,
            'estado' => 'clasificada',
            'origen' => 'externa',
            'tipo_caso' => 'Queja/Reclamo de clientes',
            'sector_id' => $this->calidad->id,
            'contacto_nombre' => 'Cliente',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Caso '.$numero,
            'descripcion' => 'Detalle.',
        ], $attrs));
    }

    private function producto(Observacion $o, string $codigo): void
    {
        ObservationProduct::create([
            'observation_id' => $o->id,
            'producto' => 'Producto '.$codigo,
            'codigo' => $codigo,
            'cantidad_afectada' => 1,
            'lote' => 'L1',
            'fecha_vencimiento' => '2027-01-01',
        ]);
    }

    public function test_por_defecto_solo_simula_y_no_escribe_nada(): void
    {
        $o = $this->caso('0011-26', 'disconformidad_servicio');

        $this->artisan('observaciones:reclasificar')
            ->expectsOutputToContain('Simulación')
            ->assertSuccessful();

        $o->refresh();
        $this->assertSame('disconformidad_servicio', $o->tipo);
        $this->assertSame($this->calidad->id, $o->sector_id);
        $this->assertSame(0, $o->historial()->where('accion', ObservationHistory::ACCION_TIPO)->count());
    }

    public function test_con_aplicar_la_0011_pasa_a_e1_y_sigue_siendo_reclamo_de_cliente(): void
    {
        $o = $this->caso('0011-26', 'disconformidad_servicio');

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true])->assertSuccessful();

        $o->refresh();
        $this->assertSame('falla_logistica_tercerizada', $o->tipo);
        $this->assertSame($this->externos->id, $o->sector_id);
        $this->assertSame(['transporte' => 'Sneider'], $o->datos_especificos);
        // Origen y tipo de caso no se tocan.
        $this->assertSame('externa', $o->origen);
        $this->assertSame('Queja/Reclamo de clientes', $o->tipo_caso);
    }

    public function test_cada_cambio_deja_su_rastro_en_la_bitacora(): void
    {
        $o = $this->caso('0011-26', 'disconformidad_servicio');

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true]);

        $cambio = $o->historial()->where('accion', ObservationHistory::ACCION_TIPO)->first();
        $this->assertNotNull($cambio);
        $this->assertNull($cambio->user_id);

        $nota = $o->historial()->where('accion', ObservationHistory::ACCION_SISTEMA)->first();
        $this->assertStringContainsString('Reclasificación', $nota->nota);
        $this->assertStringContainsString('Sneider', $nota->nota);
    }

    public function test_es_idempotente(): void
    {
        $o = $this->caso('0011-26', 'disconformidad_servicio');

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true]);
        $this->artisan('observaciones:reclasificar', ['--aplicar' => true])
            ->expectsOutputToContain('Reclasificadas: 0')
            ->assertSuccessful();

        $this->assertSame(1, $o->historial()->where('accion', ObservationHistory::ACCION_TIPO)->count());
    }

    public function test_una_falla_de_producto_de_reventa_pasa_a_e3_y_una_propia_no(): void
    {
        $reventa = $this->caso('0001-26', 'falla_producto', ['contacto_numero_cliente' => '1006']);
        $this->producto($reventa, 'RE-1521');

        $propia = $this->caso('0003-26', 'falla_producto');
        $this->producto($propia, 'KS030HE');

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame('falla_calidad_distribucion', $reventa->fresh()->tipo);
        $this->assertSame($this->externos->id, $reventa->fresh()->sector_id);
        // Los productos siguen ahí.
        $this->assertSame(1, $reventa->productos()->count());

        $this->assertSame('falla_producto', $propia->fresh()->tipo);
    }

    /** Mandar un caso al tipo equivocado es peor que dejarlo donde está. */
    public function test_lo_dudoso_no_se_toca_y_se_avisa(): void
    {
        $sinProductos = $this->caso('0043-26', 'falla_producto');
        $mezclado = $this->caso('0047-26', 'falla_producto');
        $this->producto($mezclado, 'RE-1');
        $this->producto($mezclado, 'KS030');
        $raro = $this->caso('0048-26', 'falla_producto');
        $this->producto($raro, 'XYZ-9');

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true])
            ->expectsOutputToContain('a confirmar')
            ->assertSuccessful();

        foreach ([$sinProductos, $mezclado, $raro] as $o) {
            $this->assertSame('falla_producto', $o->fresh()->tipo);
        }
    }

    public function test_solo_limita_a_los_numeros_pedidos(): void
    {
        $a = $this->caso('0011-26', 'disconformidad_servicio');
        $b = $this->caso('0020-26', 'disconformidad_servicio');

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true, '--solo' => ['0011-26']])->assertSuccessful();

        $this->assertSame('falla_logistica_tercerizada', $a->fresh()->tipo);
        $this->assertSame('disconformidad_servicio', $b->fresh()->tipo);
    }

    public function test_un_caso_que_no_se_puede_pasar_se_informa_y_no_frena_al_resto(): void
    {
        // La 0035 a E.1 funciona; el E.3 sin productos requeridos se rechaza.
        $ok = $this->caso('0035-26', 'disconformidad_servicio');
        $sinProductos = $this->caso('0001-26', 'falla_proceso_dep', ['sector_id' => Sector::where('slug', 'deposito')->first()->id]);

        config(['reclasificacion.casos' => [
            '0035-26' => ['tipo' => 'falla_logistica_tercerizada', 'datos' => [], 'motivo' => 'x'],
            '0001-26' => ['tipo' => 'falla_calidad_distribucion', 'datos' => [], 'motivo' => 'x'],
        ]]);

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true])
            ->expectsOutputToContain('no se puede')
            ->assertSuccessful();

        $this->assertSame('falla_logistica_tercerizada', $ok->fresh()->tipo);
        $this->assertSame('falla_proceso_dep', $sinProductos->fresh()->tipo);
    }

    public function test_avisa_si_falta_el_sector_externos(): void
    {
        $this->externos->delete();
        $o = $this->caso('0011-26', 'disconformidad_servicio');

        $this->artisan('observaciones:reclasificar', ['--aplicar' => true])
            ->expectsOutputToContain('falta el sector')
            ->assertSuccessful();

        $this->assertSame('disconformidad_servicio', $o->fresh()->tipo);
    }
}
