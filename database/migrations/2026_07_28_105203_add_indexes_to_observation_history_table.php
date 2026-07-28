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
        Schema::table('observation_history', function (Blueprint $table) {
            // Es la única tabla del sistema que crece sin techo (una fila por
            // cada cambio de cada caso), y la pantalla de auditoría filtra por
            // acción y por rango de fechas ordenando por fecha sobre *todas*
            // las observaciones — los índices de las FK no sirven para eso.
            $table->index(['accion', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observation_history', function (Blueprint $table) {
            $table->dropIndex(['accion', 'created_at']);
            $table->dropIndex(['created_at']);
        });
    }
};
