<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Responsable del fallo": quién, del sector que falló, cometió el error.
 *
 * ⚠️ No es `responsable_id`: ése es quien GESTIONA el caso. Éste es quien lo
 * causó, y por eso solo lo ven los jefes y Calidad (ver
 * `User::veResponsableDelFallo()`).
 *
 * Apunta a `personas` y no a `users`: buena parte de quienes fallan (choferes,
 * operarios) no tienen usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->foreignId('responsable_fallo_id')->nullable()->after('responsable_id')
                ->constrained('personas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_fallo_id');
        });
    }
};
