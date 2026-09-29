<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale el `tipo` de cada acción del plan (corrección / correctiva / preventiva).
 *
 * Venía del instructivo, que usa esas tres palabras para explicar qué es un
 * CAPA. Pero el formulario real no tiene esa columna —su tabla es Acción /
 * Responsable / Plazo— y el cliente confirmó el 24/9/2026 que en la práctica no
 * hacen la distinción: era un campo obligatorio que se iba a completar al azar.
 *
 * ⚠️ El plan de acción **sigue siendo el CAPA**. Lo que se pierde es solo el
 * registro de qué tipo era cada acción; `non_conformities.requiere_capa` no se
 * toca.
 *
 * El `down()` repone la columna con `correctiva` para las filas existentes: es
 * NOT NULL y hay que darle algo, y es el tipo más frecuente de los tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformity_actions', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('non_conformity_actions', function (Blueprint $table) {
            $table->string('tipo', 20)->default('correctiva')->after('descripcion');
        });
    }
};
