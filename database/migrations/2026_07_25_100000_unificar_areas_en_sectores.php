<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unifica `areas` (organigrama, traído del Excel) y `sectors` (destino de
 * gestión de una observación) en una sola taxonomía: `sectors`. Los datos
 * mostraron que eran la misma lista escrita dos veces con distinta
 * nomenclatura, y la duplicación bloqueaba features pendientes (clasificación
 * y derivación por sector necesitan saber a qué sector pertenece un usuario,
 * y `users` no tenía sector).
 *
 * El catálogo de sectores es fijo (los 8 de `SectorSeeder`): esta migración
 * NO crea sectores nuevos. Un área del Excel que no matchea ningún sector del
 * catálogo (vía `config('organizacion.alias_sectores')` o por slug directo)
 * deja a sus usuarios sin sector — se asignan a mano después.
 *
 * `down()` recrea el esquema (tabla `areas` + las FKs `area_id`) pero no
 * restaura los datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_gestion')->nullable()->after('slug');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sector_id')->nullable()->after('id')->constrained('sectors')->nullOnDelete();
        });

        if (Schema::hasTable('areas')) {
            $alias = config('organizacion.alias_sectores', []);

            $areas = DB::table('areas')->get();

            foreach ($areas as $area) {
                $slug = $alias[$area->slug] ?? $area->slug;

                $sector = DB::table('sectors')->where('slug', $slug)->first();

                if (! $sector) {
                    continue;
                }

                if ($area->dias_gestion !== null) {
                    DB::table('sectors')->where('id', $sector->id)->update(['dias_gestion' => $area->dias_gestion]);
                }

                DB::table('users')->where('area_id', $area->id)->update(['sector_id' => $sector->id]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
        });

        Schema::table('observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
        });

        Schema::dropIfExists('areas');
    }

    public function down(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('dias_gestion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('id')->constrained('areas')->nullOnDelete();
        });

        Schema::table('observations', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('sector_id')->constrained('areas')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sector_id');
        });

        Schema::table('sectors', function (Blueprint $table) {
            $table->dropColumn('dias_gestion');
        });
    }
};
