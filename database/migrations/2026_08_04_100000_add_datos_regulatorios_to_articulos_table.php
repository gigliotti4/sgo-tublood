<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuatro campos propios del panel, editables a mano o por importación de
 * Excel: fecha de vencimiento, PM (registro de producto médico), legajo y
 * observaciones. El ERP no los tiene.
 *
 * ⚠️ No van en la lista de columnas del `upsert()` de ArticuloSyncService — ver
 * el comentario ahí mismo y el mismo contrato que ya rige para
 * `clientes.fecha_vencimiento` / `clientes.mail_nuevo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->date('fecha_vencimiento')->nullable()->after('codigo_proveedor');
            $table->string('pm')->nullable()->index()->after('fecha_vencimiento');
            $table->string('legajo')->nullable()->index()->after('pm');
            $table->text('observaciones')->nullable()->after('legajo');
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['fecha_vencimiento', 'pm', 'legajo', 'observaciones']);
        });
    }
};
