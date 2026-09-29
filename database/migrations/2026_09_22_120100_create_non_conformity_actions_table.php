<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de acción de una No Conformidad (§4.5 y §4.6).
 *
 * ⚠️ **Esto es el CAPA.** El instructivo no tiene una entidad CAPA aparte: la
 * pliega acá, en el `tipo` de cada acción (corrección / correctiva /
 * preventiva), y deja `non_conformities.requiere_capa` como la determinación
 * que se toma durante la investigación. `ARQUITECTURA-SGO.md` preveía una tabla
 * `capas` con ciclo de vida propio; el instructivo es posterior y más concreto.
 *
 * Tabla hija y no columnas en la NC porque son N por NC, cada una con su
 * responsable, su fecha y su estado (§4.5: "podrán cargarse varias acciones
 * dentro de una misma NC").
 *
 * Las columnas de seguimiento (`fecha_real`, `avance`, `motivo_cancelacion`)
 * se crean acá aunque la pantalla que las usa llegue después: son parte de la
 * forma natural de la tabla y partirlo en dos migraciones no compra nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('non_conformity_id')->constrained('non_conformities')->cascadeOnDelete();

            $table->text('descripcion');
            // correccion | correctiva | preventiva — ver NonConformityAction::TIPOS.
            $table->string('tipo', 20);

            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha_prevista');

            $table->text('evidencia_requerida')->nullable();
            $table->text('observaciones')->nullable();

            // pendiente | en_curso | completada | vencida | cancelada (§4.6).
            $table->string('estado', 20)->default('pendiente')->index();
            $table->text('avance')->nullable();
            $table->date('fecha_real')->nullable();
            // §4.6: cancelar una acción exige justificación.
            $table->text('motivo_cancelacion')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformity_actions');
    }
};
