<?php

namespace Tests\Feature;

use App\Models\Configuracion as ConfiguracionModel;
use App\Models\User;
use App\Support\Configuracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El helper cachea para siempre: sin esto, un test se lleva puesto al
        // siguiente con los valores del anterior.
        Configuracion::olvidar();
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_la_pantalla_requiere_permiso(): void
    {
        $this->actingAs($this->userWith('users.view'))
            ->get('/configuracion')
            ->assertForbidden();

        $this->actingAs($this->userWith('configuracion.view'))
            ->get('/configuracion')
            ->assertOk();
    }

    /** Una clave sin fila en la tabla cae en el default del catálogo. */
    public function test_una_clave_sin_valor_guardado_devuelve_el_default_del_config(): void
    {
        $this->assertSame(
            config('configuracion.claves.empresa_nombre.default'),
            Configuracion::get('empresa_nombre')
        );
    }

    public function test_guardar_cambia_el_valor_y_limpia_la_cache(): void
    {
        // Se lee antes para dejar la caché poblada: si `update()` no la
        // limpiara, la lectura de después devolvería el valor viejo.
        $this->assertSame('Tublood SA', Configuracion::get('empresa_nombre'));

        $this->actingAs($this->userWith('configuracion.view', 'configuracion.edit'))
            ->post('/configuracion', ['empresa_nombre' => 'Otra Empresa SA'])
            ->assertRedirect(route('configuracion.index'));

        $this->assertSame('Otra Empresa SA', Configuracion::get('empresa_nombre'));
    }

    /** Lo que no está declarado en el catálogo no se guarda. */
    public function test_una_clave_fuera_del_catalogo_se_descarta(): void
    {
        $this->actingAs($this->userWith('configuracion.view', 'configuracion.edit'))
            ->post('/configuracion', [
                'empresa_nombre' => 'Otra Empresa SA',
                'clave_inventada' => 'valor',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('configuraciones', ['clave' => 'clave_inventada']);
        $this->assertDatabaseHas('configuraciones', ['clave' => 'empresa_nombre']);
    }

    public function test_guardar_requiere_permiso_de_edicion(): void
    {
        $this->actingAs($this->userWith('configuracion.view'))
            ->post('/configuracion', ['empresa_nombre' => 'No debería guardarse'])
            ->assertForbidden();

        $this->assertSame('Tublood SA', Configuracion::get('empresa_nombre'));
    }

    public function test_subir_un_logo_lo_deja_en_el_disco_public(): void
    {
        Storage::fake('public');

        $this->actingAs($this->userWith('configuracion.view', 'configuracion.edit'))
            ->post('/configuracion', ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertRedirect();

        $path = Configuracion::get('logo');

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    /** La tilde de "quitar" borra el archivo y vuelve el valor a null. */
    public function test_borrar_el_logo_lo_saca_del_disco_y_de_la_configuracion(): void
    {
        Storage::fake('public');

        $user = $this->userWith('configuracion.view', 'configuracion.edit');

        $this->actingAs($user)
            ->post('/configuracion', ['logo' => UploadedFile::fake()->image('logo.png')]);

        $path = Configuracion::get('logo');
        $this->assertNotNull($path);

        $this->actingAs($user)
            ->post('/configuracion', ['_borrar' => ['logo']])
            ->assertRedirect();

        $this->assertNull(Configuracion::get('logo'));
        Storage::disk('public')->assertMissing($path);
    }

    /** Un texto vaciado a propósito queda vacío, no vuelve al default. */
    public function test_un_texto_vaciado_no_vuelve_al_default(): void
    {
        $this->actingAs($this->userWith('configuracion.view', 'configuracion.edit'))
            ->post('/configuracion', ['empresa_bajada' => '']);

        $this->assertSame('', Configuracion::get('empresa_bajada'));
    }

    /** La configuración viaja a todas las pantallas, incluidas las públicas. */
    public function test_se_comparte_en_las_pantallas_publicas(): void
    {
        ConfiguracionModel::create(['clave' => 'empresa_nombre', 'valor' => 'Marca Nueva']);
        Configuracion::olvidar();

        $this->get('/login')->assertOk()->assertInertia(
            fn ($page) => $page->where('configuracion.empresa_nombre', 'Marca Nueva')
        );
    }

    /**
     * Con la cache poblada, agregar una clave al catalogo tiene que verse en el
     * acto. Antes se cacheaba el array ya resuelto, asi que una clave nueva no
     * estaba en la cache vieja y `paraCompartir()` reventaba con "Undefined
     * array key" hasta que alguien limpiara la cache a mano.
     */
    public function test_una_clave_agregada_al_catalogo_no_rompe_con_la_cache_poblada(): void
    {
        // Poblar la cache con el catalogo actual.
        Configuracion::valores();

        config(['configuracion.claves.clave_nueva' => [
            'grupo' => 'marca',
            'label' => 'Clave nueva',
            'tipo' => 'texto',
            'default' => 'valor por defecto',
        ]]);

        $this->assertSame('valor por defecto', Configuracion::get('clave_nueva'));
        $this->assertArrayHasKey('clave_nueva', Configuracion::paraCompartir());
    }

    /** Lo mismo para una clave de imagen, que es la que indexaba sin guarda. */
    public function test_una_clave_de_imagen_agregada_al_catalogo_no_rompe(): void
    {
        Configuracion::valores();

        config(['configuracion.claves.imagen_nueva' => [
            'grupo' => 'marca',
            'label' => 'Imagen nueva',
            'tipo' => 'imagen',
            'default' => null,
        ]]);

        $this->assertNull(Configuracion::paraCompartir()['imagen_nueva']);
    }
}
