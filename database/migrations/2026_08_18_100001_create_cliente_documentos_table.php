<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklist de documentación de cada cliente: una fila por documento del
 * catálogo de su tipo (config/documentacion_clientes.php).
 *
 * `documento` guarda la clave del catálogo, no un FK: el catálogo vive en
 * config, igual que la taxonomía de incidencias. Una fila cuya clave ya no
 * pertenece al tipo del cliente se borra al guardar el checklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained()->cascadeOnDelete();
            $table->string('documento');
            $table->boolean('presentado')->default(false);
            $table->date('fecha_vencimiento')->nullable();
            $table->timestamps();

            $table->unique(['cliente_id', 'documento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_documentos');
    }
};
