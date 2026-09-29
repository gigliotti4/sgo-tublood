<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificación de eficacia (§4.7) y cierre (§4.8).
 *
 * `cerrada_at` y `cerrada_por` ya existen desde la migración inicial: los sella
 * el observer al entrar en un estado final, igual que en `observations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            // §4.7 — cómo se verificó que las acciones sirvieron.
            $table->text('metodo_seguimiento')->nullable()->after('conclusion');
            $table->date('fecha_seguimiento')->nullable()->after('metodo_seguimiento');
            $table->text('evidencia_revisada')->nullable()->after('fecha_seguimiento');
            // eficaz | ineficaz | pendiente_evaluacion.
            // ⚠️ "Pendiente de evaluación" es un RESULTADO, no un estado de la
            // NC: mientras esté así, la NC se queda en verificación esperando
            // que pase el tiempo necesario para medir.
            $table->string('resultado_eficacia', 25)->nullable()->after('evidencia_revisada');
            $table->text('observaciones_verificacion')->nullable()->after('resultado_eficacia');

            // §4.8 — lo que se registra al cerrar.
            $table->text('resultado_final')->nullable()->after('observaciones_verificacion');
            $table->text('observaciones_finales')->nullable()->after('resultado_final');
        });
    }

    public function down(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->dropColumn([
                'metodo_seguimiento',
                'fecha_seguimiento',
                'evidencia_revisada',
                'resultado_eficacia',
                'observaciones_verificacion',
                'resultado_final',
                'observaciones_finales',
            ]);
        });
    }
};
