<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de compra pendientes de entrega (powerbi_ordenescompra_pend_vista).
 *
 * Se guardan los renglones crudos y no el agregado por artículo: son 21.492
 * filas (nada), y tener el proveedor y la fecha de entrega permite explicar de
 * dónde sale el número de "OC pend." de una fila del tablero.
 *
 * Refresh completo en cada sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_ordenes_pendientes', function (Blueprint $table) {
            $table->id();

            // OC = nacional, OCI = importado.
            $table->string('tipo', 5)->nullable()->index();
            $table->integer('numero')->nullable();
            $table->integer('item')->nullable();

            $table->integer('proveedor_numero')->nullable()->index();
            $table->string('razon_social')->nullable();

            $table->date('fecha')->nullable();
            $table->date('fecha_entrega')->nullable()->index();

            $table->string('articulo', 30)->index();
            $table->string('descrip_arti')->nullable();

            // Envases pendientes de entrega.
            $table->decimal('cant_pend', 16, 4)->default(0);

            // ⚠️ Inservible: 64% NULL y el resto es ruido ("1", "36,", "5/1",
            // "GRS"). Se guarda para poder auditarlo, pero el cálculo asume que
            // `cant_pend` viene en la misma unidad que el stock.
            $table->string('um_compra', 10)->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_ordenes_pendientes');
    }
};
