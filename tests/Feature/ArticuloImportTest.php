<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ArticuloImportTest extends TestCase
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

    /** Arma un .xlsx real (no un fake genérico), la única forma de que PhpSpreadsheet lo pueda leer. */
    private function excelDeFilas(array $filas): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($filas as $i => $fila) {
            $sheet->fromArray($fila, null, 'A'.($i + 1));
        }

        $path = tempnam(sys_get_temp_dir(), 'excel').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'articulos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importar(User $admin, array $encabezado, array $filas)
    {
        return $this->actingAs($admin)->post('/articulos/import', [
            'archivo' => $this->excelDeFilas([$encabezado, ...$filas]),
        ]);
    }

    public function test_import_requiere_permiso_articulos_import(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/articulos/import', ['archivo' => $this->excelDeFilas([['cod_articulo'], ['RE-1']])])
            ->assertStatus(403);
    }

    public function test_importa_los_cuatro_campos_por_encabezado(): void
    {
        Articulo::create(['codigo' => 'RE-1982', 'descripcion' => 'GUANTE NITRILO M CORONET']);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'descrip_arti', 'pm', 'vto', 'observaciones'], [
            ['RE-1982', 'GUANTE NITRILO M CORONET', '236-80', '06/10/2030', 'ver ficha'],
        ])->assertRedirect('/articulos');

        $articulo = Articulo::where('codigo', 'RE-1982')->firstOrFail();
        $this->assertSame('236-80', $articulo->pm);
        $this->assertSame('2030-10-06', $articulo->fecha_vencimiento->toDateString());
        $this->assertSame('ver ficha', $articulo->observaciones);
    }

    /** El caso real de la planilla vieja: columnas con huecos entre medio (A, B, E, F). */
    public function test_resuelve_columnas_con_huecos_por_encabezado_y_no_por_posicion(): void
    {
        Articulo::create(['codigo' => 'RE-4488', 'descripcion' => 'RECOLECTOR DE ORINA ESTERIL']);
        $admin = $this->userWith('articulos.import');

        // C y D quedan vacías/irrelevantes, como en la planilla real.
        $this->importar($admin, ['cod_articulo', 'descrip_arti', 'columna_c', 'columna_d', 'pm', 'vto'], [
            ['RE-4488', 'RECOLECTOR DE ORINA ESTERIL', '', '', 'PM 833-12', '16/10/2029'],
        ])->assertRedirect('/articulos');

        $articulo = Articulo::where('codigo', 'RE-4488')->firstOrFail();
        $this->assertSame('2029-10-16', $articulo->fecha_vencimiento->toDateString());
    }

    /** El caso que motivó el cambio: "LEGAJO 133" en la columna PM va a legajo, no a pm. */
    public function test_legajo_en_la_columna_pm_cae_en_legajo(): void
    {
        Articulo::create(['codigo' => 'RE-4238', 'descripcion' => 'PAÑAL ADULTO EXTRA GRANDE']);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'descrip_arti', 'pm'], [
            ['RE-4238', 'PAÑAL ADULTO EXTRA GRANDE', 'LEGAJO 133'],
        ])->assertRedirect('/articulos');

        $articulo = Articulo::where('codigo', 'RE-4238')->firstOrFail();
        $this->assertNull($articulo->pm);
        $this->assertSame('133', $articulo->legajo);
    }

    /** Si el archivo trae una columna `legajo` propia, esa gana sobre lo que diga la columna PM. */
    public function test_columna_legajo_propia_tiene_prioridad_sobre_el_prefijo(): void
    {
        Articulo::create(['codigo' => 'RE-1982', 'descripcion' => 'GUANTE NITRILO M CORONET']);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'descrip_arti', 'pm', 'legajo'], [
            ['RE-1982', 'GUANTE NITRILO M CORONET', 'LEGAJO 999', '133'],
        ])->assertRedirect('/articulos');

        $articulo = Articulo::where('codigo', 'RE-1982')->firstOrFail();
        $this->assertSame('133', $articulo->legajo);
    }

    public function test_un_codigo_sin_match_deja_advertencia_y_no_crea_nada(): void
    {
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'descrip_arti'], [
            ['RE-9999-INEXISTENTE', 'ALGO QUE NO ESTÁ EN EL CATÁLOGO'],
        ]);

        $response->assertRedirect('/articulos');
        $this->assertStringContainsString(
            "el código 'RE-9999-INEXISTENTE' no está en el catálogo",
            $response->getSession()->get('error')
        );
        $this->assertSame(0, Articulo::count());
    }

    public function test_un_guion_en_vto_queda_null(): void
    {
        $articulo = Articulo::create([
            'codigo' => 'RE-4431',
            'descripcion' => 'BOMBA ELASTOMERICA',
            'fecha_vencimiento' => '2029-01-01',
        ]);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'descrip_arti', 'vto'], [
            ['RE-4431', 'BOMBA ELASTOMERICA', '-'],
        ])->assertRedirect('/articulos');

        $this->assertNull($articulo->fresh()->fecha_vencimiento);
    }

    public function test_sin_columna_de_codigo_el_archivo_se_rechaza_con_error_claro(): void
    {
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['descripcion', 'pm'], [
            ['GUANTE NITRILO M CORONET', '236-80'],
        ]);

        $response->assertRedirect('/articulos');
        $this->assertStringContainsString('columna de código', $response->getSession()->get('error'));
    }
}
