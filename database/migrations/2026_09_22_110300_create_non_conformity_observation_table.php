<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo entre No Conformidades y Observaciones (§5 del instructivo).
 *
 * ⚠️ **Es muchos-a-muchos, no una FK simple.** Una NC puede originarse en
 * varias observaciones — está pedido por escrito en `GUIA-FUNCIONAL-SGO.md`:
 * "la posibilidad de vincular una no conformidad a varias observaciones". Y del
 * otro lado, nada impide que una observación termine alimentando más de una NC.
 *
 * Es el segundo muchos-a-muchos del dominio, después de `observation_watchers`,
 * y sigue su molde.
 *
 * ⚠️ `sync()` sobre esta relación **no dispara el evento `updated`** del modelo,
 * así que el observer no se entera del cambio: la entrada de bitácora del
 * vínculo se escribe desde el controller. Mismo caso y mismo remedio que
 * `ObservacionController::sincronizarNotificados()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_observation', function (Blueprint $table) {
            $table->id();

            $table->foreignId('non_conformity_id')->constrained('non_conformities')->cascadeOnDelete();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['non_conformity_id', 'observation_id'], 'nc_observacion_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformity_observation');
    }
};
