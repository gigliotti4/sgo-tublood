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
        Schema::table('observation_attachments', function (Blueprint $table) {
            // Los dos nullable: los adjuntos que ya existen (portal, alta) no
            // tienen ni entrada de historial ni autor conocido, y rellenarlos
            // con un valor inventado sería peor que dejarlos vacíos.
            $table->foreignId('observation_history_id')->nullable()->after('observation_id')
                ->constrained('observation_history')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->after('observation_history_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observation_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('observation_history_id');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
