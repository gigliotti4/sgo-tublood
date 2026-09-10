<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo maestro del ERP para el módulo Compras.
 *
 * ⚠️ Es una tabla APARTE de `articulos` a propósito, no un reemplazo:
 *
 *  - `articulos` (734 filas) viene de la API HTTP de RP y es el catálogo
 *    "vendible" que usan observaciones, el portal público y la vinculación de
 *    proveedores.
 *  - Ésta espeja `ARTICULOS` de SQL Server: 5.227 filas, el catálogo completo
 *    incluidos los discontinuados. Compras los necesita todos.
 *
 * El local es subconjunto exacto del ERP (verificado: 734 de 734 matchean),
 * así que unificarlas es posible más adelante, pero cambiaría el buscador del
 * portal público y los contadores del listado de Artículos.
 *
 * Refresh completo en cada sync: no hay campos propios del panel acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_articulos', function (Blueprint $table) {
            $table->id();

            // ⚠️ Colación binaria a propósito. `utf8mb4_unicode_ci` (el default
            // del proyecto) ignora acentos y mayúsculas, así que el índice
            // único colapsaría dos códigos que el ERP considera distintos y la
            // sync perdería artículos en silencio. Medido: hoy pasa con
            // 'CODIGO' vs 'CÓDIGO' — dos filas de encabezado de planilla que
            // alguien importó al ERP. Es una sola colisión y son basura, pero
            // el índice tiene que significar lo mismo de los dos lados o el día
            // que colisionen dos artículos reales no nos enteramos.
            // Los códigos entran siempre en mayúscula (UPPER en el SELECT), así
            // que las búsquedas exactas siguen funcionando igual.
            $column = $table->string('codigo', 30);

            // Solo en MySQL: SQLite (los tests) ya compara binario por defecto y
            // no conoce esta colación — declararla ahí tira "no such collation".
            if (DB::connection()->getDriverName() === 'mysql') {
                $column->collation('utf8mb4_bin');
            }

            $column->unique();
            $table->string('descripcion')->nullable();

            // Envases, tal cual los cuenta el ERP. Puede venir NEGATIVO (~15
            // artículos): es un error de carga, se cuenta 0 al calcular.
            $table->decimal('cant_stock', 16, 4)->default(0);

            // AGRU_1. Se guarda el código; la etiqueta sale de config/compras.php.
            $table->string('agru_1', 10)->nullable()->index();

            // Clasificación de texto para unificar multimarca. Se guarda CRUDA:
            // los comodines se filtran al armar el dataset, no acá.
            $table->string('gtin', 60)->nullable()->index();

            // SIN_STOCK = 'S': servicios y mano de obra, no mueven stock.
            $table->boolean('sin_stock')->default(false)->index();
            $table->boolean('activo')->default(false)->index();

            // CODIGO_REFERENCIA = unidades por envase.
            // ⚠️ Hoy está cargado en 13 de 5.227 artículos. Vacío o 0 vale 1.
            $table->unsignedInteger('unidades_por_envase')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_articulos');
    }
};
