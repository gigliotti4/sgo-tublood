<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('clientes:sync')->everyFiveMinutes();
// El catálogo cambia mucho menos que los clientes y la respuesta trae las
// ~4000 filas de una, así que una vez por día alcanza y no castiga al ERP.
Schedule::command('articulos:sync')->dailyAt('03:00');
// El padrón de proveedores no viene por la API: se lee de la vista SQL que RP
// Sistemas habilita para Power BI. Cambia poco, así que una vez por hora sobra.
//
// Queda apagado donde no haya conexión al ERP configurada: sin el driver
// `pdo_sqlsrv` (hoy: hosting compartido y cualquier máquina sin la extensión)
// fallaría en cada corrida y llenaría el log de errores.
$erpConfigurado = fn () => filled(config('database.connections.erp.host'));

Schedule::command('proveedores:sync')->hourly()->when($erpConfigurado);

Schedule::command('observaciones:alertas')->hourly();
