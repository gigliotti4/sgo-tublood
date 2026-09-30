<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clasificación que RP Sistemas le pone a cada proveedor (`AGRU_1`).
 *
 * Es el único campo de la vista que separa a los proveedores de producto médico
 * (90) del resto del padrón (1.788), y hasta ahora no lo traíamos. Sin él, la
 * pantalla de proveedores lista 1.849 filas sin ninguna forma de empezar por
 * las que importan.
 *
 * Se guarda el **código crudo del ERP** (`01`, `02`, `03`) y no un slug nuestro:
 * es la clave de RP, la etiqueta vive en `config/proveedores.php` y así un
 * código nuevo entra sin migración. Ver ahí por qué no es `tipo_proveedor`.
 *
 * Va indexada porque es un filtro del listado, igual que las demás columnas por
 * las que se filtra el padrón.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('clasificacion_erp', 5)->nullable()->after('estado')->index();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropIndex(['clasificacion_erp']);
            $table->dropColumn('clasificacion_erp');
        });
    }
};
