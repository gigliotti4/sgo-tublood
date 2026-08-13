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

class ProveedorImportTest extends TestCase
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

        return new UploadedFile($path, 'proveedores.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importar(User $admin, array $encabezado, array $filas)
    {
        return $this->actingAs($admin)->post('/proveedores/import', [
            'archivo' => $this->excelDeFilas([$encabezado, ...$filas]),
        ]);
    }

    public function test_import_requiere_permiso_proveedores_import(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/proveedores/import', ['archivo' => $this->excelDeFilas([['NUM_PROV', 'RAZON'], ['933', 'ALGO']])])
            ->assertStatus(403);
    }

    /** Las tres filas de la planilla real. */
    public function test_carga_el_padron_desde_cero(): void
    {
        $admin = $this->userWith('proveedores.import');

        $this->importar($admin, ['NUM_PROV', 'RAZON', 'DOMICILIO'], [
            ['933', 'ANTONIO LUQUIN S A C I F E I', 'GRAL GUEMES 897'],
            ['1246', 'CRONOINK SRL', 'LISANDRO DE LA TORRE 4101'],
            ['1680', 'TRANSPORTES AUTOMOTORES QUEBEK SOCIEDAD ANONIMA T A Q S A', 'YUGOSLAVIA 1407 - ESQUINA : CHAP'],
        ])->assertRedirect('/proveedores');

        $this->assertSame(3, Proveedor::count());

        $cronoink = Proveedor::where('numero', '1246')->firstOrFail();
        $this->assertSame('CRONOINK SRL', $cronoink->razon_social);
        $this->assertSame('LISANDRO DE LA TORRE 4101', $cronoink->domicilio);
    }

    public function test_una_segunda_corrida_actualiza_sin_duplicar(): void
    {
        $admin = $this->userWith('proveedores.import');

        $this->importar($admin, ['NUM_PROV', 'RAZON', 'DOMICILIO'], [
            ['933', 'ANTONIO LUQUIN S A C I F E I', 'GRAL GUEMES 897'],
        ]);

        $this->importar($admin, ['NUM_PROV', 'RAZON', 'DOMICILIO'], [
            ['933', 'ANTONIO LUQUIN S.A.', 'AV. SIEMPREVIVA 742'],
        ])->assertRedirect('/proveedores');

        $this->assertSame(1, Proveedor::count());

        $proveedor = Proveedor::where('numero', '933')->firstOrFail();
        $this->assertSame('ANTONIO LUQUIN S.A.', $proveedor->razon_social);
        $this->assertSame('AV. SIEMPREVIVA 742', $proveedor->domicilio);
    }

    /** La regresión importante: reimportar no puede borrar lo que se cargó a mano en el panel. */
    public function test_reimportar_no_pisa_los_campos_propios_del_panel(): void
    {
        $admin = $this->userWith('proveedores.import');

        $proveedor = Proveedor::create([
            'numero' => '933',
            'razon_social' => 'ANTONIO LUQUIN S A C I F E I',
            'domicilio' => 'GRAL GUEMES 897',
            'cuit' => '30-12345678-9',
            'telefono' => '11 4444-5555',
            'mail' => 'compras@luquin.com.ar',
            'localidad' => 'CABA',
            'observaciones' => 'Entrega los martes.',
        ]);

        $this->importar($admin, ['NUM_PROV', 'RAZON', 'DOMICILIO'], [
            ['933', 'ANTONIO LUQUIN S A C I F E I', 'GRAL GUEMES 897'],
        ])->assertRedirect('/proveedores');

        $proveedor->refresh();
        $this->assertSame('30-12345678-9', $proveedor->cuit);
        $this->assertSame('11 4444-5555', $proveedor->telefono);
        $this->assertSame('compras@luquin.com.ar', $proveedor->mail);
        $this->assertSame('CABA', $proveedor->localidad);
        $this->assertSame('Entrega los martes.', $proveedor->observaciones);
    }

    public function test_resuelve_las_columnas_por_encabezado_y_no_por_posicion(): void
    {
        $admin = $this->userWith('proveedores.import');

        // Orden invertido y una columna de más entre medio.
        $this->importar($admin, ['DOMICILIO', 'PROVINCIA', 'RAZON', 'NUM_PROV'], [
            ['GRAL GUEMES 897', 'BUENOS AIRES', 'ANTONIO LUQUIN S A C I F E I', '933'],
        ])->assertRedirect('/proveedores');

        $proveedor = Proveedor::where('numero', '933')->firstOrFail();
        $this->assertSame('ANTONIO LUQUIN S A C I F E I', $proveedor->razon_social);
        $this->assertSame('GRAL GUEMES 897', $proveedor->domicilio);
    }

    /** NUM_PROV viene como celda numérica: 933 y 933.0 tienen que ser el mismo proveedor. */
    public function test_el_numero_llega_como_celda_numerica_y_no_duplica(): void
    {
        $admin = $this->userWith('proveedores.import');

        $this->importar($admin, ['NUM_PROV', 'RAZON'], [
            [933, 'ANTONIO LUQUIN S A C I F E I'],
        ]);

        $this->importar($admin, ['NUM_PROV', 'RAZON'], [
            ['933.0', 'ANTONIO LUQUIN S A C I F E I'],
        ])->assertRedirect('/proveedores');

        $this->assertSame(1, Proveedor::count());
        $this->assertSame('933', Proveedor::first()->numero);
    }

    public function test_una_fila_sin_razon_social_deja_advertencia_y_no_crea_nada(): void
    {
        $admin = $this->userWith('proveedores.import');

        $response = $this->importar($admin, ['NUM_PROV', 'RAZON', 'DOMICILIO'], [
            ['4321', '', 'CALLE FALSA 123'],
        ]);

        $response->assertRedirect('/proveedores');
        $this->assertStringContainsString(
            "el proveedor '4321' no tiene razón social",
            $response->getSession()->get('error')
        );
        $this->assertSame(0, Proveedor::count());
    }

    public function test_una_fila_sin_numero_se_saltea_en_silencio(): void
    {
        $admin = $this->userWith('proveedores.import');

        $response = $this->importar($admin, ['NUM_PROV', 'RAZON'], [
            ['', 'FILA VACÍA AL FINAL DE LA PLANILLA'],
            ['933', 'ANTONIO LUQUIN S A C I F E I'],
        ]);

        $response->assertRedirect('/proveedores');
        $this->assertNull($response->getSession()->get('error'));
        $this->assertSame(1, Proveedor::count());
    }

    public function test_sin_columna_de_numero_el_archivo_se_rechaza_con_error_claro(): void
    {
        $admin = $this->userWith('proveedores.import');

        $response = $this->importar($admin, ['RAZON', 'DOMICILIO'], [
            ['ANTONIO LUQUIN S A C I F E I', 'GRAL GUEMES 897'],
        ]);

        $response->assertRedirect('/proveedores');
        $this->assertStringContainsString('columna de número de proveedor', $response->getSession()->get('error'));
        $this->assertSame(0, Proveedor::count());
    }

    // --- Adopción de los que creó el import de artículos -----------------

    /**
     * El import de artículos da de alta proveedores sin número (esa planilla no
     * trae el NUM_PROV). Cuando después entra el padrón real, tiene que
     * completarles el número y no crear un segundo registro de la misma empresa.
     */
    public function test_el_padron_adopta_al_proveedor_sin_numero_en_vez_de_duplicarlo(): void
    {
        $admin = $this->userWith('proveedores.import');
        $huerfano = Proveedor::create(['razon_social' => 'PROPATO HNOS. S.A.I.C.']);

        $this->importar($admin, ['NUM_PROV', 'RAZON', 'DOMICILIO'], [
            ['1500', 'PROPATO HNOS S A I C', 'AV. CORRIENTES 1234'],
        ])->assertRedirect('/proveedores');

        $this->assertSame(1, Proveedor::count());

        $huerfano->refresh();
        $this->assertSame('1500', $huerfano->numero);
        $this->assertSame('PROPATO HNOS S A I C', $huerfano->razon_social);
        $this->assertSame('AV. CORRIENTES 1234', $huerfano->domicilio);
    }

    /** El artículo que apuntaba al huérfano sigue apuntando al mismo proveedor. */
    public function test_la_adopcion_no_rompe_el_vinculo_con_los_articulos(): void
    {
        $admin = $this->userWith('proveedores.import');
        $huerfano = Proveedor::create(['razon_social' => 'PROPATO HNOS. S.A.I.C.']);
        $articulo = Articulo::create([
            'codigo' => 'RE-4680',
            'descripcion' => 'DEA PAD ADULTO',
            'proveedor_id' => $huerfano->id,
        ]);

        $this->importar($admin, ['NUM_PROV', 'RAZON'], [
            ['1500', 'PROPATO HNOS S A I C'],
        ])->assertRedirect('/proveedores');

        $this->assertSame($huerfano->id, $articulo->fresh()->proveedor_id);
        $this->assertSame('1500', $articulo->fresh()->proveedor->numero);
    }

    /** Adoptar uno de dos homónimos le pondría el número al que no era. */
    public function test_dos_huerfanos_que_normalizan_igual_no_se_adoptan(): void
    {
        $admin = $this->userWith('proveedores.import');
        Proveedor::create(['razon_social' => 'HILOS TUCUMAN SRL']);
        Proveedor::create(['razon_social' => 'HILOS TUCUMAN S.R.L.']);

        $this->importar($admin, ['NUM_PROV', 'RAZON'], [
            ['77', 'HILOS TUCUMAN SRL'],
        ])->assertRedirect('/proveedores');

        $this->assertSame(3, Proveedor::count());
        $this->assertSame(2, Proveedor::whereNull('numero')->count());
    }

    /** Un huérfano solo puede ser adoptado una vez, aunque el padrón repita la razón social. */
    public function test_un_huerfano_no_lo_adoptan_dos_filas_distintas(): void
    {
        $admin = $this->userWith('proveedores.import');
        Proveedor::create(['razon_social' => 'SEISEME SA']);

        $this->importar($admin, ['NUM_PROV', 'RAZON'], [
            ['200', 'SEISEME S.A.'],
            ['201', 'SEISEME S.A.'],
        ])->assertRedirect('/proveedores');

        $this->assertSame(2, Proveedor::count());
        $this->assertSame('200', Proveedor::where('razon_social', 'SEISEME S.A.')->orderBy('id')->first()->numero);
        $this->assertSame(0, Proveedor::whereNull('numero')->count());
    }

    public function test_sin_columna_de_razon_social_el_archivo_se_rechaza_con_error_claro(): void
    {
        $admin = $this->userWith('proveedores.import');

        $response = $this->importar($admin, ['NUM_PROV', 'DOMICILIO'], [
            ['933', 'GRAL GUEMES 897'],
        ]);

        $response->assertRedirect('/proveedores');
        $this->assertStringContainsString('columna de razón social', $response->getSession()->get('error'));
        $this->assertSame(0, Proveedor::count());
    }
}
