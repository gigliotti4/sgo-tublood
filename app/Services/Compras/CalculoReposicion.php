<?php

namespace App\Services\Compras;

/**
 * El cálculo del tablero de reposición, en PHP.
 *
 * ⚠️ ES UNA COPIA de `resources/js/lib/compras.ts`, y existe por una sola
 * razón: la alerta mensual (`compras:alerta-cobertura`) se manda sola desde el
 * servidor y no puede correr el JS del navegador. El tablero sigue calculando
 * en el cliente; esto no lo reemplaza.
 *
 * Es exactamente la duplicación que el módulo evitaba (ver ReposicionService),
 * así que se acota a lo que la alerta necesita —filtros, cálculo por grupo y
 * Pareto; ni buscador ni orden— y se escribe calcando la versión TS, con los
 * mismos nombres, para que comparar las dos sea leer en paralelo. Cualquier
 * cambio en una fórmula de `compras.ts` va acá también; `CalculoReposicionTest`
 * fija cada regla, y al tocar algo conviene repetir la comparación sobre el
 * dataset real (ver CLAUDE.md, sección Compras).
 *
 * Trabaja sobre el dataset tal como lo arma `ReposicionService` (claves de una
 * letra).
 */
class CalculoReposicion
{
    /**
     * Filtros con la misma forma que `FiltrosCompras` del front.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function filtros(array $f): array
    {
        return $f + [
            'mesesObjetivo' => 1,
            'desde' => 0,
            'hasta' => 0,
            'cats' => [],
            'activo' => 'all',
            'stock' => 'all',
            'cubre' => 'all',
            'pareto' => 'all',
            'servicios' => false,
            'depositos' => [],
            // Mismo default que `filtrosPorDefecto()` del front: sin agrupar.
            'agrupar' => false,
        ];
    }

    /**
     * Las filas que pasan los filtros, con el Pareto clasificado.
     *
     * Espejo de `filtrar()` sin el buscador.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    public function filtrar(array $groups, array $f, int $totalMeses): array
    {
        $f = self::filtros($f);
        $cats = array_flip($f['cats']);
        $filas = [];

        foreach ($f['agrupar'] ? $groups : $this->desagrupar($groups) as $g) {
            // Un grupo se excluye solo si TODOS sus artículos no mueven stock.
            if (! $f['servicios'] && $this->todas($g['c'], fn ($c) => $c === 'SERVICIOS')) {
                continue;
            }

            // La categoría es POR ARTÍCULO: alcanza con que una esté elegida.
            if ($cats && ! $this->alguna($g['c'], fn ($c) => isset($cats[$c]))) {
                continue;
            }

            $fila = $this->computeGroup($g, $f, $totalMeses);

            if ($fila === null || ($f['cubre'] !== 'all' && $fila['estado'] !== $f['cubre'])) {
                continue;
            }

            $filas[] = $fila;
        }

        $this->clasificarPareto($filas);

        return $f['pareto'] === 'all'
            ? $filas
            : array_values(array_filter($filas, fn ($r) => $r['pareto'] === $f['pareto']));
    }

    /**
     * Las columnas calculadas de un grupo, o null si ningún artículo pasa.
     *
     * @param  array<string, mixed>  $g
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>|null
     */
    public function computeGroup(array $g, array $f, int $totalMeses): ?array
    {
        $items = array_values(array_filter($g['i'], fn ($it) => $this->itemPasa($it, $f)));

        if ($items === []) {
            return null;
        }

        $ventas = array_fill(0, $totalMeses, 0.0);
        $stockEnv = $stockU = $reservado = $ocPend = $importe = $promMensual = 0.0;
        $anomalia = $estimada = false;
        $proveedores = [];
        $entregas = [];
        $nMeses = $f['hasta'] - $f['desde'] + 1;

        foreach ($items as $it) {
            $u = $this->envaseDe($it);

            // Stock negativo = error de carga del ERP: cuenta 0 y se marca.
            $crudo = $this->stockDe($it, $f);
            if ($crudo < 0) {
                $anomalia = true;
            }
            $st = max(0.0, $crudo);

            $stockEnv += $st;
            $stockU += $st * $u;
            $reservado += $it['r'] * $u;
            $ocPend += $it['o'] * $u;

            $ventaItem = 0.0;
            if (isset($it['v'])) {
                for ($i = 0; $i < $totalMeses; $i++) {
                    $ventas[$i] += $it['v'][$i] * $u;
                }
                for ($i = $f['desde']; $i <= $f['hasta']; $i++) {
                    $ventaItem += $it['v'][$i] * $u;
                }
            }

            // Las notas de crédito ya vienen en negativo: se suman tal cual.
            if (isset($it['m'])) {
                for ($i = $f['desde']; $i <= $f['hasta']; $i++) {
                    $importe += $it['m'][$i];
                }
            }

            // La venta cargada a mano manda sobre el promedio, por artículo.
            if (($it['ve'] ?? 0) > 0) {
                $promMensual += $it['ve'] * $u;
                $estimada = true;
            } else {
                $promMensual += $ventaItem / $nMeses;
            }

            if (! empty($it['p'])) {
                $proveedores[$it['p']] = true;
            }

            foreach ($it['e'] ?? [] as [$fecha, $cant]) {
                $entregas[] = ['fecha' => $fecha, 'cantidad' => $cant * $u, 'codigo' => $it['c']];
            }
        }

        $ventaPeriodo = 0.0;
        for ($i = $f['desde']; $i <= $f['hasta']; $i++) {
            $ventaPeriodo += $ventas[$i];
        }

        usort($entregas, fn ($a, $b) => [$a['fecha'] === null, $a['fecha']] <=> [$b['fecha'] === null, $b['fecha']]);

        $stockTotal = $stockU - $reservado + $ocPend;

        // Sin disponible la cobertura es 0: no existen los "meses negativos".
        $meses = $stockTotal <= 0 ? 0.0 : ($promMensual > 0 ? $stockTotal / $promMensual : INF);
        $cubre = $meses >= $f['mesesObjetivo'];

        $proveedores = array_keys($proveedores);
        sort($proveedores);

        return [
            'grupo' => $g,
            'items' => $items,
            'stockU' => $stockU,
            'stockEnv' => $stockEnv,
            'reservado' => $reservado,
            'ocPend' => $ocPend,
            'stockTotal' => $stockTotal,
            'ventaPeriodo' => $ventaPeriodo,
            'promMensual' => $promMensual,
            'meses' => $meses,
            'cubre' => $cubre,
            'cantComprar' => max(0.0, $promMensual * $f['mesesObjetivo'] - $stockTotal),
            // TRES estados: un producto sin demanda no es un "NO cubre".
            'estado' => $promMensual <= 0 ? 'sv' : ($cubre ? 'si' : 'no'),
            'importe' => $importe,
            'anomalia' => $anomalia,
            'estimada' => $estimada,
            'proveedores' => $proveedores,
            'entregas' => $entregas,
            'proximaEntrega' => $entregas[0]['fecha'] ?? null,
            'pareto' => null,
        ];
    }

