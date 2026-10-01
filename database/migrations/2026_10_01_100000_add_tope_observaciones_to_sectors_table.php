<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tope de observaciones abiertas por sector.
 *
 * Hasta acá la única alerta era **por caso**: `observaciones:alertas` avisa
 * cuando una observación puntual pasó su plazo. Nadie miraba la carga agregada
 * de un área, así que un sector podía juntar veinte reclamos abiertos sin que
 * se enterara nadie hasta que empezaban a vencer de a uno.
 *
 * ⚠️ `tope_observaciones` nace **null en los diez sectores**, y null significa
 * "sin tope, no avisa nunca". Nada cambia hasta que alguien lo cargue desde el
 * ABM de Sectores: sembrar un umbral inventado llenaría de avisos el primer día
 * y nadie volvería a mirar la campana.
 *
 * `tope_avisado_at` es la marca de idempotencia. Sin ella el aviso saldría en
 * cada corrida del comando mientras el sector siguiera saturado. Mismo papel
 * que `non_conformities.verificacion_avisada_at` en `nc:recordatorios`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->unsignedSmallInteger('tope_observaciones')->nullable()->after('dias_gestion');
            $table->timestamp('tope_avisado_at')->nullable()->after('tope_observaciones');
        });
    }

    public function down(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->dropColumn(['tope_observaciones', 'tope_avisado_at']);
        });
    }
};
