<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué lote salió en cada renglón de un comprobante de venta.
 *
 * Sale del mismo kardex que `partidas` (`COMPRO_PARTIDAS` del ERP), pero a otro
 * grano: `partidas` agrega a una fila por artículo+lote y descarta el
 * comprobante, así que no puede responder "a quién le despaché este lote".
 * Acá se conserva el comprobante.
 *
 * ⚠️ **El número de remito NO identifica un remito.** `NUM = 8500` devuelve
 * tres remitos distintos (`VR6/8500`, `VR8/8500`, `VRM/8500`), de facturas
 * diferentes: 7.395 de 25.320 números (29%) están repetidos entre series. Como
 * `ventas.remito_nro` es un entero pelado sin la serie, joinear por remito
 * daría el lote equivocado casi un tercio de las veces. **El join va por
 * `compro_nro`, que sí es único**, y el remito se guarda con su serie
 * (`remito_tipo` + `remito_numero`) solo para mostrarlo.
 *
 * `compro_nro` se guarda en el mismo formato que `ventas.compro_nro`
 * (`FEA-00036318`) para que el join sea por una sola columna. Se reconstruye
 * como `TIPO-%08d`: verificado, los 60.781 registros de `ventas` tienen
 * exactamente 8 dígitos después del último guion.
 *
 * Sin FK a `ventas` (se reemplaza entera en cada sync, los `id` no sobreviven)
 * ni a `articulos` / `partidas`: son datos de un sistema de terceros y el
 * refresh completo no debe poder fallar por integridad. Mismo criterio que el
 * resto de los espejos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_partidas', function (Blueprint $table) {
            $table->id();

            // Mismo formato que `ventas.compro_nro`: por acá se joinea.
            $table->string('compro_nro', 20)->index();
            $table->string('cod_comprobante', 10);
            $table->integer('numero');

            $table->string('codigo_articulo', 30)->index();
            $table->string('codigo_partida', 25)->index();

            // SUM(ABS(CANTI)): en los comprobantes de venta el kardex la trae
            // negativa porque es una salida de stock.
            $table->decimal('cantidad', 14, 4)->nullable();

            // El remito de origen, CON su serie. Sin la serie el número es
            // ambiguo — ver el comentario de arriba.
            $table->string('remito_tipo', 10)->nullable();
            $table->integer('remito_numero')->nullable()->index();

            $table->date('fecha')->nullable()->index();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['compro_nro', 'codigo_articulo', 'codigo_partida'], 'venta_partidas_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_partidas');
    }
};
