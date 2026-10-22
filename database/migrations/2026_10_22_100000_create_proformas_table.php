<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Espejo de las facturas proforma del ERP (`powerbi_pedidos_vista` con
 * `cod_comprobante = 'DDD'`), una fila por comprobante, para el tablero de
 * urgencias (pedido de Tublood del 22/10/2026).
 *
 * Una fila por comprobante y no por renglón: vendedor, cliente y la observación
 * general son de la cabecera (medido: únicos en las 14.234 proformas), y lo que
 * se cuenta son proformas, no artículos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proformas', function (Blueprint $table) {
            $table->id();
            $table->string('compro_nro', 30)->unique();
            $table->date('fecha')->index();
            $table->string('cliente_numero', 20)->nullable();
            $table->string('razon_social')->nullable();
            $table->string('vendedor')->nullable();
            $table->string('codi_vende', 20)->nullable();
            // `obser_cabe`: la "Obser. Grales. del Pedido" donde se escribe "URGENTE".
            $table->text('observacion')->nullable();
            $table->string('estado', 60)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proformas');
    }
};
