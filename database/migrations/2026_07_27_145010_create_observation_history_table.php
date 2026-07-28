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
        Schema::create('observation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            // Nullable: las entradas automáticas (observer, comando de alertas)
            // no tienen un usuario detrás.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion');
            $table->text('nota')->nullable();
            // {de: ..., a: ...} de lo que cambió, cuando la entrada es de un campo.
            $table->json('cambios')->nullable();
            // Sin updated_at: una entrada de historial no se modifica.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observation_history');
    }
};
