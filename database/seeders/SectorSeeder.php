<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        // dias_gestion: plazo real del Excel para los sectores que lo traen;
        // null para los que no tienen usuarios cargados con ese sector todavía.
        $sectores = [
            'facturacion' => ['nombre' => 'Facturación', 'dias_gestion' => 2],
            'logistica' => ['nombre' => 'Logística', 'dias_gestion' => 5],
            'deposito' => ['nombre' => 'Depósito', 'dias_gestion' => 3],
            'comercial' => ['nombre' => 'Comercial', 'dias_gestion' => 2],
            'comex' => ['nombre' => 'Compras', 'dias_gestion' => null],
            'finanzas' => ['nombre' => 'Finanzas', 'dias_gestion' => null],
            'asuntos_regulatorios' => ['nombre' => 'Asuntos Regulatorios', 'dias_gestion' => 5],
            'garantia_calidad' => ['nombre' => 'Calidad', 'dias_gestion' => 3],
            'direccion_tecnica' => ['nombre' => 'Dirección Técnica', 'dias_gestion' => null],
            // Producción es **un solo sector**; adentro se divide en dos líneas
            // (Tubos y Apósitos), que son subgrupos de tipos de incidencia y no
            // sectores aparte — ver `grupo` en config/incidencias.php.
            //
            // Como el slug es `produccion`, el Excel de usuarios que trae
            // `PRODUCCIÓN` ahora matchea solo: esa gente dejaba de quedar sin
            // sector, que era un agujero conocido del import.
            'produccion' => ['nombre' => 'Producción', 'dias_gestion' => null],
            // Fallos de terceros (transporte, proveedor, fabricante): el sector
            // 11, desde el 6/11/2026. No son gente de Tublood, así que no hay
            // usuarios ni plazo propio. Ver `externos` en config/incidencias.php.
            'externos' => ['nombre' => 'Externos', 'dias_gestion' => null],
        ];

        foreach ($sectores as $slug => $datos) {
            Sector::firstOrCreate(['slug' => $slug], $datos);
        }
    }
}
