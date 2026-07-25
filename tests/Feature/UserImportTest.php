<?php

namespace Tests\Feature;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserImportTest extends TestCase
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

        return new UploadedFile($path, 'usuarios.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function encabezado(): array
    {
        return ['NOMBRE', 'APELLIDO', 'MAIL', 'SECTOR ORIGINAL', 'SUPERVISOR', 'GERENTE AVISO FINAL', 'TIEMPO DE GESTIÓN PARA ALERTAS'];
    }

    private function importar(User $admin, array $filas, string $rol = 'usuario_interno')
    {
        return $this->actingAs($admin)->post('/users/import', [
            'archivo' => $this->excelDeFilas([$this->encabezado(), ...$filas]),
            'rol' => $rol,
        ]);
    }

    public function test_import_requiere_permiso_users_create(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/users/import', ['archivo' => $this->excelDeFilas([$this->encabezado()]), 'rol' => 'usuario_interno'])
            ->assertStatus(403);
    }

    public function test_import_crea_usuarios_con_rol_y_devuelve_sus_contrasenas(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $response = $this->importar($admin, [
            ['EMANUEL', 'DURAN', 'eduran@tublood.com'],
            ['FACUNDO', 'DURAN', 'fduran@tublood.com'],
        ])->assertRedirect(route('users.index'));

        $emanuel = User::where('email', 'eduran@tublood.com')->firstOrFail();
        $this->assertSame('EMANUEL', $emanuel->name);
        $this->assertSame('DURAN', $emanuel->apellido);
        $this->assertTrue($emanuel->hasRole('usuario_interno'));

        $importados = $response->getSession()->get('importados');
        $this->assertCount(2, $importados);

        // La contraseña que se muestra tiene que ser la que realmente quedó guardada.
        $credencial = collect($importados)->firstWhere('email', 'eduran@tublood.com');
        $this->assertSame('EMANUEL DURAN', $credencial['nombre']);
        $this->assertTrue(Hash::check($credencial['password'], $emanuel->password));
    }

    public function test_import_actualiza_usuario_existente_sin_tocar_password_ni_rol(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $existente = User::factory()->create([
            'name' => 'Nombre Viejo',
            'email' => 'existente@tublood.com',
            'password' => Hash::make('secreto-original'),
        ]);
        $existente->assignRole('viewer');

        $response = $this->importar($admin, [['NOMBRE NUEVO', 'APELLIDO', 'existente@tublood.com']])
            ->assertRedirect(route('users.index'));

        $existente->refresh();
        $this->assertSame('NOMBRE NUEVO', $existente->name);
        $this->assertSame('APELLIDO', $existente->apellido);
        $this->assertTrue(Hash::check('secreto-original', $existente->password));
        $this->assertTrue($existente->hasRole('viewer'));
        $this->assertFalse($existente->hasRole('usuario_interno'));

        // No es alta, así que no aparece en la lista de contraseñas.
        $this->assertEmpty($response->getSession()->get('importados'));
    }

    public function test_import_normaliza_el_mail_a_minusculas(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $this->importar($admin, [['MARTIN', 'MILEO', 'MMileo@Tublood.com']]);

        $this->assertTrue(User::where('email', 'mmileo@tublood.com')->exists());
    }

    public function test_import_omite_filas_invalidas_y_reporta_advertencias(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $response = $this->importar($admin, [
            ['Sin Mail', 'Apellido', ''],
            ['Mail Roto', 'Apellido', 'no-es-un-mail'],
            ['', '', ''],
            ['Valido', 'Apellido', 'valido@tublood.com'],
        ])->assertRedirect(route('users.index'));

        $this->assertFalse(User::where('name', 'Sin Mail')->exists());
        $this->assertFalse(User::where('name', 'Mail Roto')->exists());
        $this->assertTrue(User::where('email', 'valido@tublood.com')->exists());
        $response->assertSessionHas('error');
    }

    public function test_import_omite_mails_repetidos_dentro_del_archivo(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $response = $this->importar($admin, [
            ['Primero', 'Apellido', 'repetido@tublood.com'],
            ['Segundo', 'Apellido', 'repetido@tublood.com'],
        ])->assertRedirect(route('users.index'));

        $this->assertSame(1, User::where('email', 'repetido@tublood.com')->count());
        $this->assertSame('Primero', User::where('email', 'repetido@tublood.com')->first()->name);
        $response->assertSessionHas('error');
    }

    public function test_import_asigna_el_sector_con_su_plazo_de_gestion(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');
        $sector = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);

        $this->importar($admin, [
            ['LONEL', 'BRINGAS', 'logistica@tublood.com', 'LOGISTICA', '-', 'EMANUEL', '5 días'],
        ]);

        $this->assertSame(5, $sector->fresh()->dias_gestion);
        $this->assertSame($sector->id, User::where('email', 'logistica@tublood.com')->first()->sector_id);
    }

    public function test_import_normaliza_el_nombre_del_sector_con_saltos_de_linea(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');
        // El catálogo usa el nombre consolidado; el Excel trae el mismo sector
        // partido en dos líneas — el import tiene que igual reconocerlo.
        $sector = Sector::create(['nombre' => 'Asuntos Regulatorios', 'slug' => 'asuntos_regulatorios']);

        $this->importar($admin, [
            ['IARA', 'NIEVA', 'asuntosregulatorios@tublood.com', "ASUNTOS REGULATORIOS/\nGESTIÓN DE CALIDAD", '-', 'EMANUEL', '5 días'],
        ]);

        $this->assertSame(
            $sector->id,
            User::where('email', 'asuntosregulatorios@tublood.com')->first()->sector_id
        );
    }

    public function test_import_resuelve_el_sector_via_alias(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');
        // El Excel trae "CALIDAD"; el catálogo lo tiene como "garantia_calidad".
        $sector = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->importar($admin, [
            ['NADIA', 'PAVIA', 'calidad@tublood.com', 'CALIDAD', '-', '-', '3 días'],
        ]);

        $this->assertSame($sector->id, User::where('email', 'calidad@tublood.com')->first()->sector_id);
    }

    public function test_import_deja_sin_sector_a_un_nombre_fuera_del_catalogo(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        // El catálogo de sectores es fijo: el import no crea sectores nuevos.
        $response = $this->importar($admin, [
            ['OSMARLY', 'AZOCAR', 'tesoreria@tublood.com', 'FINANZAS', '-', '-', '2 días'],
        ])->assertRedirect(route('users.index'));

        $this->assertNull(User::where('email', 'tesoreria@tublood.com')->first()->sector_id);
        $this->assertFalse(Sector::where('slug', 'finanzas')->exists());
        $this->assertStringContainsString('no está en el catálogo', $response->getSession()->get('error'));
    }

    public function test_import_marca_como_gerente_a_quien_trae_si_en_la_columna_supervisor(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $this->importar($admin, [
            ['EMANUEL', 'DURAN', 'eduran@tublood.com', '', 'si', '-', '-'],
        ]);

        $emanuel = User::where('email', 'eduran@tublood.com')->firstOrFail();
        $this->assertTrue($emanuel->es_gerente);
        $this->assertNull($emanuel->supervisor_id);
        $this->assertNull($emanuel->sector_id);
    }

    public function test_import_resuelve_supervisor_por_nombre_completo_y_gerente_por_nombre_de_pila(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $this->importar($admin, [
            ['MARTIN', 'MILEO', 'mmileo@tublood.com', '', 'si', '-', '-'],
            ['CANDELARIA', 'VACULA', 'oficinacomercial@tublood.com', 'VENTAS', '-', 'MARTIN', '2 días'],
            ['MATIAS', 'BORDA', 'ventas4@tublood.com', 'VENTAS', 'CANDELARIA VACULA', 'MARTIN', '2 días'],
        ]);

        $martin = User::where('email', 'mmileo@tublood.com')->firstOrFail();
        $candelaria = User::where('email', 'oficinacomercial@tublood.com')->firstOrFail();
        $matias = User::where('email', 'ventas4@tublood.com')->firstOrFail();

        $this->assertSame($candelaria->id, $matias->supervisor_id);
        $this->assertSame($martin->id, $matias->gerente_id);
        $this->assertSame($martin->id, $candelaria->gerente_id);

        // La cadena completa de escalamiento del equipo de Ventas.
        $this->assertSame([$candelaria->id], $matias->cadenaEscalamiento()->pluck('id')->all());
    }

    public function test_import_resuelve_referencias_a_filas_posteriores(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        // Osmarly nombra a Barbara, que recién aparece en la fila siguiente.
        $this->importar($admin, [
            ['OSMARLY', 'AZOCAR', 'tesoreria@tublood.com', 'FINANZAS', 'BARBARA NEGRIN', '-', '2 días'],
            ['BARBARA', 'NEGRIN', 'bnegrin@tublood.com', 'FINANZAS', '-', '-', '2 días'],
        ]);

        $this->assertSame(
            User::where('email', 'bnegrin@tublood.com')->first()->id,
            User::where('email', 'tesoreria@tublood.com')->first()->supervisor_id
        );
    }

    public function test_import_avisa_cuando_el_supervisor_no_existe(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $response = $this->importar($admin, [
            ['MARIA', 'AVERO', 'cotizaciones@tublood.com', 'VENTAS', 'ALGUIEN QUE NO EXISTE', '-', '2 días'],
        ])->assertRedirect(route('users.index'));

        $this->assertNull(User::where('email', 'cotizaciones@tublood.com')->first()->supervisor_id);
        $this->assertStringContainsString('no existe ningún usuario', $response->getSession()->get('error'));
    }

    public function test_import_avisa_cuando_el_nombre_del_supervisor_es_ambiguo(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $response = $this->importar($admin, [
            ['CAMILA', 'KONZ', 'cobranzas@tublood.com', 'FINANZAS', '-', '-', '2 días'],
            ['CAMILA', 'VIÑUELA', 'info@tublood.com', 'VENTAS', '-', '-', '2 días'],
            ['BELEN', 'CAMAÑO', 'marketing@tublood.com', 'VENTAS', 'CAMILA', '-', '2 días'],
        ])->assertRedirect(route('users.index'));

        $this->assertNull(User::where('email', 'marketing@tublood.com')->first()->supervisor_id);
        $this->assertStringContainsString('hay más de un usuario', $response->getSession()->get('error'));
    }

    public function test_import_rechaza_un_circulo_de_escalamiento(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $response = $this->importar($admin, [
            ['UNO', 'PRIMERO', 'uno@tublood.com', 'VENTAS', 'DOS SEGUNDO', '-', '2 días'],
            ['DOS', 'SEGUNDO', 'dos@tublood.com', 'VENTAS', 'UNO PRIMERO', '-', '2 días'],
        ])->assertRedirect(route('users.index'));

        $uno = User::where('email', 'uno@tublood.com')->firstOrFail();
        $dos = User::where('email', 'dos@tublood.com')->firstOrFail();

        $this->assertSame($dos->id, $uno->supervisor_id);
        $this->assertNull($dos->supervisor_id);
        $this->assertStringContainsString('círculo de escalamiento', $response->getSession()->get('error'));
    }

    public function test_import_avisa_si_dos_filas_dan_plazos_distintos_al_mismo_sector(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');
        // "VENTAS" en el Excel alía a "comercial" en el catálogo.
        $sector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial']);

        $response = $this->importar($admin, [
            ['UNO', 'VENTAS', 'ventas1@tublood.com', 'VENTAS', '-', '-', '2 días'],
            ['DOS', 'VENTAS', 'ventas2@tublood.com', 'VENTAS', '-', '-', '5 días'],
        ])->assertRedirect(route('users.index'));

        $this->assertSame(2, $sector->fresh()->dias_gestion);
        $this->assertStringContainsString('ya venía con 2 días', $response->getSession()->get('error'));
    }

    public function test_import_de_tres_columnas_no_borra_lo_que_ya_estaba_cargado(): void
    {
        Role::firstOrCreate(['name' => 'usuario_interno', 'guard_name' => 'web']);
        $admin = $this->userWith('users.create');

        $sector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial', 'dias_gestion' => 2]);
        $supervisor = User::factory()->create();
        $existente = User::factory()->create([
            'email' => 'existente@tublood.com',
            'sector_id' => $sector->id,
            'supervisor_id' => $supervisor->id,
            'es_gerente' => true,
        ]);

        $this->importar($admin, [['NOMBRE NUEVO', 'APELLIDO', 'existente@tublood.com']]);

        $existente->refresh();
        $this->assertSame('NOMBRE NUEVO', $existente->name);
        $this->assertSame($sector->id, $existente->sector_id);
        $this->assertSame($supervisor->id, $existente->supervisor_id);
        $this->assertTrue($existente->es_gerente);
    }
}
