<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El responsable de un desvío: quien lo gestiona de punta a punta.
 *
 * Hasta el 25/9/2026 toda la gestión operativa pasaba obligatoriamente por
 * Garantía de Calidad, porque el permiso `nc.gestionar` era lo único que
 * habilitaba investigar, cargar la contención, armar el plan, verificar y
 * cerrar. El cliente lo marcó ese día: Calidad hace seguimiento y control, pero
 * **no va a ser responsable en todas las NC**.
 *
 * Quien esté acá puede hacer todo lo que hace Calidad sobre ESTE caso, sin
 * tener el permiso global. Mismo criterio que `observations.responsable_id`.
 *
 * `nullOnDelete()`: si se borra la persona, el caso queda sin responsable y lo
 * retoma Calidad. Lo contrario —arrastrar la NC— sería absurdo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->foreignId('responsable_id')->nullable()->after('creado_por')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_id');
        });
    }
};
