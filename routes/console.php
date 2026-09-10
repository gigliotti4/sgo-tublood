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
// Proveedores y ventas no vienen por la API: se leen de las vistas SQL que RP
// Sistemas habilita para Power BI. El padrón de proveedores cambia poco; las
// ventas se reemplazan enteras, así que van de noche y después de artículos.
//
// Las dos quedan apagadas donde no haya conexión al ERP configurada: sin el
// driver `pdo_sqlsrv` (hoy: hosting compartido y cualquier máquina sin la
// extensión) fallarían en cada corrida y llenarían el log de errores.
$erpConfigurado = fn () => filled(config('database.connections.erp.host'));

Schedule::command('proveedores:sync')->hourly()->when($erpConfigurado);
Schedule::command('ventas:sync')->dailyAt('04:00')->when($erpConfigurado);
// Los lotes despachados salen del mismo kardex que las partidas, pero conservan
// el comprobante. Va después de ventas porque se consulta joineado con ellas.
Schedule::command('venta-partidas:sync')->dailyAt('04:15')->when($erpConfigurado);
// Las partidas salen de agregar el kardex de COMPRO_PARTIDAS: cambia cada vez
// que se mueve stock, pero sirven para rastrear el lote de un reclamo, que se
// carga con horas o días de diferencia. Una vez por día alcanza.
Schedule::command('partidas:sync')->dailyAt('04:30')->when($erpConfigurado);

// Compras lee por un segundo login SQL (`erp_compras`), con otros permisos:
// tiene el catálogo maestro y las vistas de compras, pero NO el kardex. Por eso
// el guard mira su propio host y no el de `erp` — una puede estar configurada y
// la otra no. Corre después de `ventas:sync` (04:00) para que el tablero abra
// con las ventas del día ya cargadas.
$comprasConfigurado = fn () => filled(config('database.connections.erp_compras.host'));

Schedule::command('compras:sync')->dailyAt('05:00')->when($comprasConfigurado);

Schedule::command('observaciones:alertas')->hourly();
