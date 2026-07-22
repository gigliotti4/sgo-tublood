<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reloj de gestión: arranca cuando se asigna un responsable (no cuando se crea
 * la observación) y vence a los N días hábiles del área de ese responsable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->timestamp('responsable_asignado_at')->nullable()->after('responsable_id');
            $table->timestamp('vence_at')->nullable()->after('responsable_asignado_at');
            // 0 = sin alerta · 1 = avisado responsable+supervisor · 2 = escalado al gerente.
            // Hace idempotente al comando de alertas.
            $table->unsignedTinyInteger('alerta_nivel')->default(0)->after('vence_at');
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropColumn(['responsable_asignado_at', 'vence_at', 'alerta_nivel']);
        });
    }
};
