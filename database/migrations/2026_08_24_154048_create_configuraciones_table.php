<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave-valor de la configuración de marca y textos.
 *
 * Sin filas semilla a propósito: el catálogo y los valores por defecto viven en
 * `config/configuracion.php`, y acá solo se guarda lo que alguien cambió. Ver
 * `App\Support\Configuracion`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            // `text` y no `string`: el párrafo del login y la lista de puntos
            // destacados no entran cómodos en 255.
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
