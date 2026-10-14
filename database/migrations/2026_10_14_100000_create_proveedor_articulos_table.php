<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Espejo de `PROVE_ARTI` del ERP: la pestaña "Proveedores" de la ficha del artículo en RP, con qué
 * proveedores lo venden y cuál está marcado como principal.
 *
 * Hasta el 14/10/2026 nadie la leía, y el proveedor de un artículo se adivinaba desde el padrón (solo
 * los 746 del catálogo) o el kardex (solo con un único proveedor histórico): 63 productos de
 * Distribución activos salían sin proveedor en el tablero de Compras teniéndolo cargado en el ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_articulos', function (Blueprint $table) {
            $table->id();
            // Misma colación binaria que `compras_articulos.codigo`: `unicode_ci` colapsaría códigos que
            // el ERP considera distintos y el índice único rechazaría la sync.
            $column = $table->string('codigo_articulo', 30);
            if (DB::connection()->getDriverName() === 'mysql') {
                $column->collation('utf8mb4_bin');
            }
            $column->index();
            // String y no int, aunque en el ERP sea `int`: se compara contra `proveedores.numero`, que es
            // string, y mezclar tipos en un `whereIn` hace que MySQL castee la columna entera.
            $table->string('proveedor_numero', 20);
            $table->boolean('principal')->default(false);
            // `CODI_PROVE`: el código con el que ESE proveedor conoce al artículo (p. ej. "ML-VIT-DEXTR").
            $table->string('codigo_proveedor', 40)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['codigo_articulo', 'proveedor_numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_articulos');
    }
};
