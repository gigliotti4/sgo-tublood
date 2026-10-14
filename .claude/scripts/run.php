<?php

use Illuminate\Contracts\Console\Kernel;

/*
 * Arranca Laravel (la app LOCAL) y corre el archivo PHP que se le pasa, con el resto de los argumentos
 * en $argv.
 *
 *   /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe .claude/scripts/run.php <script.php> [args...]
 *
 * Existe porque `artisan tinker` con un archivo se cuelga en este entorno y `tinker --execute` rompe las
 * comillas. Es el equivalente local de la envoltura de /prod-consulta, pero SIN red: lo que escriba el
 * script, queda escrito en la base local.
 */

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require $argv[1];
