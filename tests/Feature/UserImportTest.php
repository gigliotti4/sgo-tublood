<?php

namespace Tests\Feature;

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
        return ['NOMBRE', 'APELLIDO', 'MAIL'];
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
}
