<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Observacion;
use App\Models\Partida;
use App\Models\Proveedor;
use App\Models\ProveedorArticulo;
use App\Models\User;
use App\Services\RpSistemas\ProveedorArticuloSyncService;
use App\Services\VinculacionProveedores as VP;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Quién le pone el proveedor a cada artículo y quién puede pisar a quién.
 *
 * El punto de todo esto: RP va a ir cargando `codigo_proveedor` de a poco, y
 * ese dato tiene que corregir lo que el Excel adivinó mal **sin** pisar lo que
 * alguien corrigió a mano.
 */
class VinculacionProveedoresTest extends TestCase
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

    private function proveedor(string $numero, string $razon): Proveedor
    {
        return Proveedor::create(['numero' => $numero, 'razon_social' => $razon]);
    }

    /** @param array<string, mixed> $attrs */
    private function articulo(array $attrs = []): Articulo
    {
        return Articulo::create(array_merge([
            'codigo' => 'RE-1',
            'descripcion' => 'Artículo de prueba',
        ], $attrs));
    }

    // ---------- la tabla de precedencia ----------

    public function test_cualquier_fuente_escribe_sobre_un_articulo_sin_proveedor(): void
    {
        foreach (VP::PRECEDENCIA as $origen) {
            $this->assertTrue(VP::puedePisar(null, $origen), "{$origen} debería poder llenar un vacío");
        }
    }

    public function test_el_erp_pisa_al_excel_y_al_kardex(): void
    {
        $this->assertTrue(VP::puedePisar(VP::ORIGEN_EXCEL, VP::ORIGEN_ERP));
        $this->assertTrue(VP::puedePisar(VP::ORIGEN_KARDEX, VP::ORIGEN_ERP));
    }

    /** Si alguien lo corrigió a mano es porque lo automático estaba mal. */
    public function test_nada_automatico_pisa_una_correccion_manual(): void
    {
        foreach ([VP::ORIGEN_PRINCIPAL, VP::ORIGEN_ERP, VP::ORIGEN_EXCEL, VP::ORIGEN_KARDEX] as $origen) {
            $this->assertFalse(VP::puedePisar(VP::ORIGEN_MANUAL, $origen), "{$origen} no debería pisar manual");
        }
    }

    public function test_el_kardex_solo_llena_vacios(): void
    {
        foreach ([VP::ORIGEN_PRINCIPAL, VP::ORIGEN_ERP, VP::ORIGEN_EXCEL, VP::ORIGEN_KARDEX] as $actual) {
            $this->assertFalse(VP::puedePisar($actual, VP::ORIGEN_KARDEX));
        }
    }

    /** La pestaña Proveedores del ERP manda sobre `codigo_proveedor`, el Excel y el kardex. */
    public function test_el_principal_del_erp_pisa_a_todo_lo_automatico(): void
    {
        foreach ([VP::ORIGEN_ERP, VP::ORIGEN_EXCEL, VP::ORIGEN_KARDEX] as $actual) {
            $this->assertTrue(VP::puedePisar($actual, VP::ORIGEN_PRINCIPAL), "principal debería pisar {$actual}");
            $this->assertFalse(VP::puedePisar(VP::ORIGEN_PRINCIPAL, $actual), "{$actual} no debería pisar principal");
        }
    }

    public function test_el_excel_pisa_al_kardex_pero_no_al_erp(): void
    {
        $this->assertTrue(VP::puedePisar(VP::ORIGEN_KARDEX, VP::ORIGEN_EXCEL));
        $this->assertFalse(VP::puedePisar(VP::ORIGEN_ERP, VP::ORIGEN_EXCEL));
    }

    /** Un valor viejo o desconocido no puede dejar la columna clavada para siempre. */
    public function test_un_origen_desconocido_se_trata_como_vacio(): void
    {
        $this->assertTrue(VP::puedePisar('vaya-a-saber', VP::ORIGEN_KARDEX));
    }

    // ---------- por qué un código no sirve ----------

    /**
     * ⚠️ El criterio es **estar en el padrón**, no la forma del valor: un
     * código alfanumérico que el padrón tenga vincula igual. La forma solo
     * decide el mensaje que se le muestra a RP.
     */
    public function test_un_codigo_que_esta_en_el_padron_es_valido_aunque_no_sea_numerico(): void
    {
        $this->assertNull(VP::motivoDeInvalidez('PRO1', ['PRO1', '1320']));
        $this->assertNull(VP::motivoDeInvalidez('1320', ['PRO1', '1320']));
        $this->assertNull(VP::motivoDeInvalidez(' 1320 ', ['1320']), 'los espacios no cuentan');
        $this->assertNull(VP::motivoDeInvalidez('', ['1320']), 'vacío no es un código mal cargado');
    }

    /** Los valores reales que hoy ensucian el campo en RP. */
    public function test_distingue_un_costo_tipeado_de_un_codigo_inexistente(): void
    {
        $padron = ['1320'];

        $this->assertSame('Parece un costo, no un número de proveedor', VP::motivoDeInvalidez('941.825', $padron));
        $this->assertSame('No existe en el padrón de proveedores', VP::motivoDeInvalidez('24001IC04141225X', $padron));
        $this->assertSame('No existe en el padrón de proveedores', VP::motivoDeInvalidez('V-OBTU-p/JER100', $padron));
        $this->assertSame('No existe en el padrón de proveedores', VP::motivoDeInvalidez('999999', $padron));
    }

    // ---------- vinculación por codigo_proveedor ----------

    public function test_vincula_cuando_el_codigo_matchea_el_padron(): void
    {
        $proveedor = $this->proveedor('1320', 'INSUMOS SALUDABLES S.R.L.');
        $this->articulo(['codigo_proveedor' => '1320']);

        $this->assertSame(1, VP::desdeCodigoProveedor());

        $articulo = Articulo::first();
        $this->assertSame($proveedor->id, $articulo->proveedor_id);
        $this->assertSame(VP::ORIGEN_ERP, $articulo->proveedor_origen);
    }

    public function test_un_codigo_invalido_no_vincula_nada(): void
    {
        $this->proveedor('1320', 'INSUMOS SALUDABLES S.R.L.');
        $this->articulo(['codigo' => 'RE-1', 'codigo_proveedor' => '941.825']);
        $this->articulo(['codigo' => 'RE-2', 'codigo_proveedor' => 'V-OBTU-p/JER100']);

        $this->assertSame(0, VP::desdeCodigoProveedor());
        $this->assertNull(Articulo::where('codigo', 'RE-1')->value('proveedor_id'));
    }

    /** El caso que motiva todo esto: RP corrige lo que el Excel adivinó mal. */
    public function test_el_erp_corrige_un_proveedor_puesto_por_el_excel(): void
    {
        $malo = $this->proveedor('99', 'EL QUE ADIVINO EL EXCEL');
        $bueno = $this->proveedor('1320', 'EL QUE DICE RP');
        $this->articulo([
            'codigo_proveedor' => '1320',
            'proveedor_id' => $malo->id,
            'proveedor_origen' => VP::ORIGEN_EXCEL,
        ]);

        VP::desdeCodigoProveedor();

        $this->assertSame($bueno->id, Articulo::first()->proveedor_id);
    }

    public function test_el_erp_respeta_una_correccion_manual(): void
    {
        $manual = $this->proveedor('99', 'EL QUE ELIGIO UNA PERSONA');
        $this->proveedor('1320', 'EL QUE DICE RP');
        $this->articulo([
            'codigo_proveedor' => '1320',
            'proveedor_id' => $manual->id,
            'proveedor_origen' => VP::ORIGEN_MANUAL,
        ]);

        $this->assertSame(0, VP::desdeCodigoProveedor());
        $this->assertSame($manual->id, Articulo::first()->proveedor_id);
    }

    // ---------- vinculación por kardex ----------

    // ---------- el principal de la pestaña Proveedores del ERP (PROVE_ARTI) ----------

    private function renglon(string $articulo, string $numero, bool $principal = false): void
    {
        ProveedorArticulo::create([
            'codigo_articulo' => $articulo, 'proveedor_numero' => $numero, 'principal' => $principal,
        ]);
    }

    /** El caso que lo motivó: RE-1521 con C M INSUMOS principal e ICNA alternativo. */
    public function test_vincula_el_proveedor_marcado_principal(): void
    {
        $cm = $this->proveedor('22', 'C M INSUMOS Y SISTEMAS S.R.L.');
        $this->proveedor('6', 'ICNA S.A.');
        $this->articulo(['codigo' => 'RE-1521']);
        $this->renglon('RE-1521', '22', true);
        $this->renglon('RE-1521', '6');

        $this->assertSame(1, VP::desdeProveedorPrincipal());

        $articulo = Articulo::first();
        $this->assertSame($cm->id, $articulo->proveedor_id);
        $this->assertSame(VP::ORIGEN_PRINCIPAL, $articulo->proveedor_origen);
    }

    public function test_con_un_solo_proveedor_lo_toma_aunque_no_este_marcado(): void
    {
        $unico = $this->proveedor('22', 'UNICO SA');
        $this->articulo(['codigo' => 'RE-1']);
        $this->renglon('RE-1', '22');

        VP::desdeProveedorPrincipal();

        $this->assertSame($unico->id, Articulo::first()->proveedor_id);
    }

    /** 54 artículos tenían dos o más marcados el 14/10/2026, y 4 varios sin ninguno: no se elige. */
    public function test_con_dos_principales_o_ninguno_entre_varios_no_vincula(): void
    {
        $this->proveedor('5', 'A SA');
        $this->proveedor('6', 'B SA');
        $this->articulo(['codigo' => 'RE-012']);
        $this->articulo(['codigo' => 'TONER']);
        $this->renglon('RE-012', '5', true);
        $this->renglon('RE-012', '6', true);
        $this->renglon('TONER', '5');
        $this->renglon('TONER', '6');

        $this->assertSame(0, VP::desdeProveedorPrincipal());
        $this->assertSame(0, Articulo::whereNotNull('proveedor_id')->count());
    }

    public function test_el_principal_corrige_lo_que_adivinaron_el_kardex_y_el_excel(): void
    {
        $adivinado = $this->proveedor('183', 'EL DEL KARDEX');
        $correcto = $this->proveedor('1606', 'EL PRINCIPAL');
        $this->articulo(['codigo' => 'RE-3877', 'proveedor_id' => $adivinado->id, 'proveedor_origen' => VP::ORIGEN_KARDEX]);
        $this->articulo(['codigo' => 'RE-3007', 'proveedor_id' => $adivinado->id, 'proveedor_origen' => VP::ORIGEN_EXCEL]);
        $this->renglon('RE-3877', '1606', true);
        $this->renglon('RE-3007', '1606', true);

        $this->assertSame(2, VP::desdeProveedorPrincipal());
        $this->assertSame(2, Articulo::where('proveedor_id', $correcto->id)->where('proveedor_origen', VP::ORIGEN_PRINCIPAL)->count());
    }

    public function test_el_principal_respeta_una_correccion_manual(): void
    {
        $aMano = $this->proveedor('500', 'EL QUE CARGÓ CALIDAD');
        $this->proveedor('22', 'EL DEL ERP');
        $this->articulo(['codigo' => 'RE-1', 'proveedor_id' => $aMano->id, 'proveedor_origen' => VP::ORIGEN_MANUAL]);
        $this->renglon('RE-1', '22', true);

        $this->assertSame(0, VP::desdeProveedorPrincipal());
        $this->assertSame($aMano->id, Articulo::first()->proveedor_id);
    }

    /**
     * ⚠️ El único origen que se reescribe a sí mismo: si Compras cambia el principal en el ERP, el
     * padrón lo sigue. Y la corrida siguiente sin cambios no cuenta nada como "vinculado".
     */
    public function test_si_cambia_el_principal_en_el_erp_el_padron_lo_sigue(): void
    {
        $viejo = $this->proveedor('22', 'EL DE ANTES');
        $nuevo = $this->proveedor('6', 'EL DE AHORA');
        $this->articulo(['codigo' => 'RE-1', 'proveedor_id' => $viejo->id, 'proveedor_origen' => VP::ORIGEN_PRINCIPAL]);
        $this->renglon('RE-1', '22');
        $this->renglon('RE-1', '6', true);

        $this->assertSame(1, VP::desdeProveedorPrincipal());
        $this->assertSame($nuevo->id, Articulo::first()->proveedor_id);
        $this->assertSame(0, VP::desdeProveedorPrincipal(), 'Reescribir el mismo valor no es vincular');
    }

    /** El ERP completa y nunca borra, como `pm`. */
    public function test_si_el_articulo_desaparece_de_prove_arti_no_se_le_borra_el_proveedor(): void
    {
        $proveedor = $this->proveedor('22', 'EL DE ANTES');
        $this->articulo(['codigo' => 'RE-1', 'proveedor_id' => $proveedor->id, 'proveedor_origen' => VP::ORIGEN_PRINCIPAL]);

        $this->assertSame(0, VP::desdeProveedorPrincipal());
        $this->assertSame($proveedor->id, Articulo::first()->proveedor_id);
    }

    public function test_un_principal_que_no_esta_en_el_padron_no_vincula(): void
    {
        $this->articulo(['codigo' => 'RE-1']);
        $this->renglon('RE-1', '9999', true);

        $this->assertSame(0, VP::desdeProveedorPrincipal());
        $this->assertNull(Articulo::first()->proveedor_id);
    }

    public function test_el_sync_normaliza_el_codigo_y_la_marca_de_principal(): void
    {
        $servicio = new ProveedorArticuloSyncService;
        $ahora = Carbon::parse('2026-10-14 10:00:00');

        $fila = $servicio->mapear(['ARTICULO' => ' re-1521 ', 'PROVE' => 22, 'PRINCIPAL' => 1, 'CODI_PROVE' => '  '], $ahora);
        $this->assertSame('RE-1521', $fila['codigo_articulo']);
        $this->assertSame('22', $fila['proveedor_numero']);
        $this->assertTrue($fila['principal']);
        $this->assertNull($fila['codigo_proveedor']);

        $crudo = $servicio->mapear(['ARTICULO' => 'RE-1521', 'PROVE' => '6', 'PRINCIPAL' => 'N', 'CODI_PROVE' => 'ML-VIT-DEXTR'], $ahora);
        $this->assertFalse($crudo['principal']);
        $this->assertSame('ML-VIT-DEXTR', $crudo['codigo_proveedor']);
    }

    public function test_el_kardex_completa_un_articulo_sin_proveedor(): void
    {
        $proveedor = $this->proveedor('229', 'LABORATORIOS GAUDIUM S.R.L');
        $this->articulo(['codigo' => 'RE-4176']);
        Partida::create([
            'codigo_articulo' => 'RE-4176', 'codigo_partida' => 'L1', 'proveedor_numero' => '229',
        ]);

        $this->assertSame(1, VP::desdeKardex());

        $articulo = Articulo::first();
        $this->assertSame($proveedor->id, $articulo->proveedor_id);
        $this->assertSame(VP::ORIGEN_KARDEX, $articulo->proveedor_origen);
    }

    /** Elegir uno entre dos sería adivinar, y el error se arrastra al Dashboard. */
    public function test_el_kardex_no_vincula_un_articulo_con_dos_proveedores_historicos(): void
    {
        $this->proveedor('229', 'PROVEEDOR A');
        $this->proveedor('51', 'PROVEEDOR B');
        $this->articulo(['codigo' => 'RE-4176']);
        Partida::create(['codigo_articulo' => 'RE-4176', 'codigo_partida' => 'L1', 'proveedor_numero' => '229']);
        Partida::create(['codigo_articulo' => 'RE-4176', 'codigo_partida' => 'L2', 'proveedor_numero' => '51']);

        $this->assertSame(0, VP::desdeKardex());
        $this->assertNull(Articulo::first()->proveedor_id);
    }

    public function test_el_kardex_no_pisa_un_proveedor_del_excel(): void
    {
        $excel = $this->proveedor('99', 'EL DEL EXCEL');
        $this->proveedor('229', 'EL DEL KARDEX');
        $this->articulo([
            'codigo' => 'RE-4176',
            'proveedor_id' => $excel->id,
            'proveedor_origen' => VP::ORIGEN_EXCEL,
        ]);
        Partida::create(['codigo_articulo' => 'RE-4176', 'codigo_partida' => 'L1', 'proveedor_numero' => '229']);

        $this->assertSame(0, VP::desdeKardex());
        $this->assertSame($excel->id, Articulo::first()->proveedor_id);
    }

    /**
     * Los códigos de artículo son texto: `SBS23` convive con `730440`. Si el
     * `whereIn` los manda como enteros, MySQL castea la columna entera y
     * revienta — pasó de verdad la primera vez que corrió esto.
     */
    public function test_el_kardex_maneja_codigos_numericos_y_alfanumericos(): void
    {
        $proveedor = $this->proveedor('229', 'ZHEJIANG YOUREN');
        $this->articulo(['codigo' => 'SBS23']);
        $this->articulo(['codigo' => '730440']);
        Partida::create(['codigo_articulo' => 'SBS23', 'codigo_partida' => 'L1', 'proveedor_numero' => '229']);
        Partida::create(['codigo_articulo' => '730440', 'codigo_partida' => 'L2', 'proveedor_numero' => '229']);

        $this->assertSame(2, VP::desdeKardex());
        $this->assertSame($proveedor->id, Articulo::where('codigo', 'SBS23')->value('proveedor_id'));
    }

    // ---------- reporte de códigos inválidos ----------

    public function test_el_reporte_lista_los_codigos_que_no_vinculan(): void
    {
        $this->proveedor('1320', 'PROVEEDOR REAL');
        $this->articulo(['codigo' => 'RE-1', 'codigo_proveedor' => '941.825']);
        $this->articulo(['codigo' => 'RE-2', 'codigo_proveedor' => '941.825']);
        $this->articulo(['codigo' => 'RE-3', 'codigo_proveedor' => '1320']);
        // Un entero limpio que no existe en el padrón tampoco sirve.
        $this->articulo(['codigo' => 'RE-4', 'codigo_proveedor' => '999999']);

        $reporte = VP::codigosInvalidos();

        $this->assertEqualsCanonicalizing(
            ['941.825', '999999'],
            $reporte->pluck('codigo_proveedor')->all()
        );
        $this->assertSame(2, $reporte->firstWhere('codigo_proveedor', '941.825')->articulos);
        $this->assertSame(
            'Parece un costo, no un número de proveedor',
            $reporte->firstWhere('codigo_proveedor', '941.825')->motivo
        );
    }

    // ---------- edición manual desde el panel ----------

    public function test_editar_el_proveedor_a_mano_lo_marca_como_manual(): void
    {
        $proveedor = $this->proveedor('99', 'ELEGIDO A MANO');
        $articulo = $this->articulo(['proveedor_origen' => VP::ORIGEN_EXCEL]);

        $this->actingAs($this->userWith('articulos.edit'))
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => $proveedor->id])
            ->assertRedirect();

        $this->assertSame(VP::ORIGEN_MANUAL, $articulo->fresh()->proveedor_origen);
    }

    /**
     * Vaciar el proveedor desde la ficha también es una corrección manual (decisión del 14/10/2026): si
     * no, el principal del ERP lo volvía a poner esa misma noche.
     */
    public function test_vaciar_el_proveedor_a_mano_queda_fijo_aunque_el_erp_tenga_principal(): void
    {
        $proveedor = $this->proveedor('22', 'EL DEL ERP');
        $articulo = $this->articulo(['proveedor_id' => $proveedor->id, 'proveedor_origen' => VP::ORIGEN_PRINCIPAL]);
        $this->renglon('RE-1', '22', true);

        $this->actingAs($this->userWith('articulos.edit'))
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => null])
            ->assertRedirect();

        $this->assertSame(0, VP::desdeProveedorPrincipal());
        $this->assertNull($articulo->fresh()->proveedor_id);
        $this->assertSame(VP::ORIGEN_MANUAL, $articulo->fresh()->proveedor_origen);
    }

    /** Guardar la ficha sin tocar el proveedor no lo blinda contra el ERP. */
    public function test_guardar_sin_cambiar_el_proveedor_no_lo_marca_manual(): void
    {
        $proveedor = $this->proveedor('99', 'PROVEEDOR');
        $articulo = $this->articulo([
            'proveedor_id' => $proveedor->id,
            'proveedor_origen' => VP::ORIGEN_EXCEL,
        ]);

        $this->actingAs($this->userWith('articulos.edit'))
            ->put("/articulos/{$articulo->id}", ['proveedor_id' => $proveedor->id, 'pm' => 'PM 123'])
            ->assertRedirect();

        $this->assertSame(VP::ORIGEN_EXCEL, $articulo->fresh()->proveedor_origen);
    }

    // ---------- filtro del listado ----------

    public function test_el_listado_filtra_por_sin_proveedor(): void
    {
        $proveedor = $this->proveedor('99', 'PROVEEDOR');
        $this->articulo(['codigo' => 'CON', 'proveedor_id' => $proveedor->id]);
        $this->articulo(['codigo' => 'SIN']);

        $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos?proveedor=sin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('articulos.total', 1)
                ->where('articulos.data.0.codigo', 'SIN')
                ->where('totalSinProveedor', 1)
            );
    }

    // ---------- la garantía que pide el caso de uso ----------

    /**
     * El punto de todo el trabajo: una falla de producto ya cargada tiene que
     * mostrar el proveedor apenas el artículo se vincula, **sin tocar la
     * observación**. El proveedor se lee en vivo del padrón, no de una copia.
     */
    public function test_una_falla_de_producto_ya_cargada_muestra_el_proveedor_nuevo(): void
    {
        $proveedor = $this->proveedor('1320', 'INSUMOS SALUDABLES S.R.L.');
        $this->articulo(['codigo' => 'RE-4176', 'codigo_proveedor' => '1320']);

        $observacion = Observacion::create([
            'numero' => '0001-26', 'anio' => 2026, 'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente', 'contacto_email' => 'c@example.com',
            'titulo' => 'Falla', 'descripcion' => 'Descripción',
        ]);
        $observacion->productos()->create([
            'producto' => 'Recolector', 'codigo' => 'RE-4176',
            'cantidad_afectada' => 1, 'lote' => 'L1', 'fecha_vencimiento' => '2027-01-01',
        ]);

        $user = $this->userWith('observaciones.view');

        // Antes de vincular: el artículo existe pero no tiene proveedor.
        $this->actingAs($user)->get("/observaciones/{$observacion->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('observacion.productos.0.articulo.proveedor', null));

        // Llega el dato de RP y la sincronización lo vincula.
        VP::desdeCodigoProveedor();

        // La observación no se tocó y sin embargo ya muestra el proveedor.
        $this->actingAs($user)->get("/observaciones/{$observacion->id}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('observacion.productos.0.articulo.proveedor.razon_social', $proveedor->razon_social));
    }
}
