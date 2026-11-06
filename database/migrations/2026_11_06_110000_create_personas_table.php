<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal por sector, **con o sin usuario del SGO**.
 *
 * Existe para el campo "Responsable del fallo": en Logística, Depósito y Calidad
 * hay gente (choferes, operarios, personal de laboratorio) que no usa el sistema
 * y por eso no aparece en ninguna lista. Se carga acá, sin usuario ni
 * contraseña, y alimenta el desplegable.
 *
 * `activo = false` los saca del desplegable pero los conserva: quien dejó la
 * empresa sigue figurando en las observaciones que ya tenía. Por eso **no se
 * borran** desde el panel.
 *
 * `user_id` es opcional: une a la persona con su usuario cuando lo tiene, para
 * no duplicar a quien ya está en el SGO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->string('puesto')->nullable();
            $table->boolean('activo')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sector_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
