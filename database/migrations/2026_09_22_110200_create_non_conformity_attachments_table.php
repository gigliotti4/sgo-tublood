<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencias y adjuntos de una No Conformidad. Molde de
 * `observation_attachments`.
 *
 * El instructivo pide evidencias en varios momentos del flujo (al crear §3, en
 * cada acción §4.6, en la verificación §4.7), así que el mismo trait
 * `GuardaAdjuntos` las guarda en subcarpetas distintas del disco `local`
 * (privado): `no-conformidades/{numero}/`, `.../bitacora/`, `.../acciones/`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformity_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('non_conformity_id')->constrained('non_conformities')->cascadeOnDelete();

            // Cuando el adjunto se cargó junto con un comentario de bitácora,
            // queda atado a esa entrada; si es un archivo suelto de la NC, null.
            $table->foreignId('non_conformity_history_id')->nullable()
                ->constrained('non_conformity_history')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformity_attachments');
    }
};
