<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Áreas del organigrama ("sector original" del Excel de Tublood): dónde trabaja
 * cada persona. Es una lista distinta de `sectors`, que son los sectores de
 * gestión a los que se asigna una observación y que tienen tipos de incidencia.
 * Varios nombres se repiten entre ambas listas (Logística, Facturación,
 * Depósito) con significados distintos, por eso van en tablas separadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('slug')->unique();
            // Plazo de gestión en días hábiles antes de que salte la alerta.
            $table->unsignedSmallInteger('dias_gestion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
