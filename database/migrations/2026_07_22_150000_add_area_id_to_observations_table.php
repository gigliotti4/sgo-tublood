<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Área del organigrama a la que se le asigna la observación, aparte del
 * `sector_id` (sector de gestión, el que define los tipos de incidencia).
 *
 * Se guarda como dato propio del caso: queda registrado que la observación fue
 * de Ventas aunque después cambie el responsable, o aunque esa persona pase a
 * otra área.
 *
 * Ojo: el reloj de vencimiento NO sale de acá — sigue saliendo del área del
 * responsable asignado (ver ObservacionObserver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('sector_id')
                ->constrained('areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
        });
    }
};
