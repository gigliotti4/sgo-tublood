<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El número de proveedor pasa a ser opcional.
 *
 * El Excel de artículos trae la razón social del proveedor pero **no** el
 * NUM_PROV, y ahora ese import da de alta los proveedores que no estén en el
 * padrón (ver `ArticuloImportService`). Esos quedan sin número hasta que se
 * importe el padrón real, que los adopta por razón social normalizada en vez
 * de duplicarlos (ver `ProveedorImportService`).
 *
 * El índice único sobre `numero` se mantiene: MySQL y SQLite permiten varios
 * NULL en una columna única, así que sigue impidiendo dos proveedores con el
 * mismo número sin estorbar a los que todavía no tienen uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('numero')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('numero')->nullable(false)->change();
        });
    }
};
