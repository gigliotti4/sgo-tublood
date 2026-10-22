<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "SGO Observaciones a modificar 5" (reunión con Tublood del 21/10/2026).
 *
 * - `non_conformity_products`: los productos afectados de una No Conformidad
 *   (sección 2), con el mismo molde que `observation_products`. Hasta acá eran un
 *   texto libre que no registraba los productos como lo hace una Falla de producto.
 * - `non_conformity_attachments.seccion`: el adjunto de "Riesgo involucrado /
 *   Ilustración" se carga en la sección 2 y se muestra ahí, no entre las
 *   evidencias sueltas. Null = evidencia suelta, como hasta ahora.
 * - Se borran `non_conformities.alcance` y `.conclusion`: se pidió sacarlos de la
 *   pantalla y al 21/10/2026 estaban vacíos en las 4 NC de producción.
 * - `observation_products.producto`, `lote` y `fecha_vencimiento` pasan a nullable:
 *   en 6.2 "Falla de proveedor" los productos son opcionales y alcanza con el
 *   código o el nombre y la cantidad. La Falla de producto los sigue exigiendo
 *   en la validación (`ReglasObservacion::productos()`), no en la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('non_conformity_id')->constrained('non_conformities')->cascadeOnDelete();
            $table->string('codigo')->nullable()->index();
            $table->string('producto')->nullable();
            $table->unsignedInteger('cantidad_afectada');
            $table->string('tipo_presentacion')->nullable();
            $table->string('lote')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->timestamps();
        });

        Schema::table('non_conformity_attachments', function (Blueprint $table) {
            $table->string('seccion', 30)->nullable()->after('non_conformity_history_id');
        });

        Schema::table('non_conformities', function (Blueprint $table) {
            $table->dropColumn(['alcance', 'conclusion']);
        });

        Schema::table('observation_products', function (Blueprint $table) {
            $table->string('producto')->nullable()->change();
            $table->string('lote')->nullable()->change();
            $table->date('fecha_vencimiento')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->text('alcance')->nullable();
            $table->text('conclusion')->nullable();
        });

        Schema::table('non_conformity_attachments', function (Blueprint $table) {
            $table->dropColumn('seccion');
        });

        Schema::dropIfExists('non_conformity_products');

        // Las columnas de `observation_products` no vuelven a NOT NULL: una fila
        // de 6.2 sin lote haría fallar el rollback, y dejarlas nullable no rompe nada.
    }
};
