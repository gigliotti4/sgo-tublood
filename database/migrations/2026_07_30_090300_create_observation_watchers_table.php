<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios a notificar de una observación, además del responsable.
 *
 * Reciben el aviso cuando se los suma y pueden comentar en la bitácora del
 * caso, pero no reasignan ni reclasifican (ver ObservacionPolicy::comentar()).
 *
 * Es la primera relación muchos-a-muchos del dominio; el nombre sigue el
 * prefijo de las otras tablas hijas de `observations` (observation_products,
 * observation_attachments, observation_history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Sumar dos veces a la misma persona no significa nada.
            $table->unique(['observation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_watchers');
    }
};
