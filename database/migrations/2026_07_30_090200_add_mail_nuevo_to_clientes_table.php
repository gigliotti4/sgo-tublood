<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail de contacto real del cliente, cargado por él mismo desde el portal.
 *
 * Hace falta una columna aparte porque `mail` viene de RP Sistemas y está en la
 * lista de columnas del upsert de ClienteSyncService: cualquier corrección se
 * pisaría en la próxima sincronización (corre cada 5 minutos). Este campo, como
 * `fecha_vencimiento`, queda fuera de esa lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('mail_nuevo')->nullable()->after('mail');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('mail_nuevo');
        });
    }
};
