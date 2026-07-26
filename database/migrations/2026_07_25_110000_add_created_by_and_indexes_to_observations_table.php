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
        Schema::table('observations', function (Blueprint $table) {
            // Nullable: las observaciones del portal público no tienen creador
            // interno, y las cargadas antes de este campo tampoco.
            $table->foreignId('created_by')->nullable()->after('responsable_id')->constrained('users')->nullOnDelete();

            // Columnas del buscador del listado admin.
            $table->index('estado');
            $table->index('prioridad');
            $table->index('origen');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['estado']);
            $table->dropIndex(['prioridad']);
            $table->dropIndex(['origen']);
            $table->dropIndex(['created_at']);
        });
    }
};
