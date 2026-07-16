<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            // Campos variables por tipo de incidencia (carga interna dirigida por config/incidencias.php).
            $table->json('datos_especificos')->nullable()->after('tecnovigilancia');

            // La carga interna no tiene contacto externo → estos dejan de ser obligatorios.
            $table->string('contacto_nombre')->nullable()->change();
            $table->string('contacto_email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropColumn('datos_especificos');
            $table->string('contacto_nombre')->nullable(false)->change();
            $table->string('contacto_email')->nullable(false)->change();
        });
    }
};
