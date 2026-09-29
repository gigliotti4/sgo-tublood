<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fecha en que se va a comprobar si el plan de acción sirvió, y la marca de
 * que ya se avisó por ella.
 *
 * ⚠️ `fecha_verificacion_prevista` **no es `fecha_seguimiento`**, que ya existe
 * y es *cuándo se hizo* el seguimiento (sección 6, registro). Ésta mira al
 * futuro: se carga al armar el plan de acción, mucho antes de llegar a la etapa
 * de verificación, y es lo único que permite avisar a tiempo. Confundirlas deja
 * el aviso sin fecha o lo dispara cuando ya no sirve.
 *
 * `verificacion_avisada_at` es lo que hace idempotente a `nc:recordatorios`:
 * sin una marca, la tarea avisaría de la misma verificación todos los días. En
 * las acciones alcanza con el propio cambio de estado a `vencida` (mismo truco
 * que `alerta_nivel` en observaciones), pero acá no hay ningún estado que
 * cambie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->date('fecha_verificacion_prevista')->nullable()->after('requiere_capa');
            $table->timestamp('verificacion_avisada_at')->nullable()->after('fecha_verificacion_prevista');
        });
    }

    public function down(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->dropColumn(['fecha_verificacion_prevista', 'verificacion_avisada_at']);
        });
    }
};