    /**
     * ABC sobre la facturación del conjunto ya filtrado. El que CRUZA el 80% va en A.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    public function clasificarPareto(array &$filas): void
    {
        $conFact = array_keys(array_filter($filas, fn ($r) => $r['importe'] > 0));
        usort($conFact, fn ($a, $b) => $filas[$b]['importe'] <=> $filas[$a]['importe']);

        $total = array_sum(array_map(fn ($k) => $filas[$k]['importe'], $conFact));
        $acumulado = 0.0;

        foreach ($conFact as $k) {
            $previo = $acumulado;
            $acumulado += $filas[$k]['importe'];
            $filas[$k]['pareto'] = $previo < $total * 0.8 ? 'A' : 'B';
        }
    }

    /**
     * Stock en envases, crudo. Con "todos" (lista vacía) es `CANT_STOCK`; con
     * depósitos elegidos, la suma de esos. Ver `stockDe()` en compras.ts.
     *
     * @param  array<string, mixed>  $it
     * @param  array<string, mixed>  $f
     */
    public function stockDe(array $it, array $f): float
    {
        if ($f['depositos'] === []) {
            return (float) $it['s'];
        }

        $total = 0.0;
        foreach ($f['depositos'] as $d) {
            $total += $it['sd'][$d] ?? 0;
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $it
     * @param  array<string, mixed>  $f
     */
    private function itemPasa(array $it, array $f): bool
    {
        if ($f['activo'] === 'si' && ! $it['a']) {
            return false;
        }
        if ($f['activo'] === 'no' && $it['a']) {
            return false;
        }

        $conStock = $this->stockDe($it, $f) > 0;

        if ($f['stock'] === 'si' && ! $conStock) {
            return false;
        }
        if ($f['stock'] === 'no' && $conStock) {
            return false;
        }

        return true;
    }

    /** @param  array<string, mixed>  $it */
    private function envaseDe(array $it): int
    {
        return ($it['u'] ?? 0) > 0 ? (int) $it['u'] : 1;
    }

    /**
     * Cada artículo como su propio grupo. Espejo de `desagrupar()`.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function desagrupar(array $groups): array
    {
        $salida = [];

        foreach ($groups as $g) {
            if (count($g['i']) === 1 && str_starts_with($g['id'], 'a:')) {
                $salida[] = $g;

                continue;
            }

            foreach ($g['i'] as $it) {
                $salida[] = ['id' => 'a:'.$it['c'], 'n' => $it['d'] ?: $it['c'], 'c' => [$it['k']], 'u' => $it['u'], 'i' => [$it]];
            }
        }

        return $salida;
    }

    /** @param  list<string>  $lista */
    private function todas(array $lista, callable $condicion): bool
    {
        foreach ($lista as $x) {
            if (! $condicion($x)) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $lista */
    private function alguna(array $lista, callable $condicion): bool
    {
        foreach ($lista as $x) {
            if ($condicion($x)) {
                return true;
            }
        }

        return false;
    }
}
