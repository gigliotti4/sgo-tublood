<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `observation_products.codigo` se creó sin índice y ya lo usan dos consultas
 * que barren la tabla entera: el filtro por artículo del listado
 * (`whereIn('codigo', ...)`) y el join contra `articulos` del ranking de
 * proveedores con más fallas del Dashboard.
 *
 * Del otro lado `articulos.codigo` ya es `unique`, así que con este índice el
 * join queda cubierto de los dos lados.
 *
 * No es una FK: el código puede venir tipeado a mano desde el portal y no
 * siempre matchea un artículo del catálogo — ver ObservationProduct::articulo().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observation_products', function (Blueprint $table) {
            $table->index('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('observation_products', function (Blueprint $table) {
            $table->dropIndex(['codigo']);
        });
    }
};
