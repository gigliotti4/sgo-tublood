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
Schedule::command('observaciones:alertas')->hourly();
