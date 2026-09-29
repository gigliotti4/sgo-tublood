<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acción inmediata de contención (sección 3 del Informe de Desvío).
 *
 * Eran tres columnas sueltas en `non_conformities` porque el instructivo la
 * describe en singular. El formulario real la dibuja como una **tabla** de
 * Fecha / Acción / Responsable, y el cliente confirmó el 24/9/2026 que a veces
 * se toman varias medidas distintas antes de conocer la causa.
 *
 * ⚠️ El `up()` copia lo cargado a una primera fila **antes** de borrar las
 * columnas: hay NC con contención escrita y perderla sería perder trabajo real.
 * El `down()` hace el camino inverso con la fila más antigua — si había varias,
 * las demás se pierden, que es lo inevitable al volver a un modelo de una sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_containments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('non_conformity_id')->constrained('non_conformities')->cascadeOnDelete();

            $table->date('fecha')->nullable();
            $table->text('accion');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        $ahora = now();

        DB::table('non_conformities')
            ->whereNotNull('accion_inmediata')
            ->where('accion_inmediata', '<>', '')
            ->orderBy('id')
            ->each(function ($nc) use ($ahora) {
                DB::table('non_conformity_containments')->insert([
                    'non_conformity_id' => $nc->id,
                    'fecha' => $nc->fecha_ejecucion_contencion,
                    'accion' => $nc->accion_inmediata,
                    'responsable_id' => $nc->responsable_contencion_id,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            });

        Schema::table('non_conformities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_contencion_id');
            $table->dropColumn(['accion_inmediata', 'fecha_ejecucion_contencion']);
        });
    }

    public function down(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->text('accion_inmediata')->nullable()->after('descripcion');
            $table->foreignId('responsable_contencion_id')->nullable()->after('accion_inmediata')
                ->constrained('users')->nullOnDelete();
            $table->date('fecha_ejecucion_contencion')->nullable()->after('responsable_contencion_id');
        });

        DB::table('non_conformity_containments')
            ->orderBy('non_conformity_id')
            ->orderBy('id')
            ->get()
            ->groupBy('non_conformity_id')
            ->each(function ($filas, $ncId) {
                $primera = $filas->first();

                DB::table('non_conformities')->where('id', $ncId)->update([
                    'accion_inmediata' => $primera->accion,
                    'responsable_contencion_id' => $primera->responsable_id,
                    'fecha_ejecucion_contencion' => $primera->fecha,
                ]);
            });

        Schema::dropIfExists('non_conformity_containments');
    }
};
