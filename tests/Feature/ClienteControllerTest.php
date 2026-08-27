<?php

namespace Tests\Feature;

use App\Jobs\SyncClientesJob;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClienteControllerTest extends TestCase
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

    public function test_index_requiere_permiso_clientes_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/clientes')->assertStatus(403);
    }

    public function test_index_accesible_con_permiso(): void
    {
        $user = $this->userWith('clientes.view');
        $this->actingAs($user)->get('/clientes')->assertStatus(200);
    }

    public function test_busqueda_filtra_por_razon_social(): void
    {
        Cliente::create([
            'numero' => '1', 'razon_social' => 'Empresa Alpha SA',
            'cuit' => '30-1234-0',
        ]);
        Cliente::create([
            'numero' => '2', 'razon_social' => 'Distribuidora Beta SRL',
            'cuit' => '30-9999-0',
        ]);

        $user = $this->userWith('clientes.view');
        $response = $this->actingAs($user)->get('/clientes?search=Alpha');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Admin/Clientes/Index')
            ->has('clientes.data', 1)
            ->where('clientes.data.0.razon_social', 'Empresa Alpha SA')
        );
    }

    /** El contador del encabezado necesita el total sin filtrar, no el del paginador. */
    public function test_el_listado_manda_el_total_sin_filtrar(): void
    {
        Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Alpha SA', 'cuit' => '30-1234-0']);
        Cliente::create(['numero' => '2', 'razon_social' => 'Distribuidora Beta SRL', 'cuit' => '30-9999-0']);

        $user = $this->userWith('clientes.view');

        $this->actingAs($user)
            ->get('/clientes?search=Alpha')
            ->assertInertia(fn ($page) => $page
                ->where('total', 2)
                ->where('clientes.total', 1));
    }

    public function test_sync_requiere_permiso_clientes_sync(): void
    {
        $user = $this->userWith('clientes.view');
        $this->actingAs($user)->post('/clientes/sync')->assertStatus(403);
    }

    public function test_sync_despacha_job_y_redirige(): void
    {
        Queue::fake();

        $user = $this->userWith('clientes.view', 'clientes.sync');
        $this->actingAs($user)
            ->post('/clientes/sync')
            ->assertRedirect('/clientes');

        Queue::assertPushed(SyncClientesJob::class);
    }

    public function test_edit_requiere_permiso_clientes_edit(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $user = $this->userWith('clientes.view');

        $this->actingAs($user)->get("/clientes/{$cliente->id}/edit")->assertStatus(403);
    }

    /** Los campos generales de la ficha: tipo, legajo, habilitado y notas. */
    public function test_update_setea_los_datos_generales(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $user = $this->userWith('clientes.view', 'clientes.edit');

        $this->actingAs($user)
            ->put("/clientes/{$cliente->id}", [
                'tipo_cliente' => 'importador',
                'tiene_legajo' => true,
                'habilitado' => false,
                'notas' => 'Pidió prórroga por el BPF.',
            ])
            ->assertRedirect(route('clientes.edit', $cliente));

        $cliente->refresh();

        $this->assertSame('importador', $cliente->tipo_cliente);
        $this->assertTrue($cliente->tiene_legajo);
        $this->assertFalse($cliente->habilitado);
        $this->assertSame('Pidió prórroga por el BPF.', $cliente->notas);
    }

    public function test_update_rechaza_un_tipo_de_cliente_fuera_del_catalogo(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'tipo_cliente' => 'kiosco',
                'tiene_legajo' => false,
                'habilitado' => true,
            ])
            ->assertSessionHasErrors('tipo_cliente');
    }

    /**
     * El vencimiento del cliente ya no se carga a mano: sale del documento que
     * el catálogo marca como `determina_vencimiento` (para importador, el BPF).
     */
    public function test_el_vencimiento_del_cliente_sale_del_documento_determinante(): void
    {
        $cliente = $this->importadorConDocumentacionCompleta();

        $this->assertSame('2027-03-10', $cliente->fresh()->fecha_vencimiento->toDateString());
        $this->assertTrue($cliente->fresh()->documentacion_completa);
    }

    /** Farmacia no tiene ningún documento con vencimiento: nunca vence. */
    public function test_un_tipo_sin_documento_determinante_deja_el_vencimiento_en_null(): void
    {
        $cliente = Cliente::create([
            'numero' => '1', 'razon_social' => 'Farmacia Test', 'tipo_cliente' => 'farmacia',
        ]);
        $user = $this->userWith('clientes.view', 'clientes.edit');

        $this->actingAs($user)
            ->put("/clientes/{$cliente->id}", [
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_ministerio' => ['presentado' => true],
                ],
            ])
            ->assertRedirect(route('clientes.edit', $cliente));

        $cliente->refresh();

        $this->assertNull($cliente->fecha_vencimiento);
        $this->assertTrue($cliente->documentacion_completa);
    }

    public function test_falta_un_obligatorio_y_la_documentacion_no_esta_completa(): void
    {
        $cliente = Cliente::create([
            'numero' => '1', 'razon_social' => 'Importadora Test', 'tipo_cliente' => 'importador',
        ]);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_anmat' => ['presentado' => true, 'fecha_vencimiento' => '2027-05-01'],
                    'certificado_funcionamiento' => ['presentado' => false],
                    'bpf' => ['presentado' => true, 'fecha_vencimiento' => '2027-03-10'],
                ],
            ]);

        $cliente->refresh();

        $this->assertFalse($cliente->documentacion_completa);
        $this->assertSame(['Certificado de Funcionamiento'], $cliente->estadoDocumentacion()['faltantes']);
        // El vencimiento igual sale del BPF, que sí está presentado.
        $this->assertSame('2027-03-10', $cliente->fecha_vencimiento->toDateString());
    }

    public function test_un_documento_vencido_no_cuenta_como_documentacion_completa(): void
    {
        $cliente = Cliente::create([
            'numero' => '1', 'razon_social' => 'Importadora Test', 'tipo_cliente' => 'importador',
        ]);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_anmat' => ['presentado' => true, 'fecha_vencimiento' => now()->subDay()->toDateString()],
                    'certificado_funcionamiento' => ['presentado' => true, 'fecha_vencimiento' => '2027-01-01'],
                    'bpf' => ['presentado' => true, 'fecha_vencimiento' => '2027-03-10'],
                ],
            ]);

        $cliente->refresh();
        $estado = $cliente->estadoDocumentacion();

        $this->assertFalse($cliente->documentacion_completa);
        $this->assertSame([], $estado['faltantes']);
        $this->assertSame(['Habilitación ANMAT'], array_column($estado['vencidos'], 'label'));
        // El próximo vencimiento es el más cercano de todos, no el del BPF.
        $this->assertSame(now()->subDay()->toDateString(), $estado['proximo_vencimiento']);
        $this->assertSame(-1, $estado['dias_para_vencer']);
    }

    /** La fecha de un papel que no entregaron no se guarda. */
    public function test_destildar_un_documento_le_borra_la_fecha(): void
    {
        $cliente = $this->importadorConDocumentacionCompleta();

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_anmat' => ['presentado' => true, 'fecha_vencimiento' => '2027-05-01'],
                    'certificado_funcionamiento' => ['presentado' => true, 'fecha_vencimiento' => '2027-01-01'],
                    'bpf' => ['presentado' => false, 'fecha_vencimiento' => '2027-03-10'],
                ],
            ]);

        $cliente->refresh();

        $this->assertNull($cliente->documentos->firstWhere('documento', 'bpf')->fecha_vencimiento);
        // Y el vencimiento del cliente se cae con él.
        $this->assertNull($cliente->fecha_vencimiento);
    }

    /**
     * Reclasificar tiene que recalcular: los documentos del tipo viejo dejan de
     * contar, y el vencimiento que traían deja de ser el del cliente.
     */
    public function test_cambiar_el_tipo_de_cliente_recalcula_el_estado(): void
    {
        $cliente = $this->importadorConDocumentacionCompleta();

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'tipo_cliente' => 'farmacia',
                'tiene_legajo' => false,
                'habilitado' => true,
            ]);

        $cliente->refresh();

        // Farmacia no pide BPF, así que ni vence ni está completa (le falta la
        // habilitación del Ministerio, que el tipo anterior no pedía).
        $this->assertNull($cliente->fecha_vencimiento);
        $this->assertFalse($cliente->documentacion_completa);
        $this->assertSame(['Habilitación Ministerio'], $cliente->estadoDocumentacion()['faltantes']);
    }

    public function test_guardar_la_documentacion_borra_los_documentos_del_tipo_anterior(): void
    {
        $cliente = $this->importadorConDocumentacionCompleta();
        $cliente->update(['tipo_cliente' => 'farmacia']);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_ministerio' => ['presentado' => true],
                ],
            ]);

        $this->assertSame(
            ['constancia_arca', 'habilitacion_ministerio'],
            $cliente->fresh()->documentos->pluck('documento')->sort()->values()->all()
        );
    }

    public function test_rechaza_un_documento_que_el_tipo_no_pide(): void
    {
        $cliente = Cliente::create([
            'numero' => '1', 'razon_social' => 'Farmacia Test', 'tipo_cliente' => 'farmacia',
        ]);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_ministerio' => ['presentado' => true],
                    'bpf' => ['presentado' => true],
                ],
            ])
            ->assertSessionHasErrors('documentos');

        $this->assertCount(0, $cliente->fresh()->documentos);
    }

    /**
     * Sin tipo no hay documentos que exigir, así que cualquier clave que venga
     * en el checklist sobra. Lo rechazan las reglas del propio tipo (vacías),
     * no un guard aparte: desde que el checklist se guarda junto con el tipo,
     * el caso "documentos sin tipo" es el mismo que "documento que este tipo no
     * pide" del test de arriba.
     */
    public function test_no_se_puede_cargar_documentacion_sin_tipo_de_cliente(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'documentos' => ['constancia_arca' => ['presentado' => true]],
            ])
            ->assertSessionHasErrors('documentos');

        $this->assertCount(0, $cliente->fresh()->documentos);
    }

    /**
     * Lo que motivó unificar el guardado: clasificar y cargar los papeles en
     * una sola pasada.
     *
     * Antes el checklist se armaba contra el tipo **ya guardado**, así que
     * había que guardar el tipo, esperar a que apareciera el bloque y guardar
     * de nuevo los vencimientos. Acá las dos cosas viajan juntas y los
     * documentos se validan contra el tipo que viene en el request.
     */
    public function test_clasificar_y_cargar_la_documentacion_en_un_solo_guardado(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Importadora Test SA']);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'tipo_cliente' => 'importador',
                'tiene_legajo' => true,
                'habilitado' => true,
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_anmat' => ['presentado' => true, 'fecha_vencimiento' => '2027-05-01'],
                    'certificado_funcionamiento' => ['presentado' => true, 'fecha_vencimiento' => '2027-01-01'],
                    'bpf' => ['presentado' => true, 'fecha_vencimiento' => '2027-03-10'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('clientes.edit', $cliente));

        $cliente->refresh();

        $this->assertSame('importador', $cliente->tipo_cliente);
        $this->assertTrue($cliente->tiene_legajo);
        $this->assertCount(4, $cliente->documentos);
        // Los dos derivados quedan bien de una, sin un segundo guardado.
        $this->assertTrue($cliente->documentacion_completa);
        $this->assertSame('2027-03-10', $cliente->fecha_vencimiento->toDateString());
    }

    /**
     * Reclasificar y cargar los papeles del tipo nuevo también entra en un solo
     * guardado: los documentos se validan contra el tipo del request, no contra
     * el que el cliente tenía.
     */
    public function test_reclasificar_y_cargar_el_checklist_del_tipo_nuevo_de_una(): void
    {
        $cliente = $this->importadorConDocumentacionCompleta();

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'tipo_cliente' => 'farmacia',
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_ministerio' => ['presentado' => true],
                ],
            ])
            ->assertSessionHasNoErrors();

        $cliente->refresh();

        $this->assertSame('farmacia', $cliente->tipo_cliente);
        // Los del tipo anterior que farmacia no pide se van; los compartidos quedan.
        $this->assertEqualsCanonicalizing(
            ['constancia_arca', 'habilitacion_ministerio'],
            $cliente->documentos->pluck('documento')->all(),
        );
        // Farmacia no tiene documento determinante: deja de vencer.
        $this->assertNull($cliente->fecha_vencimiento);
        $this->assertTrue($cliente->documentacion_completa);
    }

    /** La ficha manda el catálogo de todos los tipos para poder armar el checklist sin guardar. */
    public function test_la_ficha_manda_el_catalogo_de_documentos_de_todos_los_tipos(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Sin Clasificar SA']);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->get("/clientes/{$cliente->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('catalogoDocumentos.importador', 4)
                ->has('catalogoDocumentos.farmacia', 2)
                ->where('cliente.tipo_cliente', null));
    }

    public function test_el_listado_filtra_por_tipo_y_por_estado_documental(): void
    {
        $completo = $this->importadorConDocumentacionCompleta();
        Cliente::create(['numero' => '2', 'razon_social' => 'Farmacia Beta', 'tipo_cliente' => 'farmacia']);
        Cliente::create(['numero' => '3', 'razon_social' => 'Sin Clasificar SA']);

        $user = $this->userWith('clientes.view');

        $this->actingAs($user)
            ->get('/clientes?tipo_cliente=farmacia')
            ->assertInertia(fn ($page) => $page->has('clientes.data', 1)
                ->where('clientes.data.0.razon_social', 'Farmacia Beta'));

        $this->actingAs($user)
            ->get('/clientes?estado_documental=completa')
            ->assertInertia(fn ($page) => $page->has('clientes.data', 1)
                ->where('clientes.data.0.id', $completo->id));

        $this->actingAs($user)
            ->get('/clientes?estado_documental=sin_tipo')
            ->assertInertia(fn ($page) => $page->has('clientes.data', 1)
                ->where('clientes.data.0.razon_social', 'Sin Clasificar SA'));
    }

    /** El filtro `vencida` mira el checklist, no la columna denormalizada. */
    public function test_el_listado_filtra_por_documentacion_vencida(): void
    {
        $vigente = $this->importadorConDocumentacionCompleta();

        $vencido = Cliente::create([
            'numero' => '2', 'razon_social' => 'Droguería Vencida', 'tipo_cliente' => 'drogueria',
        ]);
        $vencido->documentos()->create([
            'documento' => 'certificado_funcionamiento',
            'presentado' => true,
            'fecha_vencimiento' => now()->subMonth(),
        ]);

        $this->actingAs($this->userWith('clientes.view'))
            ->get('/clientes?estado_documental=vencida')
            ->assertInertia(fn ($page) => $page->has('clientes.data', 1)
                ->where('clientes.data.0.id', $vencido->id)
                ->where('clientes.data.0.tiene_vencidos', true));

        $this->assertTrue($vigente->fresh()->documentacion_completa);
    }

    /** Importador con los 4 documentos presentados y vigentes. BPF vence 10/3/2027. */
    private function importadorConDocumentacionCompleta(): Cliente
    {
        $cliente = Cliente::create([
            'numero' => '1', 'razon_social' => 'Importadora Test SA', 'tipo_cliente' => 'importador',
        ]);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->put("/clientes/{$cliente->id}", [
                'documentos' => [
                    'constancia_arca' => ['presentado' => true],
                    'habilitacion_anmat' => ['presentado' => true, 'fecha_vencimiento' => '2027-05-01'],
                    'certificado_funcionamiento' => ['presentado' => true, 'fecha_vencimiento' => '2027-01-01'],
                    'bpf' => ['presentado' => true, 'fecha_vencimiento' => '2027-03-10'],
                ],
            ])
            ->assertSessionHasNoErrors();

        return $cliente->fresh();
    }

    public function test_los_archivos_van_a_una_carpeta_con_el_numero_de_cliente(): void
    {
        Storage::fake('local');

        $cliente = Cliente::create(['numero' => '1234', 'razon_social' => 'Empresa Test SA']);

        $this->actingAs($this->userWith('clientes.view', 'clientes.edit'))
            ->post("/clientes/{$cliente->id}/archivos", [
                'archivos' => [
                    UploadedFile::fake()->create('Contrato Marco.pdf', 50, 'application/pdf'),
                    // Mismo nombre: no se tiene que pisar al anterior.
                    UploadedFile::fake()->create('Contrato Marco.pdf', 50, 'application/pdf'),
                ],
            ]);

        $paths = $cliente->fresh()->attachments->pluck('path')->all();

        $this->assertSame([
            'clientes/1234/contrato-marco.pdf',
            'clientes/1234/contrato-marco-2.pdf',
        ], $paths);

        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }

        // El nombre real se conserva para mostrar y descargar.
        $this->assertSame('Contrato Marco.pdf', $cliente->attachments->first()->original_name);
    }

    public function test_subir_archivo_crea_adjunto_y_lo_guarda_en_disco(): void
    {
        Storage::fake('local');

        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $user = $this->userWith('clientes.view', 'clientes.edit');

        $this->actingAs($user)
            ->post("/clientes/{$cliente->id}/archivos", [
                'archivos' => [UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf')],
            ])
            ->assertRedirect(route('clientes.edit', $cliente));

        $this->assertCount(1, $cliente->fresh()->attachments);
        Storage::disk('local')->assertExists($cliente->attachments->first()->path);
    }

    /**
     * El caso feliz de la descarga no estaba cubierto: el único test que había
     * chequeaba el 403, así que un return type equivocado en el controller
     * (BinaryFileResponse en vez de StreamedResponse) devolvía 500 sin que
     * ningún test lo notara.
     */
    public function test_descargar_archivo_devuelve_el_contenido(): void
    {
        Storage::fake('local');

        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        Storage::disk('local')->put('clientes/contrato.pdf', 'contenido de prueba');

        $archivo = $cliente->attachments()->create([
            'path' => 'clientes/contrato.pdf',
            'original_name' => 'contrato.pdf',
            'mime_type' => 'application/pdf',
            'size' => 19,
        ]);

        $this->actingAs($this->userWith('clientes.view'))
            ->get("/clientes/{$cliente->id}/archivos/{$archivo->id}")
            ->assertOk()
            ->assertDownload('contrato.pdf');
    }

    public function test_descargar_archivo_requiere_permiso_clientes_view(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $archivo = $cliente->attachments()->create([
            'path' => 'clientes/no-existe.pdf',
            'original_name' => 'contrato.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get("/clientes/{$cliente->id}/archivos/{$archivo->id}")
            ->assertStatus(403);
    }

    public function test_borrar_archivo_elimina_fila_y_archivo_fisico(): void
    {
        Storage::fake('local');

        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $path = UploadedFile::fake()->create('contrato.pdf', 100)->store('clientes', 'local');
        $archivo = $cliente->attachments()->create([
            'path' => $path,
            'original_name' => 'contrato.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ]);

        $user = $this->userWith('clientes.view', 'clientes.edit');

        $this->actingAs($user)
            ->delete("/clientes/{$cliente->id}/archivos/{$archivo->id}")
            ->assertRedirect(route('clientes.edit', $cliente));

        $this->assertDatabaseMissing('cliente_attachments', ['id' => $archivo->id]);
        Storage::disk('local')->assertMissing($path);
    }

    // ── Autocompletado de razón social (clientes.buscar) ────────────────────

    public function test_buscar_requiere_permiso_clientes_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/clientes/buscar?numero=1')->assertStatus(403);
    }

    public function test_buscar_devuelve_la_razon_social_de_un_numero_existente(): void
    {
        Cliente::create(['numero' => '1234', 'razon_social' => 'Droguería del Sur SA']);

        $response = $this->actingAs($this->userWith('clientes.view'))
            ->get('/clientes/buscar?numero=1234');

        $response->assertOk();
        $response->assertJson(['numero' => '1234', 'razon_social' => 'Droguería del Sur SA']);
    }

    public function test_buscar_un_numero_inexistente_devuelve_vacio_sin_error(): void
    {
        $response = $this->actingAs($this->userWith('clientes.view'))
            ->get('/clientes/buscar?numero=99999');

        // No es 'null': response()->json(null) serializa como '{}' en esta
        // versión de Symfony (su constructor reemplaza null por un
        // ArrayObject vacío antes de codificar). Lo que importa para el
        // frontend es que no traiga 'id' — eso es lo que chequea para saber
        // si hubo match.
        $response->assertOk();
        $this->assertArrayNotHasKey('id', $response->json());
    }

    public function test_buscar_sin_numero_devuelve_vacio(): void
    {
        $response = $this->actingAs($this->userWith('clientes.view'))
            ->get('/clientes/buscar?numero=');

        // Mismo motivo que el test anterior: sin match la respuesta es '{}',
        // no 'null'.
        $response->assertOk();
        $this->assertArrayNotHasKey('id', $response->json());
    }

    /**
     * Es coincidencia exacta, no un buscador de texto: mismo criterio que
     * ObservacionController::clienteIdDesdeNumero(), que es lo que va a
     * vincular el caso al guardar.
     */
    public function test_buscar_no_matchea_por_texto_parcial(): void
    {
        Cliente::create(['numero' => '1234', 'razon_social' => 'Droguería del Sur SA']);

        $response = $this->actingAs($this->userWith('clientes.view'))
            ->get('/clientes/buscar?numero=123');

        // Mismo motivo que el test anterior: sin match la respuesta es '{}',
        // no 'null'.
        $response->assertOk();
        $this->assertArrayNotHasKey('id', $response->json());
    }

    /** No expone campos sensibles: solo lo que hace falta para autocompletar. */
    public function test_buscar_no_devuelve_campos_sensibles(): void
    {
        Cliente::create([
            'numero' => '1234',
            'razon_social' => 'Droguería del Sur SA',
            'mail' => 'contacto@drogueriadelsur.com',
            'telefono' => '011-4444-5555',
        ]);

        $response = $this->actingAs($this->userWith('clientes.view'))
            ->get('/clientes/buscar?numero=1234');

        $response->assertOk();
        $response->assertJsonStructure(['id', 'numero', 'razon_social']);
        $response->assertJsonMissing(['mail' => 'contacto@drogueriadelsur.com']);
        $this->assertArrayNotHasKey('telefono', $response->json());
    }
}
