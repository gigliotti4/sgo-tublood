<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura organizacional del Excel de Tublood: área, supervisor (a quién se
 * escala si vence el plazo) y gerente (aviso final + último escalón).
 *
 * Reemplaza `sector_id`: ese campo se cargaba a mano y quedó sin fuente de
 * verdad — el Excel es ahora el origen de "dónde trabaja cada persona", y esa
 * lista es la de `areas`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('id')->constrained('areas')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->after('area_id')->constrained('users')->nullOnDelete();
            $table->foreignId('gerente_id')->nullable()->after('supervisor_id')->constrained('users')->nullOnDelete();
            $table->boolean('es_gerente')->default(false)->after('gerente_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sector_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sector_id')->nullable()->after('id')->constrained('sectors')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropConstrainedForeignId('gerente_id');
            $table->dropColumn('es_gerente');
        });
    }
};
