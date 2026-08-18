<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clasificación documental del proveedor: la misma que la de clientes (mismas
 * figuras reguladas, mismos papeles de ANMAT), sobre el catálogo compartido de
 * config/documentacion.php.
 *
 * No se agrega una columna de notas: `proveedores.observaciones` ya existe y ya
 * es un campo propio del panel. Es la diferencia con `clientes`, donde hubo que
 * llamarla `notas` porque ahí una "observación" es un reclamo.
 *
 * ⚠️ `habilitado` **no es** `estado`. `estado` viene del ERP (A/S/I) y lo pisa
 * `ProveedorSyncService` en cada sincronización; `habilitado` es el Sí/No
 * documental del panel y queda fuera de la lista de columnas del `upsert()`,
 * igual que el resto de lo que se agrega acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('tipo_proveedor')->nullable()->after('estado');
            $table->boolean('tiene_legajo')->default(false)->after('tipo_proveedor');
            $table->boolean('habilitado')->default(true)->after('tiene_legajo');
            $table->date('fecha_vencimiento')->nullable()->after('habilitado');
            $table->boolean('documentacion_completa')->default(false)->index()->after('fecha_vencimiento');
        });

        Schema::create('proveedor_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('documento');
            $table->boolean('presentado')->default(false);
            $table->date('fecha_vencimiento')->nullable();
            $table->timestamps();

            $table->unique(['proveedor_id', 'documento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_documentos');

        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_proveedor', 'tiene_legajo', 'habilitado',
                'fecha_vencimiento', 'documentacion_completa',
            ]);
        });
    }
};
