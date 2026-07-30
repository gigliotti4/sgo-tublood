<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de artículos espejado de RP Sistemas (articulos.php).
 *
 * Sirve para que el producto de una observación se elija de una lista en vez
 * de tipearse a mano, que es de donde salen los códigos que después no matchean.
 *
 * No se guardan precios a propósito: el endpoint obliga a mandar una lista de
 * precios para valorizar, pero para gestionar reclamos de calidad el precio no
 * aporta y quedaría desactualizado entre sincronizaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articulos', function (Blueprint $table) {
            $table->id();

            // Clave del upsert: el código del artículo en RP.
            $table->string('codigo')->unique();
            $table->string('descripcion');
            $table->string('descripcion_adicional')->nullable();
            $table->string('codigo_barras')->nullable()->index();
            $table->string('unidad_medida', 20)->nullable();

            // Agrupaciones del ERP (hasta 3 niveles), con su código y su texto.
            $table->string('codigo_agrupacion_1', 30)->nullable()->index();
            $table->string('descripcion_agrupacion_1')->nullable();
            $table->string('codigo_agrupacion_2', 30)->nullable();
            $table->string('descripcion_agrupacion_2')->nullable();
            $table->string('codigo_agrupacion_3', 30)->nullable();
            $table->string('descripcion_agrupacion_3')->nullable();

            $table->decimal('stock', 14, 4)->nullable();
            $table->decimal('stock_disponible', 14, 4)->nullable();
            $table->string('codigo_proveedor', 30)->nullable();

            // fecha_modi del ERP: sirve para saber qué cambió sin comparar todo.
            $table->dateTime('modificado_en')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // El buscador del selector filtra por descripción.
            $table->index('descripcion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articulos');
    }
};
