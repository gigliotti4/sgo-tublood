<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clasificación documental del cliente.
 *
 * `categoria` era texto libre "hasta que hubiera un catálogo real" (ver su
 * propia migración). Ese catálogo ahora existe — config/documentacion_clientes.php —
 * así que se renombra a `tipo_cliente`, que es como lo llaman las planillas de
 * Tublood, y pasa a guardar el slug del tipo.
 *
 * `documentacion_completa` está denormalizado a propósito: el estado se deriva
 * del catálogo (config), no de la base, así que sin esta columna el listado no
 * podría filtrar ni ordenar por él en SQL.
 *
 * Ninguna de estas columnas va en el `upsert()` de ClienteSyncService: son
 * campos propios del panel y la sincronización con RP corre cada 5 minutos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->renameColumn('categoria', 'tipo_cliente');
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('tiene_legajo')->default(false)->after('tipo_cliente');
            $table->boolean('habilitado')->default(true)->after('tiene_legajo');
            // El "Observaciones" de la planilla. No se llama `observaciones`:
            // en este dominio una observación es un reclamo, y el cliente ya
            // tiene relación con ellos.
            $table->text('notas')->nullable()->after('habilitado');
            $table->boolean('documentacion_completa')->default(false)->index()->after('notas');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['tiene_legajo', 'habilitado', 'notas', 'documentacion_completa']);
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->renameColumn('tipo_cliente', 'categoria');
        });
    }
};
