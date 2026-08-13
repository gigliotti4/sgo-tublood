<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columna muerta en la práctica: ningún flujo de la UI la escribía en `true`
 * (ni CrearInterna.vue ni el modal de clasificación la tocan), solo se leía
 * en 3 lugares de solo lectura (Dashboard, badge de Show.vue, PDF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropColumn('tecnovigilancia');
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->boolean('tecnovigilancia')->default(false)->after('tipo_caso');
        });
    }
};
