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
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique()->index();
            $table->unsignedSmallInteger('anio');
            $table->string('tipo');
            $table->string('estado')->default('pendiente_clasificacion');

            // Contacto (portal público)
            $table->string('contacto_nombre');
            $table->string('contacto_email');
            $table->string('contacto_numero_cliente')->nullable();
            $table->string('contacto_telefono')->nullable();

            // Comunes
            $table->string('titulo');
            $table->text('descripcion');

            // Falla de Producto
            $table->unsignedInteger('cantidad_afectada')->nullable();
            $table->string('lote')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('numero_remito')->nullable();
            $table->string('tipo_comprobante')->nullable();
            $table->string('institucion')->nullable();
            $table->string('provincia')->nullable();
            $table->string('producto')->nullable();
            $table->string('equipamiento')->nullable();
            $table->string('ejecutivo_cuenta')->nullable();

            // Clasificación (Garantía de Calidad — flujo 8.2, asignado luego)
            $table->string('prioridad')->nullable();
            $table->string('tipo_caso')->nullable();
            $table->boolean('tecnovigilancia')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
