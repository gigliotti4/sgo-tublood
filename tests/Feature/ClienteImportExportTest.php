<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Support\Documentacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClienteImportExportTest extends TestCase
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

        return new UploadedFile($path, 'clientes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Baja el export y lo devuelve como matriz de filas de la hoja activa. */
    private function exportar(User $user, string $query = ''): array
    {
        return $this->libroExportado($user, $query)->getActiveSheet()->toArray();
    }

    private function libroExportado(User $user, string $query = ''): Spreadsheet
    {
        $response = $this->actingAs($user)->get("/clientes/export{$query}");
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /** Todo el texto del instructivo en un solo string, para buscar adentro. */
    private function instructivo(User $user): string
    {
        $filas = $this->libroExportado($user)->getSheetByName('Instructivo')->toArray();

        return implode("\n", array_map(fn ($fila) => implode(' | ', $fila), $filas));
    }

    private function importar(User $user, array $filas)
    {
        return $this->actingAs($user)->post('/clientes/import', [
            'archivo' => $this->excelDeFilas($filas),
        ]);
    }

    public function test_export_requiere_permiso_clientes_view(): void
    {
        $this->actingAs(User::factory()->create())->get('/clientes/export')->assertStatus(403);
    }

    public function test_import_requiere_permiso_clientes_import(): void
    {
        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->post('/clientes/import')
            ->assertStatus(403);
    }

    public function test_el_export_trae_los_datos_y_una_columna_por_documento(): void
    {
        $cliente = Cliente::create([
            'numero' => '1066', 'razon_social' => 'Importadora Test SA', 'cuit' => '30-1234-0',
            'tipo_cliente' => 'importador', 'tiene_legajo' => true, 'habilitado' => false,
            'notas' => 'Pidió prórroga.',
        ]);
        $cliente->documentos()->createMany([
            ['documento' => 'constancia_arca', 'presentado' => true],
            ['documento' => 'bpf', 'presentado' => true, 'fecha_vencimiento' => '2027-03-10'],
        ]);
        $cliente->recalcularEstadoDocumental();

        $filas = $this->exportar($this->userWith('clientes.view'));
        $encabezado = $filas[0];
        $fila = array_combine($encabezado, $filas[1]);

        $this->assertSame('1066', $fila['N°']);
        $this->assertSame('Importadora Test SA', $fila['Razón Social']);
        $this->assertSame('Importador', $fila['Tipo de cliente']);
        $this->assertSame('SÍ', $fila['Tiene legajo']);
        $this->assertSame('NO', $fila['Habilitado']);
        $this->assertSame('Pidió prórroga.', $fila['Observaciones']);

        // Una columna por documento del catálogo, más su vencimiento.
        $this->assertSame('SÍ', $fila['Constancia ARCA']);
        $this->assertSame('SÍ', $fila['BPF']);
        $this->assertSame('10/03/2027', $fila['BPF - Vto']);

        // Un documento que el tipo no pide queda en blanco, no en "NO".
        $this->assertSame('', (string) $fila['DDJJ']);

        // Constancia ARCA no vence en ningún tipo: la columna existe (el
        // encabezado es fijo) pero la celda va vacía.
        $this->assertSame('', (string) $fila['Constancia ARCA - Vto']);

        // Y las calculadas, para leer.
        $this->assertSame('NO', $fila['Documentación completa']);
        $this->assertSame('Habilitación ANMAT, Certificado de Funcionamiento', $fila['Documentación faltante']);
        $this->assertSame('10/03/2027', $fila['Vencimiento del cliente']);
    }

    /** Lo que ves en el listado es lo que baja. */
    public function test_el_export_respeta_los_filtros_del_listado(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Alfa SA', 'tipo_cliente' => 'farmacia']);
        Cliente::create(['numero' => '2', 'razon_social' => 'Beta SA', 'tipo_cliente' => 'importador']);

        $filas = $this->exportar($this->userWith('clientes.view'), '?tipo_cliente=farmacia');

        $this->assertCount(2, $filas); // encabezado + 1
        $this->assertSame('Alfa SA', $filas[1][1]);
    }

    /**
     * El circuito completo: bajar el Excel, escribirle encima y volver a
     * subirlo. Es el caso de uso real de la carga masiva.
     */
    public function test_el_archivo_exportado_se_puede_volver_a_importar(): void
    {
        Cliente::create(['numero' => '1066', 'razon_social' => 'Importadora Test SA']);

        $filas = $this->exportar($this->userWith('clientes.view'));
        $encabezado = $filas[0];
        $columna = fn (string $nombre) => array_search($nombre, $encabezado, true);

        // Se completa la planilla como lo haría una persona en Excel.
        $fila = $filas[1];
        $fila[$columna('Tipo de cliente')] = 'Importador';
        $fila[$columna('Tiene legajo')] = 'SÍ';
        $fila[$columna('Habilitado')] = 'NO';
        $fila[$columna('Observaciones')] = 'Cargado por Excel.';
        $fila[$columna('Constancia ARCA')] = 'SÍ';
        $fila[$columna('Habilitación ANMAT')] = 'SÍ';
        $fila[$columna('Habilitación ANMAT - Vto')] = '01/05/2027';
        $fila[$columna('Certificado de Funcionamiento')] = 'SÍ';
        $fila[$columna('Certificado de Funcionamiento - Vto')] = '01/01/2027';
        $fila[$columna('BPF')] = 'SÍ';
        $fila[$columna('BPF - Vto')] = '10/03/2027';

        $this->importar($this->userWith('clientes.import'), [$encabezado, $fila])
            ->assertRedirect('/clientes')
            ->assertSessionMissing('error');

        $cliente = Cliente::where('numero', '1066')->first();

        $this->assertSame('importador', $cliente->tipo_cliente);
        $this->assertTrue($cliente->tiene_legajo);
        $this->assertFalse($cliente->habilitado);
        $this->assertSame('Cargado por Excel.', $cliente->notas);
        $this->assertTrue($cliente->documentacion_completa);
        // El vencimiento sale del BPF, igual que cargándolo desde la ficha.
        $this->assertSame('2027-03-10', $cliente->fecha_vencimiento->toDateString());
        $this->assertCount(4, $cliente->documentos);
    }

    /** dd/mm/aaaa: 03/04 es el 3 de abril, no el 4 de marzo. */
    public function test_las_fechas_se_leen_en_formato_argentino(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Importadora Test', 'tipo_cliente' => 'importador']);

        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'BPF', 'BPF - Vto'],
            ['1', 'SÍ', '03/04/2027'],
        ]);

        $this->assertSame(
            '2027-04-03',
            Cliente::where('numero', '1')->first()->fecha_vencimiento->toDateString()
        );
    }

    /** Solo las columnas que trae el archivo; el resto queda como estaba. */
    public function test_una_planilla_parcial_no_pisa_lo_que_no_trae(): void
    {
        $cliente = Cliente::create([
            'numero' => '1', 'razon_social' => 'Importadora Test', 'tipo_cliente' => 'importador',
            'notas' => 'Nota vieja.', 'tiene_legajo' => true,
        ]);
        $cliente->documentos()->create([
            'documento' => 'bpf', 'presentado' => true, 'fecha_vencimiento' => '2027-03-10',
        ]);

        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'Constancia ARCA'],
            ['1', 'SÍ'],
        ]);

        $cliente->refresh();

        $this->assertSame('Nota vieja.', $cliente->notas);
        $this->assertTrue($cliente->tiene_legajo);
        $this->assertSame('importador', $cliente->tipo_cliente);
        $this->assertSame('2027-03-10', $cliente->documentos->firstWhere('documento', 'bpf')->fecha_vencimiento->toDateString());
        $this->assertTrue($cliente->documentos->firstWhere('documento', 'constancia_arca')->presentado);
    }

    /** Una celda vacía no borra: dar de baja un documento exige un "NO". */
    public function test_una_celda_vacia_no_borra_un_documento_ya_cargado(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Test', 'tipo_cliente' => 'importador']);
        $cliente->documentos()->create(['documento' => 'bpf', 'presentado' => true, 'fecha_vencimiento' => '2027-03-10']);

        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'BPF', 'BPF - Vto'],
            ['1', '', ''],
        ]);

        $doc = $cliente->fresh()->documentos->firstWhere('documento', 'bpf');
        $this->assertTrue($doc->presentado);
        $this->assertSame('2027-03-10', $doc->fecha_vencimiento->toDateString());

        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'BPF'],
            ['1', 'NO'],
        ]);

        $cliente->refresh();
        $this->assertFalse($cliente->documentos->firstWhere('documento', 'bpf')->presentado);
        // Al destildarlo se cae también el vencimiento del cliente.
        $this->assertNull($cliente->fecha_vencimiento);
    }

    /** Los clientes vienen del ERP: el import no puede inventar uno. */
    public function test_un_numero_que_no_existe_no_crea_el_cliente_y_avisa(): void
    {
        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'Tipo de cliente'],
            ['9999', 'Importador'],
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_un_tipo_de_cliente_desconocido_no_pisa_el_que_tenia(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Test', 'tipo_cliente' => 'farmacia']);

        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'Tipo de cliente'],
            ['1', 'Kiosco'],
        ])->assertSessionHas('error');

        $this->assertSame('farmacia', Cliente::where('numero', '1')->first()->tipo_cliente);
    }

    /** El tipo se aplica antes que los documentos: reclasificar y cargar en la misma fila funciona. */
    public function test_reclasificar_y_cargar_documentos_en_la_misma_fila(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Test', 'tipo_cliente' => 'farmacia']);
        $cliente->documentos()->create(['documento' => 'habilitacion_ministerio', 'presentado' => true]);

        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'Tipo de cliente', 'Constancia ARCA', 'Habilitación ANMAT', 'Habilitación ANMAT - Vto', 'Certificado de Funcionamiento', 'Certificado de Funcionamiento - Vto', 'BPF', 'BPF - Vto'],
            ['1', 'Importador', 'SÍ', 'SÍ', '01/05/2027', 'SÍ', '01/01/2027', 'SÍ', '10/03/2027'],
        ]);

        $cliente->refresh();

        $this->assertSame('importador', $cliente->tipo_cliente);
        $this->assertTrue($cliente->documentacion_completa);
        // La habilitación del Ministerio no la pide Importador: se borró.
        $this->assertNull($cliente->documentos->firstWhere('documento', 'habilitacion_ministerio'));
    }

    /** Un documento que el tipo del cliente no pide se avisa y se ignora. */
    public function test_un_documento_que_no_corresponde_al_tipo_se_ignora(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Test', 'tipo_cliente' => 'farmacia']);

        $this->importar($this->userWith('clientes.import'), [
            ['N°', 'BPF', 'BPF - Vto'],
            ['1', 'SÍ', '10/03/2027'],
        ])->assertSessionHas('error');

        $this->assertCount(0, Cliente::where('numero', '1')->first()->documentos);
    }

    public function test_un_archivo_sin_columna_de_numero_avisa_y_no_toca_nada(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Test']);

        $this->importar($this->userWith('clientes.import'), [
            ['Razón Social', 'Tipo de cliente'],
            ['Test', 'Importador'],
        ])->assertSessionHas('error');

        $this->assertNull(Cliente::where('numero', '1')->first()->tipo_cliente);
    }

    /**
     * El instructivo va en una segunda hoja: la de datos tiene que seguir siendo
     * la activa, que es la que lee el import. Si esto se rompe, reimportar el
     * archivo exportado leería el instructivo en vez de los clientes.
     */
    public function test_el_libro_trae_la_hoja_de_datos_activa_y_el_instructivo_aparte(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Test SA']);

        $libro = $this->libroExportado($this->userWith('clientes.view'));

        $this->assertSame(['Clientes', 'Instructivo'], $libro->getSheetNames());
        $this->assertSame('Clientes', $libro->getActiveSheet()->getTitle());
    }

    public function test_el_instructivo_explica_como_completar_cada_columna(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Test SA']);

        $texto = $this->instructivo($this->userWith('clientes.view'));

        $this->assertStringContainsString('INSTRUCTIVO', $texto);
        $this->assertStringContainsString('Qué se carga en cada columna', $texto);
        // Las reglas que no son obvias mirando la planilla.
        $this->assertStringContainsString('Una celda vacía significa "no cambiar"', $texto);
        $this->assertStringContainsString('NO se crea', $texto);
        $this->assertStringContainsString('dd/mm/aaaa', $texto);
    }

    /**
     * La tabla de tipos se genera desde el catálogo, así que un tipo o un
     * documento nuevo tiene que aparecer solo, sin tocar ningún texto.
     */
    public function test_el_instructivo_lista_los_tipos_con_su_documentacion(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Test SA']);

        $texto = $this->instructivo($this->userWith('clientes.view'));

        foreach (Documentacion::etiquetasTipos() as $label) {
            $this->assertStringContainsString($label, $texto);
        }

        // Y por cada documento, si es obligatorio, si vence y si manda el
        // vencimiento del cliente: la fila del BPF de Importador las tiene todas.
        $this->assertStringContainsString('BPF | Obligatorio | Sí, cargar fecha | SÍ', $texto);
        $this->assertStringContainsString('Designación Director Técnico | Opcional', $texto);
    }

    /**
     * Sube el archivo exportado **tal cual**, con sus dos hojas, en vez de
     * rearmar uno con sus filas: es lo que hace una persona de verdad, y es lo
     * único que prueba que el import sigue leyendo la hoja de datos y no el
     * instructivo.
     */
    public function test_se_puede_subir_el_archivo_exportado_sin_tocarlo(): void
    {
        $cliente = Cliente::create([
            'numero' => '1066', 'razon_social' => 'Importadora Test SA', 'tipo_cliente' => 'importador',
        ]);
        $cliente->documentos()->create([
            'documento' => 'bpf', 'presentado' => true, 'fecha_vencimiento' => '2027-03-10',
        ]);
        $cliente->recalcularEstadoDocumental();

        $response = $this->actingAs($this->userWith('clientes.view'))->get('/clientes/export');
        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $archivo = new UploadedFile($path, 'clientes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->userWith('clientes.import'))
            ->post('/clientes/import', ['archivo' => $archivo])
            ->assertRedirect('/clientes')
            ->assertSessionMissing('error');

        // Reimportar sin cambios es idempotente: queda todo igual.
        $cliente->refresh();
        $this->assertSame('importador', $cliente->tipo_cliente);
        $this->assertSame('2027-03-10', $cliente->fecha_vencimiento->toDateString());
        $this->assertTrue($cliente->documentos->firstWhere('documento', 'bpf')->presentado);
    }

    /**
     * Las listas desplegables son la mitad del instructivo: sin ellas alguien
     * tipea "Si señor" y el import lo ignora en silencio.
     */
    public function test_las_columnas_que_se_completan_a_mano_tienen_lista_desplegable(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Test SA']);

        $hoja = $this->libroExportado($this->userWith('clientes.view'))->getSheetByName('Clientes');

        // G = Tipo de cliente, H = Tiene legajo, I = Habilitado, K = primer documento.
        $this->assertStringContainsString('Importador', $hoja->getCell('G2')->getDataValidation()->getFormula1());
        $this->assertStringContainsString('SÍ', $hoja->getCell('H2')->getDataValidation()->getFormula1());
        $this->assertStringContainsString('SÍ', $hoja->getCell('I2')->getDataValidation()->getFormula1());
        $this->assertStringContainsString('SÍ', $hoja->getCell('K2')->getDataValidation()->getFormula1());

        // La celda vacía tiene que seguir siendo válida: significa "no cambiar".
        $this->assertTrue($hoja->getCell('G2')->getDataValidation()->getAllowBlank());
    }
}
