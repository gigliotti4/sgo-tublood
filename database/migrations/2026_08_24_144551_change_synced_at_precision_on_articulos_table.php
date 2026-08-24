<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precisión a microsegundos: ArticuloSyncService marca inactivo lo que tenga
 * `synced_at` anterior al de la corrida actual, y con precisión de segundo
 * dos corridas dentro del mismo segundo (dos clics en "Sincronizar",
 * `--sync` corrido dos veces seguidas) comparten el mismo valor y la
 * desactivación no detecta nada — un bug real que encontraron los tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->timestamp('synced_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->timestamp('synced_at')->nullable()->change();
        });
    }
};
