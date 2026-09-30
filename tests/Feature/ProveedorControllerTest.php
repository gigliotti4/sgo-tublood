<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProveedorControllerTest extends TestCase
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

    private function proveedor(array $attrs = []): Proveedor
    {
        return Proveedor::create(array_merge([
            'numero' => '933',
            'razon_social' => 'ANTONIO LUQUIN S A C I F E I',
            'domicilio' => 'GRAL GUEMES 897',
        ], $attrs));
    }

    public function test_el_listado_requiere_permiso_proveedores_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/proveedores')
            ->assertStatus(403);
    }

    public function test_el_listado_muestra_los_proveedores(): void
    {
        $this->proveedor();
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Proveedores/Index')
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.numero', '933')
                ->where('proveedores.data.0.razon_social', 'ANTONIO LUQUIN S A C I F E I'));
    }

    public function test_el_buscador_filtra_por_razon_social_y_por_numero(): void
    {
        $this->proveedor();
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL', 'domicilio' => 'LISANDRO DE LA TORRE 4101']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?search=CRONO')
            ->assertInertia(fn ($page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.razon_social', 'CRONOINK SRL'));

        $this->actingAs($user)
            ->get('/proveedores?search=933')
            ->assertInertia(fn ($page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.numero', '933'));
    }

    /**
     * ⚠️ La regresión: con la regla `email` había 128 proveedores que **no se
     * podían guardar**. El formulario devolvía el mail tal como vino de RP
     * —varias direcciones separadas por `;`— la validación lo rechazaba, y de
     * paso quedaban bloqueadas Observaciones y el tipo, que sí son del panel.
     */
    public function test_se_puede_guardar_un_proveedor_con_varios_mails(): void
    {
        $proveedor = $this->proveedor(['mail' => 'a@b.com;c@d.com']);

        $this->actingAs($this->userWith('proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => $proveedor->razon_social,
                'mail' => 'a@b.com;c@d.com',
                'observaciones' => 'Corregido a mano',
                'tiene_legajo' => '0',
                'habilitado' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Corregido a mano', $proveedor->fresh()->observaciones);
    }

    /** Mismo caso, con el mail que RP cargó como nombre para mostrar. */
    public function test_se_puede_guardar_un_mail_con_nombre_para_mostrar(): void
    {
        $proveedor = $this->proveedor(['mail' => 'Hugo - Libertador <hlt@libertadorfactoring.com.ar>']);

        $this->actingAs($this->userWith('proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => $proveedor->razon_social,
                'mail' => 'Hugo - Libertador <hlt@libertadorfactoring.com.ar>',
                'tiene_legajo' => '0',
                'habilitado' => '1',
            ])
            ->assertSessionHasNoErrors();
    }

    /** El listado necesita `mails` para mostrar uno y contar el resto. */
    public function test_el_listado_manda_los_mails_ya_separados(): void
    {
        $this->proveedor(['mail' => 'uno@x.com; dos@x.com']);

        $this->actingAs($this->userWith('proveedores.view'))
            ->get('/proveedores')
            ->assertInertia(fn ($page) => $page
                ->where('proveedores.data.0.mails', ['uno@x.com', 'dos@x.com']));
    }

    // ── Filtro por la clasificación del ERP (AGRU_1) ─────────────────────────

    public function test_filtra_por_la_clasificacion_del_erp(): void
    {
        $this->proveedor(['clasificacion_erp' => '01']);
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL', 'clasificacion_erp' => '03']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?clasificacion_erp=01')
            ->assertInertia(fn ($page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.numero', '933'));
    }

    /**
     * ⚠️ "Sin clasificar" necesita un centinela propio: con la cadena vacía
     * sería indistinguible de "sin filtro" y devolvería el padrón entero.
     */
    public function test_el_centinela_sin_clasificar_trae_los_que_el_erp_no_clasifico(): void
    {
        $this->proveedor(['clasificacion_erp' => '01']);
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL', 'clasificacion_erp' => null]);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?clasificacion_erp=sin_clasificar')
            ->assertInertia(fn ($page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.numero', '1246'));
    }

    /**
     * El catálogo es de RP, no nuestro: pueden sumar un código cualquier día.
     * Filtrar por uno que todavía no tiene etiqueta tiene que funcionar igual,
     * mismo criterio que un `?sort=` desconocido.
     */
    public function test_un_codigo_fuera_del_catalogo_igual_filtra(): void
    {
        $this->proveedor(['clasificacion_erp' => '04']);
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL', 'clasificacion_erp' => '01']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?clasificacion_erp=04')
            ->assertInertia(fn ($page) => $page
                ->has('proveedores.data', 1)
                ->where('proveedores.data.0.numero', '933'));
    }

    public function test_el_listado_manda_el_catalogo_de_clasificaciones(): void
    {
        $this->proveedor();

        $this->actingAs($this->userWith('proveedores.view'))
            ->get('/proveedores')
            ->assertInertia(fn ($page) => $page
                ->where('clasificaciones.01', 'PROV. APROBADOS DE PM'));
    }

    /** El export baja lo mismo que se ve: ver el test equivalente del buscador. */
    public function test_exportar_respeta_el_filtro_de_clasificacion(): void
    {
        $this->proveedor(['clasificacion_erp' => '01']);
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL', 'clasificacion_erp' => '03']);

        $response = $this->actingAs($this->userWith('proveedores.view'))
            ->get('/proveedores/export?clasificacion_erp=01');

        $response->assertOk();
        $filas = $this->filasDelExcel($response);

        $this->assertCount(2, $filas, 'Encabezado + un solo proveedor.');
        $this->assertSame('933', $filas[1][0]);
    }

    /** El contador del encabezado necesita el total sin filtrar, no el del paginador. */
    public function test_el_listado_manda_el_total_sin_filtrar(): void
    {
        $this->proveedor();
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?search=CRONO')
            ->assertInertia(fn ($page) => $page
                ->where('total', 2)
                ->where('proveedores.total', 1));
    }

    // ── Exportación a Excel ──────────────────────────────────────────────────

    public function test_exportar_requiere_permiso_proveedores_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/proveedores/export')
            ->assertStatus(403);
    }

    public function test_exportar_devuelve_un_xlsx(): void
    {
        $this->proveedor();
        $user = $this->userWith('proveedores.view');

        $response = $this->actingAs($user)->get('/proveedores/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString(
            'proveedores-'.now()->format('Y-m-d').'.xlsx',
            $response->headers->get('content-disposition')
        );
    }

    /** Lo que ves es lo que baja: el archivo respeta el buscador. */
    public function test_exportar_respeta_el_filtro_del_listado(): void
    {
        $this->proveedor();
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL']);
        $user = $this->userWith('proveedores.view');

        $filas = $this->filasDelExcel(
            $this->actingAs($user)->get('/proveedores/export?search=CRONO')
        );

        // Encabezado + una sola fila de datos.
        $this->assertCount(2, $filas);
        $this->assertSame('CRONOINK SRL', $filas[1][1]);
    }

    /** Un proveedor sin número (los creó el Excel de artículos) no rompe el archivo. */
    public function test_exportar_tolera_un_proveedor_sin_numero(): void
    {
        Proveedor::create(['razon_social' => 'SIN NUMERO SRL']);
        $user = $this->userWith('proveedores.view');

        $filas = $this->filasDelExcel($this->actingAs($user)->get('/proveedores/export'));

        $this->assertSame('sin número', $filas[1][0]);
        $this->assertSame('SIN NUMERO SRL', $filas[1][1]);
    }

    /** Abre el xlsx que devolvió la respuesta y lo lee como array de filas. */
    private function filasDelExcel($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }

    public function test_editar_requiere_permiso_proveedores_edit(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)->get("/proveedores/{$proveedor->id}/edit")->assertStatus(403);
        $this->actingAs($user)->put("/proveedores/{$proveedor->id}", [
            'razon_social' => 'OTRA COSA',
        ])->assertStatus(403);
    }

    public function test_update_guarda_los_campos_propios_del_panel(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.edit');

        $this->actingAs($user)->put("/proveedores/{$proveedor->id}", [
            'razon_social' => 'ANTONIO LUQUIN S.A.',
            'domicilio' => 'GRAL GUEMES 897',
            'cuit' => '30-12345678-9',
            'telefono' => '11 4444-5555',
            'mail' => 'compras@luquin.com.ar',
            'localidad' => 'CABA',
            'observaciones' => 'Entrega los martes.',
        ])->assertRedirect('/proveedores');

        $proveedor->refresh();
        $this->assertSame('ANTONIO LUQUIN S.A.', $proveedor->razon_social);
        $this->assertSame('30-12345678-9', $proveedor->cuit);
        $this->assertSame('compras@luquin.com.ar', $proveedor->mail);
        $this->assertSame('CABA', $proveedor->localidad);
    }

    /** El número es la clave del import: mandarlo en el request no tiene que cambiarlo. */
    public function test_update_no_cambia_el_numero(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.edit');

        $this->actingAs($user)->put("/proveedores/{$proveedor->id}", [
            'numero' => '9999',
            'razon_social' => 'ANTONIO LUQUIN S A C I F E I',
        ])->assertRedirect('/proveedores');

        $this->assertSame('933', $proveedor->fresh()->numero);
    }

    public function test_el_buscador_requiere_permiso_proveedores_view(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/proveedores/buscar?q=CRONO')
            ->assertStatus(403);
    }

    public function test_el_buscador_devuelve_id_numero_y_razon_social(): void
    {
        $this->proveedor(['numero' => '1246', 'razon_social' => 'CRONOINK SRL']);
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->getJson('/proveedores/buscar?q=CRONO')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([['id', 'numero', 'razon_social']])
            ->assertJsonPath('0.razon_social', 'CRONOINK SRL');
    }

    /** Menos de 2 caracteres no es un error: es "todavía no hay nada que sugerir". */
    public function test_el_buscador_con_un_termino_corto_devuelve_lista_vacia(): void
    {
        $this->proveedor();
        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->getJson('/proveedores/buscar?q=C')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_update_exige_razon_social(): void
    {
        $proveedor = $this->proveedor();
        $user = $this->userWith('proveedores.edit');

        $this->actingAs($user)
            ->put("/proveedores/{$proveedor->id}", ['razon_social' => ''])
            ->assertSessionHasErrors('razon_social');
    }
}
