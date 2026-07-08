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
            $table->dropColumn([
                'producto',
                'cantidad_afectada',
                'lote',
                'fecha_vencimiento',
                'numero_remito',
                'tipo_comprobante',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_afectada')->nullable();
            $table->string('lote')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('numero_remito')->nullable();
            $table->string('tipo_comprobante')->nullable();
            $table->string('producto')->nullable();
        });
    }
};
