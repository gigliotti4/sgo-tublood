<?php

/*
 * Corre UNA consulta de lectura contra el ERP (conexión `erp`, SQL Server de RP Sistemas) y la imprime
 * como JSON. Se llama a través de run.php:
 *
 *   php .claude/scripts/run.php .claude/scripts/erp-sql.php consulta.sql [maxFilas=50]
 *
 * El usuario de RP ya es de solo lectura (0 permisos de escritura, medido el 30/9/2026), pero igual se
 * rechaza todo lo que no sea un SELECT/WITH: un `SELECT ... INTO` o un `EXEC` no deberían ni salir.
 */

use Illuminate\Support\Facades\DB;

$archivo = $argv[2] ?? exit("uso: run.php erp-sql.php <consulta.sql> [maxFilas]\n");
$max = (int) ($argv[3] ?? 50);
$sql = trim(file_get_contents($archivo));

// Sin comentarios ni literales, para que una palabra dentro de un string no dispare el control.
$limpio = preg_replace(["~--[^\n]*~", '~/\*.*?\*/~s', "~'(?:[^']|'')*'~"], ['', '', "''"], $sql);
$limpio = rtrim(trim($limpio), ';');

if (! preg_match('~^\s*(SELECT|WITH)\b~i', $limpio)) {
    fwrite(STDERR, "Rechazada: solo SELECT o WITH.\n");
    exit(2);
}
if (str_contains($limpio, ';') || preg_match('~\b(INSERT|UPDATE|DELETE|MERGE|EXEC|EXECUTE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|INTO|OPENROWSET|OPENQUERY|xp_\w+|sp_\w+)\b~i', $limpio, $m)) {
    fwrite(STDERR, 'Rechazada: '.(isset($m[1]) ? "contiene {$m[1]}" : 'más de una sentencia').".\n");
    exit(2);
}

$t = microtime(true);
try {
    $filas = DB::connection('erp')->select($sql);
} catch (Throwable $e) {
    // El handler de consola imprime 60 líneas y sale con 0: acá una línea y exit 1. Sin el SQL ni el host.
    $msg = $e->getPrevious()?->getMessage() ?? $e->getMessage();
    fwrite(STDERR, '[erp-sql] FALLÓ: '.preg_replace('~^.*\[SQL Server\]~', '', $msg).PHP_EOL);
    exit(1);
}
$seg = round(microtime(true) - $t, 2);

echo json_encode(array_slice($filas, 0, $max), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
fwrite(STDERR, '[erp-sql] '.count($filas)." filas en {$seg} s".(count($filas) > $max ? " (se muestran {$max})" : '').PHP_EOL);
