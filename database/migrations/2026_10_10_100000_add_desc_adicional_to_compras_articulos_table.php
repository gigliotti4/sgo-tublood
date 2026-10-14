<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ARTICULOS.DESC_ADICIONAL` en el catálogo de Compras.
 *
 * Es donde el ERP anota que un artículo viene **bonificado** (sin cargo, junto
 * con otro): "VIENE BONIFICADO CON RE-4952", "SIN COSTO (BONIFICADO
 * PROVEEDOR)". No hay un campo propio para eso, así que el tablero y el mail
 * mensual los detectan por texto — ver `compras.bonificado` en la config.
 * Se guarda crudo: el patrón se aplica al armar el dataset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_articulos', function (Blueprint $table) {
            $table->text('desc_adicional')->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('compras_articulos', function (Blueprint $table) {
            $table->dropColumn('desc_adicional');
        });
    }
};
