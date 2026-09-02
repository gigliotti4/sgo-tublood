<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Padrón local de partidas (lotes), derivado de `COMPRO_PARTIDAS` del ERP.
 *
 * Existe para **rastrear el lote de un reclamo**: el cliente reporta una falla
 * con un lote y desde acá se resuelve de qué artículo salió, de qué proveedor
 * y cuándo vence, sin ir al ERP en vivo — mismo criterio que el resto de los
 * espejos (`clientes`, `articulos`, `proveedores`, `ventas`).
 *
 * ⚠️ **Una fila por partida, no por movimiento.** `COMPRO_PARTIDAS` es un
 * kardex de 390.000 movimientos; acá quedan las ~12.800 partidas que resultan
 * de agruparlo. La agregación la hace el ERP (ver `PartidaSyncService`), no
 * nosotros: traer el kardex entero por la red tarda minutos.
 *
 * ⚠️ **La clave es (artículo, partida), no la partida sola**: 885 códigos de
 * lote se repiten entre artículos distintos, así que un lote suelto es
 * ambiguo. Igual se indexa `codigo_partida` por su cuenta, porque un reclamo
 * puede traer el lote sin un código de artículo que matchee el catálogo.
 *
 * ⚠️ **No se guarda ningún saldo de stock.** `SUM(CANTI)` del kardex da
 * negativo en 8.041 de las 12.782 partidas y positivo en 468: la tabla
 * registra consumos contra la partida pero no todos los ingresos, así que ese
 * número no es una existencia y mostrarlo mentiría.
 *
 * Tampoco hay FK a `articulos` ni a `proveedores`: son datos de un sistema de
 * terceros que puede nombrar un artículo que todavía no sincronizamos, y el
 * refresh completo de esta tabla no debe poder fallar por integridad. Mismo
 * criterio que `ObservationProduct::articulo()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partidas', function (Blueprint $table) {
            $table->id();

            $table->string('codigo_articulo', 30);
            $table->string('codigo_partida', 25);

            $table->date('fecha_vencimiento')->nullable()->index();

            // Número del proveedor en el ERP; matchea `proveedores.numero`
            // (verificado: los 97 valores distintos existen en el padrón).
            // Se guarda el número y no un `proveedor_id` para que el sync no
            // dependa del orden en que corran los dos padrones.
            $table->string('proveedor_numero', 20)->nullable()->index();

            $table->string('ubicacion', 50)->nullable();

            // Fecha del último movimiento del kardex: dice si la partida sigue
            // viva o es histórica.
            $table->date('ultimo_movimiento_at')->nullable()->index();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['codigo_articulo', 'codigo_partida']);
            $table->index('codigo_partida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partidas');
    }
};
