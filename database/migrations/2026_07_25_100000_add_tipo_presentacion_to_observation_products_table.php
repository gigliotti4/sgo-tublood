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
            // Nullable: las filas cargadas antes de este campo no lo tienen.
            $table->string('tipo_presentacion')->nullable()->after('cantidad_afectada');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observation_products', function (Blueprint $table) {
            $table->dropColumn('tipo_presentacion');
        });
    }
};
