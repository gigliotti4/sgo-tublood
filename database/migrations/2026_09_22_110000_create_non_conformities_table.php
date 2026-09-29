<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No Conformidades: incumplimientos confirmados que requieren investigación,
 * análisis de causa, acciones y verificación de eficacia.
 *
 * Es la contraparte formal de `observations`: una observación registra algo
 * que hay que revisar; una NC gestiona un incumplimiento ya confirmado. Una NC
 * puede nacer de una o varias observaciones (pivot `non_conformity_observation`),
 * pero también de una auditoría, un reclamo o una detección interna — por eso
 * es una entidad propia y no un estado más de `observations`.
 *
 * Fuente: `docs/IT- PARA LA GESTIÓN DE NO CONFORMIDADES EN EL SISTEMA.docx`.
 *
 * ⚠️ **`numero` es nullable y se asigna al APROBAR, no al crear** (§4.4: "una
 * vez aprobada, el sistema asignará el número definitivo"). Es la diferencia de
 * fondo con `observations`, que se numera en el alta. Una NC en Borrador o
 * Rechazada no tiene número, y eso es correcto, no un dato faltante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformities', function (Blueprint $table) {
            $table->id();

            // Nullable: recién existe al aprobar. Único igual, para que dos
            // aprobaciones simultáneas no se peleen el mismo correlativo — ver
            // GeneraNumeroCorrelativo.
            $table->string('numero', 20)->nullable()->unique();
            $table->unsignedSmallInteger('anio')->index();

            $table->string('estado')->default('borrador')->index();

            // interno | externo (§3).
            $table->string('tipo_desvio', 10);
            $table->date('fecha_deteccion');

            // Por qué se origina la NC. El catálogo sale de §2 del instructivo
            // ("Una No Conformidad podrá originarse por: ..."), que es la lista
            // de motivos posibles. Ver NoConformidad::MOTIVOS.
            $table->string('motivo', 40);

            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();

            // "Cliente o proveedor, cuando corresponda" (§3): los dos nullable,
            // y es válido que no haya ninguno (una NC interna de proceso).
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();

            $table->text('descripcion');

            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();

            // Aprobación (§4.3). Se sellan en el observer, igual que el cierre.
            $table->foreignId('aprobada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobada_at')->nullable();

            // Cierre (§4.8). Mismo par que `observations.cerrada_at/cerrada_por`:
            // reabrir los vuelve a null para que un segundo cierre no conserve
            // la fecha del primero.
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable()->index();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformities');
    }
};
