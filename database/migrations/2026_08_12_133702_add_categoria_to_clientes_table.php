<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campo propio del panel (como fecha_vencimiento/mail_nuevo): texto libre por
 * ahora, sin catálogo cerrado — no hay todavía una lista de categorías real
 * para convertirlo en select. El ERP no lo tiene, no lo pisa ClienteSyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('categoria')->nullable()->after('fecha_vencimiento');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('categoria');
        });
    }
};
