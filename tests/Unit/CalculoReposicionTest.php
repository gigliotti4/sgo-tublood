<?php

namespace Tests\Unit;

use App\Services\Compras\CalculoReposicion;
use PHPUnit\Framework\TestCase;

/**
 * Fija cada regla de la copia en PHP del cálculo del tablero.
 *
 * `CalculoReposicion` replica `resources/js/lib/compras.ts` para que la alerta
 * mensual pueda correr en el servidor. Si una de estas reglas cambia en el
 * front, este test es lo que tiene que fallar primero: los casos están armados
 * para que cada regla dé un número distinto si se la saltea.
 */
class CalculoReposicionTest extends TestCase
{
    /** Tres meses de historial; el período por defecto es el último. */
    private const MESES = 3;

    private function item(array $attrs = []): array
    {
        return $attrs + ['c' => 'RE-1', 'd' => 'Producto', 'a' => 1, 'u' => 1, 'k' => 'DIS', 's' => 0, 'r' => 0, 'o' => 0];
    }

    private function grupo(array $items, string $id = 'a:RE-1', array $cats = ['DIS']): array
    {
        return ['id' => $id, 'n' => 'Producto', 'c' => $cats, 'u' => 1, 'i' => $items];
    }

    private function filtros(array $attrs = []): array
    {
        return CalculoReposicion::filtros($attrs + ['mesesObjetivo' => 1, 'desde' => 0, 'hasta' => self::MESES - 1]);
    }

    private function fila(array $grupo, array $filtros = []): array
    {
        return (new CalculoReposicion)->computeGroup($grupo, $this->filtros($filtros), self::MESES);
    }

    public function test_el_promedio_es_la_venta_del_periodo_sobre_los_meses_y_se_multiplica_por_el_envase(): void
    {
        $fila = $this->fila($this->grupo([$this->item(['u' => 10, 's' => 5, 'v' => [30, 30, 30]])]));

        $this->assertSame(300.0, $fila['promMensual'], '(30+30+30)/3 envases × 10 unidades');
        $this->assertSame(50.0, $fila['stockU']);
        $this->assertSame(250.0, $fila['cantComprar'], '300 × 1 mes − 50 de stock');
        $this->assertSame('no', $fila['estado']);
    }

    public function test_stock_total_es_stock_menos_reservado_mas_oc(): void
    {
        $fila = $this->fila($this->grupo([$this->item(['s' => 100, 'r' => 30, 'o' => 20, 'v' => [10, 10, 10]])]));

        $this->assertSame(90.0, $fila['stockTotal']);
        $this->assertSame(9.0, $fila['meses']);
        $this->assertSame('si', $fila['estado']);
    }

    public function test_el_stock_negativo_cuenta_cero_y_marca_la_anomalia(): void
    {
        $fila = $this->fila($this->grupo([$this->item(['s' => -500, 'v' => [10, 10, 10]])]));

        $this->assertSame(0.0, $fila['stockU']);
        $this->assertTrue($fila['anomalia']);
        $this->assertSame(10.0, $fila['cantComprar'], 'Sin el cero, -500 de stock pediría comprar 510');
    }

    /** Sin ventas no es un "NO cubre": es SIN VENTA. Son tres estados, no dos. */
    public function test_sin_venta_es_un_estado_propio(): void
    {
        $this->assertSame('sv', $this->fila($this->grupo([$this->item()]))['estado']);
    }

    public function test_la_venta_estimada_manda_sobre_el_promedio_y_saca_al_producto_de_sin_venta(): void
    {
        $nuevo = $this->fila($this->grupo([$this->item(['u' => 2, 've' => 100])]));
        $conHistoria = $this->fila($this->grupo([$this->item(['ve' => 100, 'v' => [10, 10, 10]])]));

        $this->assertSame(200.0, $nuevo['promMensual'], '100 envases × 2 unidades');
        $this->assertSame('no', $nuevo['estado'], 'Un producto nuevo sin ventas pero con estimación sí se puede tener que comprar');
        $this->assertTrue($nuevo['estimada']);
        $this->assertSame(100.0, $conHistoria['promMensual'], 'Manda la estimación aunque haya ventas');
    }

    /** La estimación es POR ARTÍCULO: en un grupo multimarca se suma con el promedio de los demás. */
    public function test_en_un_grupo_la_venta_estimada_se_suma_al_promedio_de_los_otros_articulos(): void
    {
        $fila = $this->fila($this->grupo([
            $this->item(['c' => 'RE-1', 've' => 50]),
            $this->item(['c' => 'RE-2', 'v' => [30, 30, 30]]),
        ], 'g:AGUJA'));

        $this->assertSame(80.0, $fila['promMensual']);
    }

