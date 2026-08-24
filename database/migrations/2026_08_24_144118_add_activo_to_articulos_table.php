<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `default(true)` a propósito: hasta la primera corrida del sync que la llena
 * de verdad, las 4.200 filas existentes siguen viéndose como hoy. Ver
 * ArticuloSyncService para cómo se deriva de `synced_at` en cada corrida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->boolean('activo')->default(true)->after('synced_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
    }
};
