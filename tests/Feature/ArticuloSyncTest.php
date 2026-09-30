<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Proveedor;
use App\Services\RpSistemas\ArticuloSyncService;
use App\Services\VinculacionProveedores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La sincronización del catálogo de artículos.
 *
 * ⚠️ **Hasta el 30/9/2026 esto entraba por HTTP** y los tests fakeaban
 * `articulos.php`. Desde que lee SQL Server no se puede levantar una base en los
 * tests, así que —igual que en `ProveedorSyncTest`— lo que se cubre es el
 * **mapeo** de la tabla a columnas locales, el **contrato del upsert** y la
 * lógica de desactivación, cada uno llamando a su método público.
 *
 * Los tres tests de mojibake que había acá se eliminaron a propósito: el doble
 * encoding era un defecto del endpoint HTTP. Medido el 30/9/2026, las
 * descripciones que devuelve el SQL coinciden con las que la API entregaba ya
 * reparadas en 746 de 748 artículos.
 */
class ArticuloSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Una fila como la devuelve la consulta, con los nombres de columna reales. */
    private function fila(array $attrs = []): array
    {
        return array_merge([
            'COD_ARTICULO' => 'RE-1631',
            'DESCRIP_ARTI' => 'AGUJA 40/12 TERUMO',
            'DESC_ADICIONAL' => 'Caja x 100',
            'COD_BARRAS' => '7791234567890',
            'UM' => 'UN',
            'AGRU_1' => 'DIS',
            'AGRU_2' => '1',
            'AGRU_3' => '2',
            'DESCRIP_AGRU_1' => 'DISTRIBUCIÓN',
            'DESCRIP_AGRU_2' => 'Libre venta',
            'DESCRIP_AGRU_3' => '1',
            'COD_PROVEEDOR' => 'PRO1',
            'NRO_REGISTRO' => 'PM 2243-98',
            'ID_ARTI_TIPO' => 'PM',
            'FECHA_MODI' => '2026-09-30 14:48:00',
            'CANT_STOCK' => 1250.5,
        ], $attrs);
    }

    private function service(): ArticuloSyncService
    {
        return new ArticuloSyncService;
    }

    private function mapear(array $attrs = []): array
    {
        return $this->service()->mapear(
            $this->fila($attrs),
            Carbon::parse('2026-09-30 15:00:00'),
            '2026-09-30 15:00:00.123456'
        );
    }

    // ── Mapeo de la tabla a columnas locales ────────────────────────────────

    public function test_mapea_las_columnas_de_la_tabla(): void
    {
        $fila = $this->mapear();

        $this->assertSame('RE-1631', $fila['codigo']);
        $this->assertSame('AGUJA 40/12 TERUMO', $fila['descripcion']);
        $this->assertSame('Caja x 100', $fila['descripcion_adicional']);
        $this->assertSame('7791234567890', $fila['codigo_barras']);
        $this->assertSame('UN', $fila['unidad_medida']);
        $this->assertSame('DIS', $fila['codigo_agrupacion_1']);
        $this->assertSame('DISTRIBUCIÓN', $fila['descripcion_agrupacion_1']);
        $this->assertSame('PRO1', $fila['codigo_proveedor']);
        $this->assertSame(1250.5, $fila['stock']);
        $this->assertSame('2026-09-30 14:48:00', $fila['modificado_en']);
        $this->assertTrue($fila['activo']);
    }

    /**
     * Las descripciones de agrupación no están en `ARTICULOS`: salen de joinear
     * la tabla `AGRUPACIONES` por `CODI_AGRU` + `NUM_AGRU`. Verificado el
     * 30/9/2026 contra los 734 artículos que tenían el dato por HTTP: coincide
     * en los 734, sin una sola diferencia.
     */
    public function test_mapea_las_tres_agrupaciones_con_su_descripcion(): void
    {
        $fila = $this->mapear();

        $this->assertSame('1', $fila['codigo_agrupacion_2']);
        $this->assertSame('Libre venta', $fila['descripcion_agrupacion_2']);
        $this->assertSame('2', $fila['codigo_agrupacion_3']);
        $this->assertSame('1', $fila['descripcion_agrupacion_3']);
    }

    /**
     * Los dos datos que motivaron el cambio de fuente: el endpoint HTTP no los
     * exponía y se cargaban a mano desde el Excel de Calidad.
     */
    public function test_trae_el_registro_de_anmat_y_el_tipo_de_producto(): void
    {
        $fila = $this->mapear();

        $this->assertSame('PM 2243-98', $fila['pm']);
        $this->assertSame('PM', $fila['tipo_anmat']);
    }

    /** El tipo se guarda crudo: la etiqueta vive en config/articulos.php. */
    public function test_el_tipo_se_guarda_sin_traducir(): void
    {
        $this->assertSame('PMV', $this->mapear(['ID_ARTI_TIPO' => 'PMV'])['tipo_anmat']);

        // Un código que RP agregue y todavía no esté en config entra igual.
        $this->assertSame('XX', $this->mapear(['ID_ARTI_TIPO' => 'XX'])['tipo_anmat']);
    }

    /** El ERP rellena los `char` con espacios y usa cadenas vacías en vez de null. */
    public function test_convierte_vacios_y_espacios_en_null(): void
    {
        $fila = $this->mapear([
            'DESC_ADICIONAL' => '',
            'COD_BARRAS' => '   ',
            'UM' => null,
            'NRO_REGISTRO' => '  ',
            'ID_ARTI_TIPO' => '',
        ]);

        $this->assertNull($fila['descripcion_adicional']);
        $this->assertNull($fila['codigo_barras']);
        $this->assertNull($fila['unidad_medida']);
        $this->assertNull($fila['pm']);
        $this->assertNull($fila['tipo_anmat']);
    }

    /**
     * ⚠️ El código se normaliza a mayúsculas y sin espacios. SQL Server ignora
     * los espacios a la derecha pero no los de la izquierda, y su collation
     * distingue mayúsculas: sin esto, `' re-1631'` entraría como un artículo
     * distinto y chocaría contra el índice único de MySQL.
     */
    public function test_normaliza_el_codigo(): void
    {
        $this->assertSame('RE-1631', $this->mapear(['COD_ARTICULO' => ' re-1631 '])['codigo']);
    }

    /**
     * ⚠️ `stock_disponible` se escribe siempre en `null`, no se arrastra.
     *
     * Medido el 30/9/2026 contra los diez depósitos de `powerbi_stock_vista`:
     * ninguno lo reproduce (el mejor llega a 487 de 748), así que es un cálculo
     * propio del endpoint HTTP que ya no usamos. Dejar el último valor de la API
     * congelaría un número viejo en el Excel, que es peor que un vacío.
     */
    public function test_el_stock_disponible_queda_en_null(): void
    {
        $this->assertNull($this->mapear()['stock_disponible']);
    }

    public function test_el_stock_sin_fila_en_la_vista_cuenta_como_cero(): void
    {
        // La consulta ya hace ISNULL(...,0): 11 de 748 artículos no tienen fila
        // en la vista de stock, que significa "sin existencias".
        $this->assertSame(0.0, $this->mapear(['CANT_STOCK' => 0])['stock']);
    }

    // ── Contrato del upsert ─────────────────────────────────────────────────

    /**
     * Las columnas que el upsert actualiza, copiadas de `ArticuloSyncService`.
     * Se repiten acá a propósito, igual que en `ProveedorSyncTest`: es lo que
     * convierte a este test en una alarma si alguien suma una columna propia
     * del panel a esa lista.
     */
    private const COLUMNAS_DEL_UPSERT = [
        'descripcion', 'descripcion_adicional', 'codigo_barras', 'unidad_medida',
        'codigo_agrupacion_1', 'descripcion_agrupacion_1',
        'codigo_agrupacion_2', 'descripcion_agrupacion_2',
        'codigo_agrupacion_3', 'descripcion_agrupacion_3',
        'stock', 'stock_disponible', 'codigo_proveedor',
        'tipo_anmat',
        'modificado_en', 'synced_at', 'activo', 'updated_at',
    ];

    /** `pm` se escribe aparte: el ERP completa pero nunca borra. */
    private function sincronizarFila(array $attrs = []): void
    {
        $fila = $this->mapear($attrs);

        Articulo::upsert([$fila], ['codigo'], self::COLUMNAS_DEL_UPSERT);

        if ($fila['pm'] !== null) {
            Articulo::upsert([$fila], ['codigo'], ['pm', 'updated_at']);
        }
    }

    /**
     * El contrato central: lo que carga una persona sobrevive a la sync.
     */
    public function test_el_upsert_no_pisa_los_campos_propios_del_panel(): void
    {
        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'Descripción vieja',
            'legajo' => 'LEGAJO 133',
            'observaciones' => 'Lo revisó Calidad',
            'link_registro' => 'https://ejemplo/registro',
            'fecha_vencimiento' => '2027-01-01',
        ]);

        Articulo::upsert([$this->mapear()], ['codigo'], self::COLUMNAS_DEL_UPSERT);

        $articulo = Articulo::where('codigo', 'RE-1631')->first();

        $this->assertSame('LEGAJO 133', $articulo->legajo);
        $this->assertSame('Lo revisó Calidad', $articulo->observaciones);
        $this->assertSame('https://ejemplo/registro', $articulo->link_registro);
        $this->assertSame('2027-01-01', $articulo->fecha_vencimiento->toDateString());
        // Y lo que sí es del ERP se actualiza.
        $this->assertSame('AGUJA 40/12 TERUMO', $articulo->descripcion);
    }

    /**
     * ⚠️ La contracara, y el cambio de dueño del 30/9/2026: `pm` **sí** se pisa.
     *
     * Antes era campo del panel y lo cargaba el Excel de Calidad; hoy sale de
     * `NRO_REGISTRO`. Por eso `ArticuloImportService` y `ArticuloController`
     * dejaron de escribirlo: si dos fuentes escribieran la misma columna, lo
     * cargado a mano se perdería en la corrida siguiente, en silencio.
     */
    /** Cuando el ERP trae un registro, ése manda. */
    public function test_el_registro_del_erp_pisa_el_que_estaba(): void
    {
        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'Descripción vieja',
            'pm' => 'PM VIEJO CARGADO A MANO',
        ]);

        $this->sincronizarFila();

        $this->assertSame('PM 2243-98', Articulo::where('codigo', 'RE-1631')->first()->pm);
    }

    /**
     * ⚠️ **La regresión que más caro sale de todas las de este archivo.**
     *
     * Medido el 30/9/2026 en producción: 324 artículos con `pm` cargado a mano,
     * y `NRO_REGISTRO` vacío para 35 de ellos. Si `pm` estuviera en la lista de
     * columnas del upsert general, esos 35 registros regulatorios se borrarían
     * en la primera corrida y nadie se enteraría hasta necesitarlos.
     */
    public function test_el_erp_sin_registro_no_borra_el_pm_que_habia(): void
    {
        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'Descripción vieja',
            'pm' => 'PM 2459-3',
        ]);

        $this->sincronizarFila(['NRO_REGISTRO' => '']);

        $this->assertSame('PM 2459-3', Articulo::where('codigo', 'RE-1631')->first()->pm);
    }

    /** Y un artículo sin nada cargado sigue sin nada, no queda en blanco raro. */
    public function test_sin_registro_en_ninguno_de_los_dos_lados_queda_null(): void
    {
        $this->sincronizarFila(['NRO_REGISTRO' => null]);

        $this->assertNull(Articulo::where('codigo', 'RE-1631')->first()->pm);
    }

    public function test_el_upsert_es_idempotente_por_codigo(): void
    {
        Articulo::upsert([$this->mapear()], ['codigo'], self::COLUMNAS_DEL_UPSERT);
        Articulo::upsert([$this->mapear(['DESCRIP_ARTI' => 'Descripción nueva'])], ['codigo'], self::COLUMNAS_DEL_UPSERT);

        $this->assertSame(1, Articulo::count());
        $this->assertSame('Descripción nueva', Articulo::first()->descripcion);
    }

    // ── Desactivación de lo que dejó de venir ───────────────────────────────

    /**
     * ⚠️ Se inserta con el query builder y no con `Articulo::create()`.
     *
     * El cast `datetime` del modelo formatea con `Y-m-d H:i:s` y **se come los
     * microsegundos**, así que un artículo sembrado con el timestamp exacto de
     * la corrida quedaba guardado un instante antes y la desactivación se lo
     * llevaba puesto. La sincronización real escribe el valor crudo por
     * `upsert()`, que es lo que esta siembra reproduce — y es también el motivo
     * de la migración que le puso precisión de microsegundos a la columna.
     */
    private function articuloSincronizadoEl(string $codigo, ?string $syncedAt, bool $activo = true): void
    {
        DB::table('articulos')->insert([
            'codigo' => $codigo,
            'descripcion' => "Artículo {$codigo}",
            'synced_at' => $syncedAt,
            'activo' => $activo,
            'created_at' => '2026-09-29 10:00:00',
            'updated_at' => '2026-09-29 10:00:00',
        ]);
    }

    public function test_desactiva_lo_que_no_vino_en_esta_corrida(): void
    {
        $this->articuloSincronizadoEl('RE-1631', '2026-09-30 15:00:00.123456');
        $this->articuloSincronizadoEl('RE-999', '2026-09-29 15:00:00.000000');

        $desactivados = $this->service()->desactivarLosQueNoVinieron('2026-09-30 15:00:00.123456', 2);

        $this->assertSame(1, $desactivados);
        $this->assertTrue(Articulo::where('codigo', 'RE-1631')->first()->activo);
        $this->assertFalse(Articulo::where('codigo', 'RE-999')->first()->activo);
    }

    /**
     * Los artículos que solo cargó el Excel de Calidad ("crear faltantes",
     * `synced_at` null) no vienen de ninguna sincronización, así que no pueden
     * "dejar de venir": tienen que seguir activos aunque RP no los mencione.
     */
    public function test_un_articulo_creado_solo_por_excel_sigue_activo(): void
    {
        $this->articuloSincronizadoEl('EXCEL-1', null);

        $this->service()->desactivarLosQueNoVinieron('2026-09-30 15:00:00.123456', 5);

        $this->assertTrue(Articulo::where('codigo', 'EXCEL-1')->first()->activo);
    }

    /**
     * ⚠️ Un resultado vacío no desactiva nada: un mal día del ERP no puede dejar
     * el selector de productos del portal público sin una sola sugerencia.
     */
    public function test_un_resultado_vacio_no_desactiva_nada(): void
    {
        $this->articuloSincronizadoEl('RE-999', '2026-09-29 15:00:00.000000');

        $desactivados = $this->service()->desactivarLosQueNoVinieron('2026-09-30 15:00:00.123456', 0);

        $this->assertSame(0, $desactivados);
        $this->assertTrue(Articulo::where('codigo', 'RE-999')->first()->activo);
    }

    /**
     * ⚠️ `where('activo', true)` no es solo optimización: sin eso, un artículo
     * ya desactivado vuelve a tocar `updated_at` en cada corrida y MySQL lo
     * cuenta como fila afectada, así que el contador nunca bajaría a 0.
     */
    public function test_no_vuelve_a_contar_lo_que_ya_estaba_desactivado(): void
    {
        $this->articuloSincronizadoEl('RE-999', '2026-09-29 15:00:00.000000', activo: false);

        $desactivados = $this->service()->desactivarLosQueNoVinieron('2026-09-30 15:00:00.123456', 3);

        $this->assertSame(0, $desactivados);
    }

    /** Un artículo desactivado que vuelve a aparecer se reactiva con el upsert. */
    public function test_un_articulo_desactivado_que_vuelve_se_reactiva(): void
    {
        $this->articuloSincronizadoEl('RE-1631', '2026-09-29 15:00:00.000000', activo: false);

        Articulo::upsert([$this->mapear()], ['codigo'], self::COLUMNAS_DEL_UPSERT);

        $this->assertTrue(Articulo::where('codigo', 'RE-1631')->first()->activo);
    }

    // ── Buscador ────────────────────────────────────────────────────────────

    public function test_el_buscador_encuentra_por_codigo_y_descripcion(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA ESTERIL']);

        $this->assertSame(['RE-1631'], Articulo::buscar('RE-1631')->pluck('codigo')->all());
        $this->assertSame(['RE-999'], Articulo::buscar('gasa')->pluck('codigo')->all());
        $this->assertSame([], Articulo::buscar('inexistente')->pluck('codigo')->all());
    }

    /** El buscador público (articulos.buscar) no puede ofrecer un artículo discontinuado. */
    public function test_el_buscador_publico_no_devuelve_articulos_inactivos(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO', 'activo' => true]);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'AGUJA DESCARTABLE', 'activo' => false]);

        $response = $this->getJson('/articulos/buscar?q=aguja');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['codigo' => 'RE-1631']);
        $response->assertJsonMissing(['codigo' => 'RE-999']);
    }

    // ── Vinculación de proveedor por codigo_proveedor ───────────────────────
    //
    // Es el paso final de `sync()`. Se lo llama directo porque el mapeo ya dejó
    // `codigo_proveedor` en su lugar y la regla de precedencia vive en
    // `VinculacionProveedores`, no en el servicio de sincronización.

    /**
     * codigo_proveedor casi nunca es un número de proveedor real (147 de 5.236
     * artículos en el ERP lo tienen cargado), pero cuando coincide con
     * proveedores.numero es información correcta y vale la pena vincularla.
     */
    public function test_vincula_el_proveedor_cuando_codigo_proveedor_coincide(): void
    {
        $proveedor = Proveedor::create(['numero' => 'PRO1', 'razon_social' => 'Distribuidora Test SA']);

        Articulo::upsert([$this->mapear()], ['codigo'], self::COLUMNAS_DEL_UPSERT);
        $vinculados = VinculacionProveedores::desdeCodigoProveedor();

        $this->assertSame($proveedor->id, Articulo::first()->proveedor_id);
        $this->assertSame(1, $vinculados);
    }

    /** Sin proveedor con ese número, proveedor_id queda en null sin romper nada. */
    public function test_no_vincula_proveedor_cuando_codigo_proveedor_no_matchea(): void
    {
        Articulo::upsert(
            [$this->mapear(['COD_PROVEEDOR' => 'CODIGO-QUE-NO-EXISTE'])],
            ['codigo'],
            self::COLUMNAS_DEL_UPSERT
        );
        $vinculados = VinculacionProveedores::desdeCodigoProveedor();

        $this->assertNull(Articulo::first()->proveedor_id);
        $this->assertSame(0, $vinculados);
    }

    /**
     * El caso central: un proveedor **corregido a mano** desde la ficha no se
     * pisa aunque codigo_proveedor matchee con OTRO proveedor.
     *
     * ⚠️ El dato del ERP **sí** corrige lo que adivinó el Excel por razón
     * social, pero nunca una corrección de una persona. Ver
     * `VinculacionProveedores::PRECEDENCIA` y el test de abajo.
     */
    public function test_no_pisa_un_proveedor_corregido_a_mano(): void
    {
        $asignadoAMano = Proveedor::create(['numero' => '500', 'razon_social' => 'El que cargó Calidad']);
        $otro = Proveedor::create(['numero' => 'PRO1', 'razon_social' => 'El que coincide con el ERP']);

        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'Descripción vieja',
            'proveedor_id' => $asignadoAMano->id,
            'proveedor_origen' => VinculacionProveedores::ORIGEN_MANUAL,
        ]);

        Articulo::upsert([$this->mapear()], ['codigo'], self::COLUMNAS_DEL_UPSERT);
        $vinculados = VinculacionProveedores::desdeCodigoProveedor();

        $this->assertSame($asignadoAMano->id, Articulo::first()->proveedor_id);
        $this->assertSame(0, $vinculados);
        $this->assertNotSame($otro->id, Articulo::first()->proveedor_id);
    }

    /**
     * La contracara: lo que el Excel adivinó por razón social **sí** lo corrige
     * el dato del ERP cuando RP lo carga.
     */
    public function test_pisa_un_proveedor_que_habia_puesto_el_excel(): void
    {
        $adivinado = Proveedor::create(['numero' => '500', 'razon_social' => 'El que adivinó el Excel']);
        $correcto = Proveedor::create(['numero' => 'PRO1', 'razon_social' => 'El que dice RP']);

        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'Descripción vieja',
            'proveedor_id' => $adivinado->id,
            'proveedor_origen' => VinculacionProveedores::ORIGEN_EXCEL,
        ]);

        Articulo::upsert([$this->mapear()], ['codigo'], self::COLUMNAS_DEL_UPSERT);
        $vinculados = VinculacionProveedores::desdeCodigoProveedor();

        $this->assertSame($correcto->id, Articulo::first()->proveedor_id);
        $this->assertSame(1, $vinculados);
    }
}
