<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo real entre un artículo y su proveedor.
 *
 * Se llena desde el Excel de artículos, matcheando la columna
 * `proveedor_principal` (razón social) contra el padrón de `proveedores` — ver
 * `ArticuloImportService`. Es un campo **propio del panel**: el `upsert()` de
 * `ArticuloSyncService` no lo toca, igual que `pm`, `legajo` y compañía.
 *
 * Ojo: convive con `articulos.codigo_proveedor`, que es un string suelto que sí
 * viene del ERP y sí se pisa en cada sincronización. No son lo mismo y no está
 * confirmado que usen el mismo padrón, por eso no hay backfill de uno al otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            // El nombre de la tabla va explícito: Laravel lo deduciría
            // pluralizando `proveedor` → `proveedors`, que no existe.
            $table->foreignId('proveedor_id')
                ->nullable()
                ->after('codigo_proveedor')
                ->constrained('proveedores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_id');
        });
    }
};
