<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tipo de producto que le pone ANMAT, según el ERP (`ARTICULOS.ID_ARTI_TIPO`).
 *
 * Entra junto con el cambio de fuente del catálogo: al pasar de la API HTTP a la
 * tabla SQL aparecen columnas que el endpoint no exponía, y ésta es la que más
 * importa en una empresa de dispositivos médicos — separa el producto médico del
 * de venta libre, del medicamento y del alimento.
 *
 * Se guarda el **código crudo del ERP** (`PM`, `PMV`, `ME`, `A`) y no un slug
 * nuestro: la etiqueta vive en `config/articulos.php`, así un código nuevo entra
 * sin migración. Mismo criterio que `proveedores.clasificacion_erp`.
 *
 * ⚠️ `pm` no se toca acá pero **cambia de dueño** en el mismo cambio: pasa a
 * llenarse desde `NRO_REGISTRO` y la sincronización la pisa en cada corrida.
 * Ver el contrato del upsert en ArticuloSyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->string('tipo_anmat', 3)->nullable()->after('pm')->index();
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropIndex(['tipo_anmat']);
            $table->dropColumn('tipo_anmat');
        });
    }
};
