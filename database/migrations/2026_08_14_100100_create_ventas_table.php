<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réplica local de `powerbi_ventas_vista` del ERP.
 *
 * Una fila por **renglón de comprobante**, no por comprobante: el mismo
 * `compro_nro` aparece tantas veces como artículos tenga.
 *
 * No hay clave única natural que el ERP garantice (`remito_nro` llega en 0 en
 * miles de filas, y un comprobante repite su número por cada artículo), así que
 * el sync hace un refresh completo en vez de un upsert — ver `VentaSyncService`.
 * Por eso tampoco hay índice único acá.
 *
 * Se guarda solo el subconjunto de columnas que se muestran o se filtran: la
 * vista trae 46, incluidos costos y precios de lista que no hacen falta para
 * consultar ventas desde el panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();

            $table->string('compro_nro', 20)->nullable()->index();
            $table->string('cod_comprobante', 10)->nullable();
            $table->string('grupo_compro_descrip')->nullable();
            $table->date('fecha')->nullable()->index();
            $table->integer('anio')->nullable()->index();

            $table->integer('cliente')->nullable()->index();
            $table->string('razon_social')->nullable();
            $table->string('nombre_fantasia')->nullable();
            $table->string('provincia', 50)->nullable();

            $table->string('articulo', 50)->nullable()->index();
            $table->string('descrip_arti')->nullable();
            $table->decimal('cantidad', 14, 4)->nullable();
            $table->decimal('precio_neto', 14, 4)->nullable();
            $table->decimal('sub_total', 14, 4)->nullable();

            // 0 significa "sin remito": el ERP no usa null acá.
            $table->integer('remito_nro')->nullable()->index();

            $table->string('vendedor')->nullable();
            $table->string('codi_vende', 10)->nullable();
            $table->string('deposito', 10)->nullable();
            $table->string('transportista')->nullable();
            $table->string('condi_venta')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // El listado ordena por fecha descendente.
            $table->index(['fecha', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
