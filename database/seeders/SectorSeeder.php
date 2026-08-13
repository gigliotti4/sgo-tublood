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
            'asuntos_regulatorios' => ['nombre' => 'Asuntos Regulatorios', 'dias_gestion' => 5],
            'garantia_calidad' => ['nombre' => 'Calidad', 'dias_gestion' => 3],
            'direccion_tecnica' => ['nombre' => 'Dirección Técnica', 'dias_gestion' => null],
        ];

        foreach ($sectores as $slug => $datos) {
            Sector::firstOrCreate(['slug' => $slug], $datos);
        }
    }
}
