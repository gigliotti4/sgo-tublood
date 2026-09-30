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

// Compras corre después de `ventas:sync` (04:00) para que el tablero abra con
// las ventas del día ya cargadas. Hasta el 29/9/2026 tenía un guard propio
// (`$comprasConfigurado`) porque leía por una segunda conexión con otras
// credenciales; hoy hay una sola y comparte el guard con el resto.
Schedule::command('compras:sync')->dailyAt('05:00')->when($erpConfigurado);

Schedule::command('observaciones:alertas')->hourly();

// Los dos recordatorios de plazos de una No Conformidad: marca vencidas las
// acciones pasadas de fecha y avisa cuando llega la fecha de verificar la
// eficacia. Diario y no horario como el de observaciones: los dos plazos se
// miden en días, así que correrlo cada hora repetiría el mismo trabajo 24 veces
// para encontrar lo mismo.
Schedule::command('nc:recordatorios')->dailyAt('07:00');

// ── Higiene de tablas que crecen solas ──────────────────────────────────────
//
// Ninguna de las tres se limpiaba sola. Medido en producción el 29/9/2026:
// `failed_jobs` con 311 filas acumuladas desde agosto y la tabla `cache` con
// 36,5 MB en 72 entradas, 66 de ellas ya vencidas.

// Una semana de retención: alcanza para investigar algo que pasó el fin de
// semana, y corta el crecimiento. El comando lo trae Laravel.
Schedule::command('queue:prune-failed --hours=168')->daily();

// ⚠️ El driver `database` de caché borra una entrada vencida **solo cuando
// alguien vuelve a leer esa clave**, así que las del tablero de Compras —que
// llevan un hash de los filtros y no se repiten— quedan para siempre. Ver
// `PodarCacheCommand`.
Schedule::command('cache:podar')->daily();

// El único de los tres que cambia algo: sin esto, una tarea puede fallar
// durante semanas sin que nadie se entere. Corre después de la poda para no
// contar lo que `queue:prune-failed` acaba de borrar.
Schedule::command('colas:revisar-fallos')->dailyAt('08:00');
