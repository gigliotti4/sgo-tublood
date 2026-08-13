<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Proveedor;
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

    private function importar(User $admin, array $encabezado, array $filas, bool $crearFaltantes = false)
    {
        return $this->actingAs($admin)->post('/articulos/import', [
            'archivo' => $this->excelDeFilas([$encabezado, ...$filas]),
            'crear_faltantes' => $crearFaltantes,
        ]);
    }

    private function proveedor(string $numero, string $razonSocial): Proveedor
    {
        return Proveedor::create(['numero' => $numero, 'razon_social' => $razonSocial]);
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

    // --- Proveedor por razón social -------------------------------------

    /** Las dos planillas escriben la misma empresa distinto: con puntos y sin. */
    public function test_asigna_el_proveedor_matcheando_la_razon_social_con_otra_puntuacion(): void
    {
        $proveedor = $this->proveedor('1500', 'PROPATO HNOS S A I C');
        Articulo::create(['codigo' => 'RE-4680', 'descripcion' => 'DEA PAD ADULTO HEARTSTART']);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'descrip_arti', 'proveedor_principal'], [
            ['RE-4680', 'DEA PAD ADULTO HEARTSTART', 'PROPATO HNOS. S.A.I.C.'],
        ])->assertRedirect('/articulos');

        $this->assertSame($proveedor->id, Articulo::where('codigo', 'RE-4680')->firstOrFail()->proveedor_id);
    }

    public function test_una_razon_social_que_no_esta_en_el_padron_deja_advertencia_y_el_articulo_sin_proveedor(): void
    {
        Articulo::create(['codigo' => 'AG-20', 'descripcion' => 'AGUA BOTELLON DISPENSER']);
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'descrip_arti', 'proveedor_principal'], [
            ['AG-20', 'AGUA BOTELLON DISPENSER', 'IGLESIAS PIÑEIRO FRANCISCO JAVIER'],
        ]);

        $response->assertRedirect('/articulos');
        $this->assertStringContainsString(
            "No se encontró el proveedor 'IGLESIAS PIÑEIRO FRANCISCO JAVIER' en el padrón",
            $response->getSession()->get('error')
        );
        $this->assertNull(Articulo::where('codigo', 'AG-20')->firstOrFail()->proveedor_id);
    }

    /** Con miles de filas, una advertencia por fila sería ilegible. */
    public function test_el_mismo_proveedor_inexistente_avisa_una_sola_vez_con_el_contador(): void
    {
        Articulo::create(['codigo' => 'RE-1', 'descripcion' => 'UNO']);
        Articulo::create(['codigo' => 'RE-2', 'descripcion' => 'DOS']);
        Articulo::create(['codigo' => 'RE-3', 'descripcion' => 'TRES']);
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['RE-1', 'SEISEME SA'],
            ['RE-2', 'SEISEME SA'],
            ['RE-3', 'SEISEME SA'],
        ]);

        $error = $response->getSession()->get('error');
        $this->assertSame(1, substr_count($error, 'SEISEME SA'));
        $this->assertStringContainsString('3 artículos quedaron sin proveedor', $error);
    }

    /** Asignar el primero de dos homónimos sería asignar mal en silencio. */
    public function test_dos_proveedores_que_normalizan_igual_no_asignan_ninguno(): void
    {
        $this->proveedor('10', 'HILOS TUCUMAN SRL');
        $this->proveedor('11', 'HILOS TUCUMAN S.R.L.');
        Articulo::create(['codigo' => 'ABROJO', 'descripcion' => 'ABROJO 25 MM']);
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['ABROJO', 'HILOS TUCUMAN SRL'],
        ]);

        $this->assertStringContainsString('más de un proveedor', $response->getSession()->get('error'));
        $this->assertNull(Articulo::where('codigo', 'ABROJO')->firstOrFail()->proveedor_id);
    }

    /** Protege las correcciones hechas a mano de una reimportación del Excel roto. */
    public function test_una_celda_de_proveedor_vacia_no_borra_el_que_ya_estaba(): void
    {
        $proveedor = $this->proveedor('1500', 'PROPATO HNOS S A I C');
        Articulo::create([
            'codigo' => 'RE-4680',
            'descripcion' => 'DEA PAD ADULTO HEARTSTART',
            'proveedor_id' => $proveedor->id,
        ]);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['RE-4680', ''],
        ])->assertRedirect('/articulos');

        $this->assertSame($proveedor->id, Articulo::where('codigo', 'RE-4680')->firstOrFail()->proveedor_id);
    }

    public function test_una_razon_social_sin_match_tampoco_borra_el_proveedor_ya_asignado(): void
    {
        $proveedor = $this->proveedor('1500', 'PROPATO HNOS S A I C');
        Articulo::create([
            'codigo' => 'RE-4680',
            'descripcion' => 'DEA PAD ADULTO HEARTSTART',
            'proveedor_id' => $proveedor->id,
        ]);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['RE-4680', 'UNA EMPRESA QUE NO ESTÁ EN EL PADRÓN'],
        ])->assertRedirect('/articulos');

        $this->assertSame($proveedor->id, Articulo::where('codigo', 'RE-4680')->firstOrFail()->proveedor_id);
    }

    // --- Alta de artículos faltantes ------------------------------------

    public function test_con_la_tilde_crea_el_articulo_que_no_esta_en_el_catalogo(): void
    {
        $proveedor = $this->proveedor('1500', 'PROPATO HNOS S A I C');
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'descrip_arti', 'proveedor_principal'], [
            ['SUTURAS', '102586 SUTURA AC.POLIGLIC. 0 C/A', 'PROPATO HNOS. S.A.I.C.'],
        ], crearFaltantes: true);

        $response->assertRedirect('/articulos');
        $this->assertNull($response->getSession()->get('error'));

        $articulo = Articulo::where('codigo', 'SUTURAS')->firstOrFail();
        $this->assertSame('102586 SUTURA AC.POLIGLIC. 0 C/A', $articulo->descripcion);
        $this->assertSame($proveedor->id, $articulo->proveedor_id);
    }

    public function test_con_la_tilde_pero_sin_descripcion_no_crea_nada_y_avisa(): void
    {
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['SUTURAS', 'PROPATO HNOS. S.A.I.C.'],
        ], crearFaltantes: true);

        $this->assertStringContainsString('no trae descripción', $response->getSession()->get('error'));
        $this->assertSame(0, Articulo::count());
    }

    /** La descripción del ERP no se pisa: solo sirve para dar de alta. */
    public function test_la_columna_descripcion_no_pisa_la_de_un_articulo_existente(): void
    {
        Articulo::create(['codigo' => 'RE-4680', 'descripcion' => 'DESCRIPCIÓN DEL ERP']);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'descrip_arti'], [
            ['RE-4680', 'OTRA COSA QUE ESCRIBIERON EN EL EXCEL'],
        ], crearFaltantes: true)->assertRedirect('/articulos');

        $this->assertSame('DESCRIPCIÓN DEL ERP', Articulo::where('codigo', 'RE-4680')->firstOrFail()->descripcion);
    }

    public function test_el_mensaje_de_exito_cuenta_creados_y_actualizados(): void
    {
        Articulo::create(['codigo' => 'RE-4680', 'descripcion' => 'YA EXISTE']);
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'descrip_arti'], [
            ['RE-4680', 'YA EXISTE'],
            ['SUTURAS', 'NUEVO'],
        ], crearFaltantes: true);

        $this->assertSame(
            'Importación completa: 1 artículos actualizados, 1 creados.',
            $response->getSession()->get('success')
        );
    }

    // --- Alta de proveedores faltantes ----------------------------------

    public function test_con_la_tilde_crea_el_proveedor_que_no_esta_en_el_padron(): void
    {
        Articulo::create(['codigo' => 'RE-2179', 'descripcion' => 'AGUA BI DESMINERALIZADA X5LTS']);
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['RE-2179', 'HUGO TORBIDONI Y CIA S.R.L.'],
        ], crearFaltantes: true);

        $response->assertRedirect('/articulos');
        $this->assertNull($response->getSession()->get('error'));

        $proveedor = Proveedor::where('razon_social', 'HUGO TORBIDONI Y CIA S.R.L.')->firstOrFail();
        // Esta planilla no trae el NUM_PROV: lo completa el import del padrón.
        $this->assertNull($proveedor->numero);
        $this->assertSame($proveedor->id, Articulo::where('codigo', 'RE-2179')->firstOrFail()->proveedor_id);
    }

    /** Si no se reusara el que se acaba de crear, la misma empresa entraría una vez por artículo. */
    public function test_el_proveedor_creado_se_reusa_en_las_filas_siguientes(): void
    {
        Articulo::create(['codigo' => 'RE-4909', 'descripcion' => 'AGUJA 13/3 CORONET']);
        Articulo::create(['codigo' => 'RE-1119', 'descripcion' => 'AGUJA 13/3 TERUMO']);
        Articulo::create(['codigo' => 'RE-820', 'descripcion' => 'AGUJA 13/4 TERUMO']);
        $admin = $this->userWith('articulos.import');

        $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['RE-4909', 'SEISEME SA'],
            ['RE-1119', 'SEISEME S.A.'],
            ['RE-820', 'seiseme sa'],
        ], crearFaltantes: true)->assertRedirect('/articulos');

        $this->assertSame(1, Proveedor::count());
        $this->assertSame(1, Articulo::whereNotNull('proveedor_id')->distinct()->count('proveedor_id'));
    }

    public function test_sin_la_tilde_el_proveedor_que_falta_no_se_crea(): void
    {
        Articulo::create(['codigo' => 'RE-2179', 'descripcion' => 'AGUA BI DESMINERALIZADA X5LTS']);
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'proveedor_principal'], [
            ['RE-2179', 'HUGO TORBIDONI Y CIA S.R.L.'],
        ]);

        $this->assertSame(0, Proveedor::count());
        $this->assertStringContainsString('No se encontró el proveedor', $response->getSession()->get('error'));
    }

    public function test_el_mensaje_de_exito_cuenta_los_proveedores_nuevos(): void
    {
        $admin = $this->userWith('articulos.import');

        $response = $this->importar($admin, ['cod_articulo', 'descrip_arti', 'proveedor_principal'], [
            ['SUTURAS', 'SUTURA AC.POLIGLIC.', 'PROPATO HNOS. S.A.I.C.'],
        ], crearFaltantes: true);

        $this->assertSame(
            'Importación completa: 0 artículos actualizados, 1 creados, 1 proveedores nuevos.',
            $response->getSession()->get('success')
        );
    }
}
