<?php

namespace Tests\Feature;

use App\Services\RpSistemas\ComprasArticuloSyncService;
use App\Services\RpSistemas\OrdenCompraSyncService;
use App\Services\RpSistemas\PedidoPendienteSyncService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * No se puede levantar un SQL Server en los tests (corren contra SQLite en
 * memoria), así que lo que se cubre acá es el **mapeo** de cada vista a las
 * columnas locales, que es donde se rompe todo cuando el ERP cambia algo.
 *
 * Los nombres de columna son los reales del ERP, con su caja original: la vista
 * de OC viene toda en MAYÚSCULAS y la de pedidos mezcla `CLIENTE` con
 * `articulo`. Ese detalle ya rompió otros syncs del proyecto.
 */
class ComprasSyncTest extends TestCase
{
    private Carbon $syncedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncedAt = Carbon::parse('2026-09-10 05:00:00');
    }

    // ── Catálogo (ARTICULOS) ────────────────────────────────────────────────

    private function filaArticulo(array $attrs = []): array
    {
        return array_merge([
            'COD_ARTICULO' => 'RE-1573',
            'DESCRIP_ARTI' => 'AGUJA 25/6',
            'CANT_STOCK' => '8742.0000',
            'AGRU_1' => 'DIS',
            'GTIN' => 'AGUJA 25/6 (23GX1)',
            'SIN_STOCK' => 'N',
            'ACTIVO' => 'S',
            'CODIGO_REFERENCIA' => '500',
        ], $attrs);
    }

    public function test_mapea_el_catalogo(): void
    {
        $fila = (new ComprasArticuloSyncService)->mapear($this->filaArticulo(), $this->syncedAt);

        $this->assertSame('RE-1573', $fila['codigo']);
        $this->assertSame('AGUJA 25/6', $fila['descripcion']);
        $this->assertSame(8742.0, $fila['cant_stock']);
        $this->assertSame('DIS', $fila['agru_1']);
        $this->assertSame('AGUJA 25/6 (23GX1)', $fila['gtin']);
        $this->assertFalse($fila['sin_stock']);
        $this->assertTrue($fila['activo']);
        $this->assertSame(500, $fila['unidades_por_envase']);
    }

    /** El ERP rellena los `char` con espacios y usa cadenas vacías en vez de null. */
    public function test_el_codigo_se_normaliza_a_mayuscula_sin_espacios(): void
    {
        $fila = (new ComprasArticuloSyncService)->mapear(
            $this->filaArticulo(['COD_ARTICULO' => '  re-1573 ']),
            $this->syncedAt,
        );

        $this->assertSame('RE-1573', $fila['codigo']);
    }

    public function test_las_banderas_del_erp_son_s_o_n_con_espacios(): void
    {
        $servicio = new ComprasArticuloSyncService;

        $fila = $servicio->mapear($this->filaArticulo(['SIN_STOCK' => 'S ', 'ACTIVO' => 'N ']), $this->syncedAt);
        $this->assertTrue($fila['sin_stock']);
        $this->assertFalse($fila['activo']);

        $fila = $servicio->mapear($this->filaArticulo(['SIN_STOCK' => null, 'ACTIVO' => null]), $this->syncedAt);
        $this->assertFalse($fila['sin_stock']);
        $this->assertFalse($fila['activo']);
    }

    /**
     * Vacío, 0, 1 y basura significan todos "se cuenta de a uno". Se guarda null
     * para que la pantalla muestre "–" en vez de un "1" que no aporta nada.
     */
    public function test_el_envase_es_null_cuando_no_multiplica(): void
    {
        $servicio = new ComprasArticuloSyncService;

        foreach (['', '   ', '0', '1', 'N/A', null] as $valor) {
            $fila = $servicio->mapear($this->filaArticulo(['CODIGO_REFERENCIA' => $valor]), $this->syncedAt);
            $this->assertNull($fila['unidades_por_envase'], sprintf('CODIGO_REFERENCIA=%s', var_export($valor, true)));
        }
    }

    /** El GTIN se guarda crudo: los comodines los filtra el dataset, no la sync. */
    public function test_el_gtin_comodin_se_guarda_tal_cual(): void
    {
        $fila = (new ComprasArticuloSyncService)->mapear(
            $this->filaArticulo(['GTIN' => 'NO APLICA']),
            $this->syncedAt,
        );

        $this->assertSame('NO APLICA', $fila['gtin']);
    }

    public function test_el_stock_negativo_del_erp_se_guarda_negativo(): void
    {
        $fila = (new ComprasArticuloSyncService)->mapear(
            $this->filaArticulo(['CANT_STOCK' => '-4296450.0000']),
            $this->syncedAt,
        );

        $this->assertSame(-4296450.0, $fila['cant_stock']);
    }

    // ── OC pendientes ───────────────────────────────────────────────────────

    public function test_mapea_una_orden_de_compra_pendiente(): void
    {
        $fila = (new OrdenCompraSyncService)->mapear([
            'TIPO' => 'OCI',
            'NUM' => 1234,
            'ITEM' => 2,
            'PROVE' => 87,
            'RAZON' => 'PROPATO HNOS. S.A.I.C.',
            'FECHA' => '2026-08-01 00:00:00.000',
            'FECHA_ENTRE' => '2026-10-15 00:00:00.000',
            'ARTICULO' => ' re-1573 ',
            'DESCRIP_ARTI' => 'AGUJA 25/6',
            'CANT_PEND' => '1500.0000',
            'UM_COMPRA' => 'UNI',
        ], $this->syncedAt);

        $this->assertSame('OCI', $fila['tipo']);
        $this->assertSame(1234, $fila['numero']);
        $this->assertSame(87, $fila['proveedor_numero']);
        $this->assertSame('2026-08-01', $fila['fecha']);
        $this->assertSame('2026-10-15', $fila['fecha_entrega']);
        $this->assertSame('RE-1573', $fila['articulo'], 'El código es la clave del cruce: se normaliza');
        $this->assertSame(1500.0, $fila['cant_pend']);
    }

    /** `UM_COMPRA` viene con basura en el 64% de las filas. Se guarda igual, pero no se usa. */
    public function test_la_unidad_de_compra_se_guarda_aunque_sea_basura(): void
    {
        $servicio = new OrdenCompraSyncService;

        $this->assertSame('36,', $servicio->mapear(['ARTICULO' => 'X', 'UM_COMPRA' => '36,'], $this->syncedAt)['um_compra']);
        $this->assertNull($servicio->mapear(['ARTICULO' => 'X', 'UM_COMPRA' => null], $this->syncedAt)['um_compra']);
    }

    // ── Pedidos pendientes ──────────────────────────────────────────────────

    public function test_mapea_un_renglon_de_pedido(): void
    {
        $fila = (new PedidoPendienteSyncService)->mapear([
            'comprobante' => 'PEDIDO',
            'compro_nro' => 55010,
            'renglon' => 3,
            'fecha' => '2026-09-01 00:00:00.000',
            'CLIENTE' => 4021,
            'razon_social' => 'HOSPITAL ITALIANO',
            'articulo' => ' re-1573 ',
            'cant_pend' => '250.0000',
            'reser' => 'S',
            'descrip_depo_reserva' => 'Deposito unico',
            'estado' => 'PENDIENTE',
        ], $this->syncedAt);

        $this->assertSame(55010, $fila['compro_nro']);
        $this->assertSame(4021, $fila['cliente'], 'CLIENTE viene en mayúscula en esta vista');
        $this->assertSame('RE-1573', $fila['articulo']);
        $this->assertSame(250.0, $fila['cant_pend']);
        $this->assertSame('S', $fila['reser']);
        $this->assertSame('Deposito unico', $fila['deposito_reserva']);
        $this->assertSame('PENDIENTE', $fila['estado']);
    }

    public function test_los_depositos_que_no_comprometen_stock_se_guardan_igual(): void
    {
        // Se guardan para poder auditar la regla, que no está conciliada con el
        // ERP: filtrarlos en la sync la dejaría congelada.
        foreach (['CUARENTENA', 'PROGRAMADAS'] as $deposito) {
            $fila = (new PedidoPendienteSyncService)->mapear([
                'articulo' => 'RE-1',
                'reser' => 'S',
                'descrip_depo_reserva' => $deposito,
            ], $this->syncedAt);

            $this->assertSame($deposito, $fila['deposito_reserva']);
        }
    }
}