    public function test_con_todos_los_depositos_el_stock_es_el_total_del_erp(): void
    {
        $it = $this->item(['s' => 80, 'sd' => ['DEP' => 100, 'DPR' => -20]]);

        $this->assertSame(80.0, (new CalculoReposicion)->stockDe($it, $this->filtros()));
        $this->assertSame(100.0, (new CalculoReposicion)->stockDe($it, $this->filtros(['depositos' => ['DEP']])));
        $this->assertSame(80.0, (new CalculoReposicion)->stockDe($it, $this->filtros(['depositos' => ['DEP', 'DPR']])));
        $this->assertSame(0.0, (new CalculoReposicion)->stockDe($it, $this->filtros(['depositos' => ['MUE']])));
    }

    public function test_la_proxima_entrega_es_la_fecha_mas_cercana_y_las_sin_fecha_van_al_final(): void
    {
        $fila = $this->fila($this->grupo([
            $this->item(['c' => 'RE-1', 'e' => [['2026-10-23', 10], [null, 5]]]),
            $this->item(['c' => 'RE-2', 'e' => [['2026-10-02', 7]]]),
        ], 'g:AGUJA'));

        $this->assertSame('2026-10-02', $fila['proximaEntrega']);
        $this->assertSame([null], array_slice(array_column($fila['entregas'], 'fecha'), -1));
    }

    public function test_un_grupo_con_proveedores_distintos_los_reporta_todos(): void
    {
        $fila = $this->fila($this->grupo([
            $this->item(['c' => 'RE-1', 'p' => 'B SA']),
            $this->item(['c' => 'RE-2', 'p' => 'A SA']),
            $this->item(['c' => 'RE-3', 'p' => 'A SA']),
        ], 'g:AGUJA'));

        $this->assertSame(['A SA', 'B SA'], $fila['proveedores']);
    }

    public function test_filtrar_aplica_categoria_activo_y_cubre(): void
    {
        $groups = [
            $this->grupo([$this->item(['c' => 'A', 'v' => [10, 10, 10]])], 'a:A'),
            $this->grupo([$this->item(['c' => 'B', 'k' => 'IMP', 'v' => [10, 10, 10]])], 'a:B', ['IMP']),
            $this->grupo([$this->item(['c' => 'C', 'a' => 0, 'v' => [10, 10, 10]])], 'a:C'),
            $this->grupo([$this->item(['c' => 'D', 's' => 999, 'v' => [10, 10, 10]])], 'a:D'),
            $this->grupo([$this->item(['c' => 'E', 'k' => 'SERVICIOS', 'v' => [10, 10, 10]])], 'a:E', ['SERVICIOS']),
        ];

        $filas = (new CalculoReposicion)->filtrar($groups, [
            'mesesObjetivo' => 1, 'desde' => 0, 'hasta' => 2,
            'cats' => ['DIS'], 'activo' => 'si', 'cubre' => 'no',
        ], self::MESES);

        $this->assertSame(['a:A'], array_column(array_column($filas, 'grupo'), 'id'));
    }

    public function test_sin_agrupar_cada_articulo_va_en_su_propia_fila(): void
    {
        $groups = [$this->grupo([
            $this->item(['c' => 'RE-1', 'v' => [10, 10, 10]]),
            $this->item(['c' => 'RE-2', 'v' => [10, 10, 10]]),
        ], 'g:AGUJA')];

        $calculo = new CalculoReposicion;

        $this->assertCount(1, $calculo->filtrar($groups, ['hasta' => 2], self::MESES));
        $this->assertSame(
            ['a:RE-1', 'a:RE-2'],
            array_column(array_column($calculo->filtrar($groups, ['hasta' => 2, 'agrupar' => false], self::MESES), 'grupo'), 'id'),
        );
    }

    /** El producto que CRUZA el 80% de la facturación va en A. */
    public function test_pareto_el_que_cruza_el_ochenta_por_ciento_va_en_a(): void
    {
        $groups = [
            $this->grupo([$this->item(['c' => 'A', 'v' => [1, 1, 1], 'm' => [0, 0, 70]])], 'a:A'),
            $this->grupo([$this->item(['c' => 'B', 'v' => [1, 1, 1], 'm' => [0, 0, 20]])], 'a:B'),
            $this->grupo([$this->item(['c' => 'C', 'v' => [1, 1, 1], 'm' => [0, 0, 10]])], 'a:C'),
        ];

        $filas = (new CalculoReposicion)->filtrar($groups, ['hasta' => 2], self::MESES);

        $this->assertSame(['A', 'A', 'B'], array_column($filas, 'pareto'));
    }
}
