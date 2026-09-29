<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa "Abierta / En investigación" (§4.4 del instructivo).
 *
 * Todas nullable: la investigación se completa de a poco, a lo largo de días, y
 * lo que exige el instructivo no es que estén todas cargadas para guardar sino
 * para **avanzar al plan de acción** — esa regla vive en
 * `NoConformidad::investigacionCompleta()`, no en el schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            // Contención: lo que se hizo YA para frenar el problema, antes de
            // saber la causa.
            $table->text('accion_inmediata')->nullable()->after('descripcion');
            $table->foreignId('responsable_contencion_id')->nullable()->after('accion_inmediata')
                ->constrained('users')->nullOnDelete();
            $table->date('fecha_ejecucion_contencion')->nullable()->after('responsable_contencion_id');

            $table->text('investigacion')->nullable()->after('fecha_ejecucion_contencion');
            $table->text('alcance')->nullable()->after('investigacion');
            // Productos, lotes, pedidos o procesos afectados. Texto libre: el
            // alcance de una NC puede ser un lote, una familia o un proceso
            // entero, y encorsetarlo en FKs dejaría afuera la mitad de los casos.
            $table->text('afectados')->nullable()->after('alcance');
            $table->text('evaluacion_riesgo')->nullable()->after('afectados');

            // Determinaciones de §4.4. `requiere_capa` es una decisión que se
            // registra, no una entidad aparte: el plan de acción ES el CAPA.
            $table->boolean('es_grave')->default(false)->after('evaluacion_riesgo');
            $table->boolean('es_repetitivo')->default(false)->after('es_grave');
            $table->boolean('requiere_capa')->default(false)->after('es_repetitivo');

            $table->text('causa_raiz')->nullable()->after('requiere_capa');
            // Las 6M: {hombre: "...", maquina: "...", ...}, solo los factores
            // que contribuyeron. Es un objeto y no una lista de tildes porque lo
            // que sirve de un Ishikawa es *qué* aportó cada factor, no cuáles se
            // marcaron. Catálogo en NoConformidad::FACTORES_CAUSA.
            $table->json('causa_raiz_factores')->nullable()->after('causa_raiz');

            $table->text('conclusion')->nullable()->after('causa_raiz_factores');
        });
    }

    public function down(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_contencion_id');
            $table->dropColumn([
                'accion_inmediata',
                'fecha_ejecucion_contencion',
                'investigacion',
                'alcance',
                'afectados',
                'evaluacion_riesgo',
                'es_grave',
                'es_repetitivo',
                'requiere_capa',
                'causa_raiz',
                'causa_raiz_factores',
                'conclusion',
            ]);
        });
    }
};
