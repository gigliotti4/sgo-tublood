<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campo propio del panel (como fecha_vencimiento/pm/legajo/observaciones): el
 * ERP no lo tiene, no lo pisa ArticuloSyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->string('link_registro')->nullable()->after('observaciones');
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn('link_registro');
        });
    }
};
