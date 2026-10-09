<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos datos nuevos para el tablero de Compras, pedidos por el sector el 8/10/2026.
 *
 * - `compras_articulos.venta_estimada`: la venta mensual que Compras carga a
 *   mano en el ERP para un producto nuevo o sin historia. Sale de
 *   `ARTICULOS.STOCK_SEGURIDAD`, que se usa con ese sentido. ⚠️ No de
 *   `STOCK_MIN`, que era lo que pedía el documento: ya estaba cargado en 7
 *   artículos (seis tubos de fabricación de 15.000 a 169.000) con otro
 *   significado, y tomarlo como venta mensual les cambiaba el cálculo.
 *   `STOCK_SEGURIDAD` estaba vacío en los 5.238.
 *
 * - `compras_stock_depositos`: el stock de cada artículo partido por depósito,
 *   desde `powerbi_stock_vista`, para poder elegir qué depósitos cuentan.
 *   Refresh completo, igual que el resto de las tablas del módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_articulos', function (Blueprint $table) {
            $table->decimal('venta_estimada', 16, 4)->nullable()->after('cant_stock');
        });

        Schema::create('compras_stock_depositos', function (Blueprint $table) {
            $table->id();
            // Misma colación binaria que `compras_articulos.codigo`: es la clave
            // del cruce, y `unicode_ci` colapsaría códigos que el ERP considera
            // distintos.
            $column = $table->string('articulo', 30);
            if (DB::connection()->getDriverName() === 'mysql') {
                $column->collation('utf8mb4_bin');
            }
            $column->index();
            $table->string('deposito', 10);
            $table->string('nombre')->nullable();
            $table->decimal('cant_stock', 16, 4)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_stock_depositos');

        Schema::table('compras_articulos', function (Blueprint $table) {
            $table->dropColumn('venta_estimada');
        });
    }
};
