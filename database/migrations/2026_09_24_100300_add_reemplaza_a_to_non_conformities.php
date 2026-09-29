<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Nuevo desvío N°" — la celda de la sección 7 del Informe de Desvío.
 *
 * Cuando la verificación de eficacia da **ineficaz**, no se reabre la misma No
 * Conformidad: se abre una nueva que reemplaza a la anterior. Es la lectura del
 * formulario y la que el cliente confirmó el 24/9/2026, por encima de §4.7 del
 * instructivo, que dice que la NC "deberá regresar" a investigación.
 *
 * ⚠️ La FK vive en la NC **nueva** y apunta hacia atrás, no al revés. Con una
 * sola columna se leen las dos direcciones (`reemplazaA` es el `belongsTo`,
 * `reemplazadaPor` el `hasOne` inverso) y no hay un par de claves circulares que
 * mantener en sincronía — dos columnas apuntándose entre sí se desincronizan en
 * cuanto una escritura falla a mitad de camino.
 *
 * `nullOnDelete()` y no `cascadeOnDelete()`: borrar la NC vieja no puede
 * arrastrarse a la nueva, que es un caso en curso con trabajo propio encima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->foreignId('reemplaza_a_id')->nullable()->after('cerrada_at')
                ->constrained('non_conformities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reemplaza_a_id');
        });
    }
};
