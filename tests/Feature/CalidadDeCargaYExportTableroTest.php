<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sector;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Alertas de calidad de carga y Excel del tablero con fecha de corte
 * (punto 20 del pedido del cliente del 6/11/2026).
 */
class CalidadDeCargaYExportTableroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SectorSeeder::class);
    }

    private function tablero(string ...$extra): User
    {
        return tap(User::factory()->create())->assignRole('tablero', ...$extra);
    }

    private function obs(string $numero, array $extra = []): Observacion
    {
        return Observacion::create([
            'numero' => $numero, 'anio' => 2026, 'tipo' => 'falla_proceso_dep', 'estado' => 'clasificada',
            'origen' => 'interna', 'contacto_nombre' => 'Test', 'contacto_email' => 't@example.com',
            'titulo' => "Caso {$numero}", 'descripcion' => 'Descripción', ...$extra,
        ]);
    }

    private function sector(string $slug): int
    {
        return Sector::where('slug', $slug)->value('id');
    }

    // ── Alertas ─────────────────────────────────────────────────────

    public function test_marca_el_sector_que_no_es_el_dueno_del_tipo(): void
    {
        $this->obs('0001-26', ['sector_id' => $this->sector('logistica')]);   // 4.4 es de Depósito
        $this->obs('0002-26', ['sector_id' => $this->sector('deposito')]);    // bien cargado

        $this->actingAs($this->tablero())->get('/dashboard')->assertInertia(fn ($p) => $p
            ->has('tablero.calidadDeCarga', 1)
            ->where('tablero.calidadDeCarga.0.regla', 'sector_distinto')
            ->where('tablero.calidadDeCarga.0.numero', '0001-26'));
    }

    public function test_marca_un_proveedor_cargado_como_cliente_aunque_cambie_la_escritura(): void
    {
        Proveedor::create(['razon_social' => 'AD FLEX S.A.', 'numero' => '10']);
        $cliente = Cliente::create(['numero' => '5', 'razon_social' => 'Ad Flex SA']);
        $this->obs('0001-26', ['cliente_id' => $cliente->id]);

        $this->actingAs($this->tablero())->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('tablero.calidadDeCarga.0.regla', 'proveedor_como_cliente'));
    }

    public function test_marca_un_reclamo_de_cliente_sin_cliente_pero_no_otros_tipos_de_caso(): void
    {
        $this->obs('0001-26', ['tipo_caso' => 'Queja/Reclamo de clientes o partes interesadas']);
        $this->obs('0002-26', ['tipo_caso' => 'Auditoría Interna']);

        $this->actingAs($this->tablero())->get('/dashboard')->assertInertia(fn ($p) => $p
            ->has('tablero.calidadDeCarga', 1)
            ->where('tablero.calidadDeCarga.0.regla', 'reclamo_sin_cliente')
            ->where('tablero.calidadDeCarga.0.numero', '0001-26'));
    }

    public function test_un_caso_bien_cargado_no_genera_alertas(): void
    {
        $cliente = Cliente::create(['numero' => '5', 'razon_social' => 'Laboratorio Mantel S.A.']);
        $this->obs('0001-26', [
            'sector_id' => $this->sector('deposito'), 'cliente_id' => $cliente->id,
            'tipo_caso' => 'Queja/Reclamo de clientes o partes interesadas',
        ]);

        $this->actingAs($this->tablero())->get('/dashboard')->assertInertia(fn ($p) => $p
            ->has('tablero.calidadDeCarga', 0)
            ->has('tablero.reglasDeCarga', 3));
    }

    // ── Excel con fecha de corte ────────────────────────────────────

    private function exportar(User $user, array $datos)
    {
        return $this->actingAs($user)->post('/dashboard/exportar', $datos);
    }

    /** @return Spreadsheet */
    private function libro($respuesta)
    {
        $ruta = tempnam(sys_get_temp_dir(), 'tab').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        return IOFactory::load($ruta);
    }

    public function test_el_excel_trae_la_hoja_de_corte_y_deja_afuera_lo_cargado_despues(): void
    {
        $a = $this->obs('0001-26');
        $b = $this->obs('0002-26');
        $a->forceFill(['created_at' => '2026-09-10 10:00:00'])->saveQuietly();
        $b->forceFill(['created_at' => '2026-10-20 10:00:00'])->saveQuietly();

        $res = $this->exportar($this->tablero(), [
            'ids' => [$a->id, $b->id], 'corte' => '2026-09-30', 'periodo' => 'septiembre',
            'filtros' => ['Sector: Depósito'],
        ])->assertOk();

        $libro = $this->libro($res);

        // La hoja de observaciones queda primera y activa; la 0002 (de octubre) no entra.
        $this->assertSame('Observaciones', $libro->getActiveSheet()->getTitle());
        $this->assertSame('0001-26', $libro->getSheetByName('Observaciones')->getCell('A2')->getValue());
        $this->assertNull($libro->getSheetByName('Observaciones')->getCell('A3')->getValue());

        $corte = collect($libro->getSheetByName('Corte')->toArray())->mapWithKeys(fn ($f) => [$f[0] => $f[1]]);
        $this->assertSame('30/09/2026', $corte['Fecha de corte']);
        $this->assertSame('septiembre', $corte['Período']);
        $this->assertSame('Sector: Depósito', $corte['Filtros']);
        $this->assertSame('1', (string) $corte['Observaciones']);
    }

    public function test_sin_el_permiso_del_tablero_no_se_exporta(): void
    {
        $o = $this->obs('0001-26');
        $interno = tap(User::factory()->create())->assignRole('usuario_interno');

        $this->exportar($interno, ['ids' => [$o->id]])->assertForbidden();
    }

    public function test_exige_al_menos_un_id(): void
    {
        $this->exportar($this->tablero(), ['ids' => []])->assertSessionHasErrors('ids');
    }

    /** La columna del responsable del fallo no existe para quien no puede verla, tampoco en este Excel. */
    public function test_el_excel_del_tablero_respeta_la_puerta_del_responsable_del_fallo(): void
    {
        $persona = Persona::create(['nombre' => 'Raúl Arce', 'activo' => true]);
        $o = $this->obs('0001-26', ['responsable_fallo_id' => $persona->id]);

        $comun = $this->libro($this->exportar($this->tablero(), ['ids' => [$o->id]]));
        $this->assertStringNotContainsString('Responsable del fallo', json_encode($comun->getSheetByName('Observaciones')->toArray()));
        $this->assertStringNotContainsString('Raúl Arce', json_encode($comun->getSheetByName('Observaciones')->toArray(), JSON_UNESCAPED_UNICODE));

        $calidad = $this->libro($this->exportar($this->tablero('garantia_calidad'), ['ids' => [$o->id]]));
        $this->assertStringContainsString('Raúl Arce', json_encode($calidad->getSheetByName('Observaciones')->toArray(), JSON_UNESCAPED_UNICODE));
    }
}
