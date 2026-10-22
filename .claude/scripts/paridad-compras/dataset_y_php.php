<?php

/*
 * Lado PHP de la comparación: arma el dataset real del tablero y corre CalculoReposicion::filtrar()
 * sobre cada escenario. Lo llama paridad.sh con Laravel ya arrancado; $argv[2] es la carpeta de salida.
 */

use App\Services\Compras\CalculoReposicion;
use App\Services\Compras\ReposicionService;

$out = $argv[2];
$d = app(ReposicionService::class)->construir();
file_put_contents("$out/dataset.json", json_encode($d));

$n = count($d['meses']);
$escenarios = json_decode(file_get_contents(__DIR__.'/escenarios.json'), true);
$resultado = [];

foreach ($escenarios as $e) {
    $desde = match ($e['periodo']) {
        'ultimos3' => $n - 3, 'ultimos6' => $n - 6, 'ultimos12' => $n - 12, default => 0,
    };
    $f = CalculoReposicion::filtros($e['f'] + ['desde' => max(0, $desde), 'hasta' => $n - 1]);
    $filas = (new CalculoReposicion)->filtrar($d['groups'], $f, $n);

    $resultado[$e['nombre']] = ['f' => $f, 'filas' => array_map(fn ($r) => [
        'id' => $r['grupo']['id'], 'stockU' => $r['stockU'], 'reservado' => $r['reservado'], 'ocPend' => $r['ocPend'],
        'stockTotal' => $r['stockTotal'], 'ventaPeriodo' => $r['ventaPeriodo'], 'promMensual' => $r['promMensual'],
        'meses' => is_infinite($r['meses']) ? 'INF' : $r['meses'], 'estado' => $r['estado'], 'cantComprar' => $r['cantComprar'],
        'importe' => $r['importe'], 'pareto' => $r['pareto'], 'anomalia' => $r['anomalia'], 'estimada' => $r['estimada'],
        'proximaEntrega' => $r['proximaEntrega'], 'proveedores' => $r['proveedores'], 'proveedor' => $r['proveedor'], 'items' => count($r['items']),
    ], $filas)];
}

file_put_contents("$out/php.json", json_encode($resultado));
echo 'PHP: dataset de '.count($d['groups']).' grupos, '.count($escenarios)." escenarios\n";
