<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('observation_products', function (Blueprint $table) {
            // Un reclamo telefónico muchas veces no trae el remito a mano;
            // el tipo de comprobante lo acompaña porque describe ese mismo documento.
            $table->string('numero_remito')->nullable()->change();
            $table->string('tipo_comprobante')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observation_products', function (Blueprint $table) {
            $table->string('numero_remito')->nullable(false)->change();
            $table->string('tipo_comprobante')->nullable(false)->change();
        });
    }
};
