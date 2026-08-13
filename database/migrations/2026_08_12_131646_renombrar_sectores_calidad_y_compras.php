<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renombra solo el `nombre` visible de dos sectores. El `slug` (garantia_calidad,
 * comex) no cambia: sigue siendo la clave de config/incidencias.php, el nombre
 * del rol de Spatie y la clave de config/organizacion.php, ninguno de los
 * cuales se muestra en la interfaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sectors')->where('slug', 'garantia_calidad')->update(['nombre' => 'Calidad']);
        DB::table('sectors')->where('slug', 'comex')->update(['nombre' => 'Compras']);
    }

    public function down(): void
    {
        DB::table('sectors')->where('slug', 'garantia_calidad')->update(['nombre' => 'Garantía de Calidad']);
        DB::table('sectors')->where('slug', 'comex')->update(['nombre' => 'COMEX']);
    }
};
