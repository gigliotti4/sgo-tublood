<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objetivos de gestión por tipo de fallo (el "OBJETIVOS 2026" del cliente) y lo
 * que el tablero necesita para evaluarlos.
 *
 * - `indicadores`: una fila por (sector, fallo), con su objetivo mensual y su
 *   tolerancia. `tipo_meta` dice para qué lado se evalúa: **máximo de fallos**
 *   (menos es mejor: la mayoría) o **mínimo a alcanzar** (más es mejor:
 *   producción y stock).
 * - `indicador_valores`: el valor de un mes cuando el indicador NO sale del SGO
 *   (fallo de stock, cancelaciones, documentación aduanera…). Los que sí salen
 *   del SGO se cuentan solos desde las observaciones y no pasan por acá.
 * - `indicador_cargas`: el historial de cada planilla subida (fecha, usuario,
 *   archivo y qué cambió).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicadores', function (Blueprint $table) {
            $table->id();
            // Tal como viene en la planilla (CALIDAD, COMPRAS, PRODUCCIÓN TUBOS…). Es
            // una etiqueta de la planilla y no una FK: la planilla tiene sectores que
            // el SGO no (COMPRAS y COMEX por separado, Producción partida en dos).
            $table->string('sector');
            // El sector del SGO al que corresponde, si se lo pudo resolver.
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->string('fallo');

            // De dónde sale el dato: 'sgo' (se cuenta de las observaciones) o 'manual'.
            $table->string('origen_dato', 10)->default('manual');
            // La celda "FUENTE / TIPO SGO" tal cual ("SGO · 5.1 + 5.3"), para poder
            // volver a leerla y para que quien edita vea de dónde vino el mapeo.
            $table->string('fuente')->nullable();
            // Qué observaciones cuenta: claves de tipo (`observations.tipo`) y, si hace
            // falta partir un tipo en dos indicadores, criterios sobre el motivo o el título.
            $table->json('tipos')->nullable();
            $table->json('criterios')->nullable();

            $table->string('meta')->nullable();
            $table->string('meta_medicion')->nullable();
            // Pedidos del mes en los que se basa el objetivo (Armado: 1 % de 883). Con eso
            // el tablero lo escala con los pedidos reales de cada mes.
            $table->decimal('cant_pedidos', 12, 2)->nullable();
            $table->decimal('objetivo', 14, 3);
            $table->decimal('tolerancia', 14, 3);
            $table->string('tipo_meta', 10)->default('maximo'); // maximo | minimo
            $table->string('descripcion')->nullable();
            $table->string('estado', 12)->default('aprobado'); // aprobado | propuesta | revisar

            // % de importancia dentro del sector. Opcional: la planilla no lo trae.
            $table->decimal('importancia', 5, 2)->nullable();
            $table->text('notas')->nullable();

            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['sector', 'fallo']);
        });

        Schema::create('indicador_valores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicador_id')->constrained('indicadores')->cascadeOnDelete();
            $table->char('mes', 7); // 2026-09
            $table->decimal('valor', 14, 3);
            $table->foreignId('cargado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['indicador_id', 'mes']);
        });

        Schema::create('indicador_cargas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archivo');
            $table->json('resumen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicador_cargas');
        Schema::dropIfExists('indicador_valores');
        Schema::dropIfExists('indicadores');
    }
};
