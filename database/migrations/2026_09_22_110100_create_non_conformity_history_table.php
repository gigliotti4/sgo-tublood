<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de una No Conformidad. Molde exacto de `observation_history`.
 *
 * §7 del instructivo lo exige explícitamente: fecha y hora de cada
 * modificación, usuario, estado anterior y nuevo, campos modificados, motivo de
 * devolución/rechazo/cancelación/reapertura, comentarios y adjuntos, fechas de
 * aprobación y cierre — y "la información histórica no podrá eliminarse ni
 * reemplazarse".
 *
 * ⚠️ **Sin `updated_at`**: una entrada no se modifica nunca. La inmutabilidad
 * se hace cumplir además desde el modelo (`NonConformityHistory::booted()`
 * lanza excepción en `updating` y `deleting`), no solo por convención.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('non_conformity_id')->constrained('non_conformities')->cascadeOnDelete();

            // Nullable: las entradas automáticas (observer, comandos) no tienen
            // usuario detrás.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('accion');

            // Texto libre: comentario, o el motivo de una devolución, rechazo,
            // cancelación o reapertura.
            $table->text('nota')->nullable();

            // Forma variable según `accion` — {de, a} para un cambio de estado,
            // {sumadas, sacadas} para el vínculo con observaciones, etc.
            $table->json('cambios')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['non_conformity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformity_history');
    }
};
