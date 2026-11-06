<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta cuándo está prorrogado el plazo de una observación.
 *
 * Es el DENORMALIZADO de la última prórroga aprobada (`observation_extensions`),
 * y existe para poder preguntar "¿está vencida?" en SQL con
 * `COALESCE(prorroga_hasta, vence_at)`. `vence_at` queda como el plazo
 * ORIGINAL: no se pisa, para que el tablero pueda mostrar cuánto se estiró.
 *
 * Es fecha y hora, no solo fecha: guarda el FIN del día autorizado (23:59:59).
 * Una prórroga "hasta el 20/11" no puede dejar la observación vencida a las
 * 08:00 del 20/11, y comparar una fecha pelada contra `now()` haría justo eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dateTime('prorroga_hasta')->nullable()->after('vence_at');
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropColumn('prorroga_hasta');
        });
    }
};
