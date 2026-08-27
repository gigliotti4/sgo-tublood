<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\User;
use App\Services\RpSistemas\ProveedorSyncService;
use App\Services\RpSistemas\RpSistemasClient;
use App\Support\Documentacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * La clasificación documental de proveedores usa el mismo catálogo y el mismo
 * trait que la de clientes (App\Models\Concerns\ClasificacionDocumental), así
 * que acá se cubre lo propio del proveedor: sus rutas, que la sincronización
 * con el ERP no pise lo cargado, y que `habilitado` y `estado` sean cosas
 * distintas.
 */
class ProveedorClasificacionTest extends TestCase
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
            'numero' => '100',
            'razon_social' => 'PROPATO HNOS SAIC',
        ], $attrs));
    }

    public function test_update_setea_la_clasificacion(): void
    {
        $proveedor = $this->proveedor();

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => 'PROPATO HNOS SAIC',
                'tipo_proveedor' => 'importador',
                'tiene_legajo' => true,
                'habilitado' => false,
            ])
            ->assertRedirect(route('proveedores.edit', $proveedor));

        $proveedor->refresh();

        $this->assertSame('importador', $proveedor->tipo_proveedor);
        $this->assertTrue($proveedor->tiene_legajo);
        $this->assertFalse($proveedor->habilitado);
    }

    public function test_update_rechaza_un_tipo_fuera_del_catalogo(): void
    {
        $proveedor = $this->proveedor();

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => 'PROPATO HNOS SAIC',
                'tipo_proveedor' => 'kiosco',
                'tiene_legajo' => false,
                'habilitado' => true,
            ])
            ->assertSessionHasErrors('tipo_proveedor');
    }

    public function test_el_vencimiento_sale_del_documento_determinante(): void
    {
        $proveedor = $this->proveedor(['tipo_proveedor' => 'importador']);

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => $proveedor->razon_social,
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_anmat' => ['presentado' => true, 'fecha_vencimiento' => '2027-05-01'],
                    'certificado_funcionamiento' => ['presentado' => true, 'fecha_vencimiento' => '2027-01-01'],
                    'bpf' => ['presentado' => true, 'fecha_vencimiento' => '2027-03-10'],
                ],
            ])
            ->assertRedirect(route('proveedores.edit', $proveedor));

        $proveedor->refresh();

        $this->assertTrue($proveedor->documentacion_completa);
        $this->assertSame('2027-03-10', $proveedor->fecha_vencimiento->toDateString());
    }

    public function test_un_documento_vencido_no_cuenta_como_completa(): void
    {
        $proveedor = $this->proveedor(['tipo_proveedor' => 'importador']);

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => $proveedor->razon_social,
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_anmat' => ['presentado' => true, 'fecha_vencimiento' => now()->subDay()->toDateString()],
                    'certificado_funcionamiento' => ['presentado' => true, 'fecha_vencimiento' => '2027-01-01'],
                    'bpf' => ['presentado' => true, 'fecha_vencimiento' => '2027-03-10'],
                ],
            ]);

        $proveedor->refresh();
        $estado = $proveedor->estadoDocumentacion();

        $this->assertFalse($proveedor->documentacion_completa);
        $this->assertSame(['Habilitación ANMAT'], array_column($estado['vencidos'], 'label'));
    }

    public function test_rechaza_un_documento_que_el_tipo_no_pide(): void
    {
        $proveedor = $this->proveedor(['tipo_proveedor' => 'farmacia']);

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => $proveedor->razon_social,
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_ministerio' => ['presentado' => true],
                    'bpf' => ['presentado' => true],
                ],
            ])
            ->assertSessionHasErrors('documentos');

        $this->assertCount(0, $proveedor->fresh()->documentos);
    }

    /**
     * Sin tipo no hay documentos que exigir, así que cualquier clave que venga
     * en el checklist sobra. Lo rechazan las reglas del propio tipo (vacías),
     * no un guard aparte: desde que el checklist se guarda junto con el tipo,
     * el caso "documentos sin tipo" es el mismo que "documento que este tipo no
     * pide" del test de arriba.
     */
    public function test_no_se_puede_cargar_documentacion_sin_tipo(): void
    {
        $proveedor = $this->proveedor();

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => $proveedor->razon_social,
                'documentos' => ['constancia_arca' => ['presentado' => true]],
            ])
            ->assertSessionHasErrors('documentos');

        $this->assertCount(0, $proveedor->fresh()->documentos);
    }

    public function test_el_listado_filtra_por_tipo_y_estado_documental(): void
    {
        $completo = $this->proveedor(['numero' => '1', 'razon_social' => 'IMPORTADORA ALFA']);
        $completo->update(['tipo_proveedor' => 'farmacia']);
        $completo->documentos()->createMany([
            ['documento' => 'constancia_arca', 'presentado' => true],
            ['documento' => 'habilitacion_ministerio', 'presentado' => true],
        ]);
        $completo->recalcularEstadoDocumental();

        $this->proveedor(['numero' => '2', 'razon_social' => 'BETA SRL']);

        $user = $this->userWith('proveedores.view');

        $this->actingAs($user)
            ->get('/proveedores?estado_documental=completa')
            ->assertInertia(fn ($page) => $page->has('proveedores.data', 1)
                ->where('proveedores.data.0.id', $completo->id));

        $this->actingAs($user)
            ->get('/proveedores?estado_documental=sin_tipo')
            ->assertInertia(fn ($page) => $page->has('proveedores.data', 1)
                ->where('proveedores.data.0.razon_social', 'BETA SRL'));

        $this->actingAs($user)
            ->get('/proveedores?tipo_proveedor=farmacia')
            ->assertInertia(fn ($page) => $page->has('proveedores.data', 1)
                ->where('proveedores.data.0.id', $completo->id));
    }

    /**
     * ⚠️ `habilitado` (panel) y `estado` (ERP) son cosas distintas: la sync
     * pisa el segundo y no puede tocar el primero, ni el resto de la
     * clasificación.
     */
    public function test_la_sincronizacion_no_pisa_la_clasificacion(): void
    {
        $proveedor = $this->proveedor([
            'numero' => '933',
            'razon_social' => 'NOMBRE VIEJO',
            'estado' => 'I',
            'tipo_proveedor' => 'importador',
            'tiene_legajo' => true,
            'habilitado' => false,
            'documentacion_completa' => true,
            'observaciones' => 'Pidió prórroga.',
        ]);
        $proveedor->documentos()->create([
            'documento' => 'bpf', 'presentado' => true, 'fecha_vencimiento' => '2027-03-10',
        ]);
        $proveedor->recalcularEstadoDocumental();

        Http::fake(['*' => Http::response([
            'datos' => [[
                'numero' => '933',
                'razon' => 'NOMBRE NUEVO SA',
                'estado' => 'A',
            ]],
            'paginado' => ['pagina' => 1, 'total_paginas' => 1],
        ], 200)]);

        (new ProveedorSyncService(new RpSistemasClient))->sync();

        $proveedor->refresh();

        // Lo del ERP se actualiza...
        $this->assertSame('A', $proveedor->estado);
        // ...y lo del panel sobrevive.
        $this->assertSame('importador', $proveedor->tipo_proveedor);
        $this->assertTrue($proveedor->tiene_legajo);
        $this->assertFalse($proveedor->habilitado);
        $this->assertSame('Pidió prórroga.', $proveedor->observaciones);
        $this->assertSame('2027-03-10', $proveedor->fecha_vencimiento->toDateString());
        $this->assertCount(1, $proveedor->documentos);
    }

    public function test_el_export_trae_la_clasificacion(): void
    {
        $proveedor = $this->proveedor(['tipo_proveedor' => 'importador', 'tiene_legajo' => true]);
        $proveedor->documentos()->create([
            'documento' => 'bpf', 'presentado' => true, 'fecha_vencimiento' => '2027-03-10',
        ]);
        $proveedor->recalcularEstadoDocumental();

        $response = $this->actingAs($this->userWith('proveedores.view'))->get('/proveedores/export');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $filas = IOFactory::load($path)->getActiveSheet()->toArray();
        $fila = array_combine($filas[0], $filas[1]);

        $this->assertSame('Importador', $fila['Tipo de proveedor']);
        $this->assertSame('SÍ', $fila['Tiene legajo']);
        $this->assertSame('SÍ', $fila['BPF']);
        $this->assertSame('10/03/2027', $fila['BPF - Vto']);
        $this->assertSame('10/03/2027', $fila['Vencimiento del proveedor']);
        // Un documento que Importador no pide queda en blanco.
        $this->assertSame('', (string) $fila['DDJJ']);
    }

    /**
     * Un proveedor solo puede ser una de seis figuras: las otras cuatro
     * (profesional independiente, centro médico, veterinaria e institución) le
     * compran a Tublood, no le venden.
     */
    public function test_solo_ofrece_los_seis_tipos_que_pueden_ser_proveedor(): void
    {
        $this->assertSame([
            'laboratorio_analisis_clinicos',
            'drogueria',
            'farmacia',
            'distribuidor',
            'importador',
            'laboratorio_fabricante',
        ], array_keys(Documentacion::tipos(Documentacion::PROVEEDORES)));

        // Los clientes siguen teniendo los diez: el recorte es solo del lado
        // del proveedor.
        $this->assertCount(10, Documentacion::tipos(Documentacion::CLIENTES));
    }

    public function test_no_se_puede_clasificar_un_proveedor_con_un_tipo_solo_de_clientes(): void
    {
        $proveedor = $this->proveedor();

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->put("/proveedores/{$proveedor->id}", [
                'razon_social' => 'PROPATO HNOS SAIC',
                'tipo_proveedor' => 'veterinaria',
                'tiene_legajo' => false,
                'habilitado' => true,
            ])
            ->assertSessionHasErrors('tipo_proveedor');

        $this->assertNull($proveedor->fresh()->tipo_proveedor);
    }

    /**
     * Sacar un tipo de la lista de una entidad no puede romper lo ya cargado:
     * el nombre y el checklist salen del catálogo completo, no de la lista
     * recortada.
     */
    public function test_un_proveedor_ya_clasificado_con_un_tipo_retirado_sigue_mostrando_su_checklist(): void
    {
        $proveedor = $this->proveedor(['tipo_proveedor' => 'veterinaria']);

        $this->assertSame('Veterinaria', Documentacion::etiqueta('veterinaria'));
        $this->assertCount(4, Documentacion::documentos('veterinaria'));

        $this->actingAs($this->userWith('proveedores.view', 'proveedores.edit'))
            ->get("/proveedores/{$proveedor->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('catalogoDocumentos.veterinaria', 4));
    }
}
