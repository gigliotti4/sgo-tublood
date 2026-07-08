<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        $sectores = [
            'facturacion' => 'Facturación',
            'logistica' => 'Logística',
            'deposito' => 'Depósito',
            'comercial' => 'Comercial',
            'comex' => 'COMEX',
            'asuntos_regulatorios' => 'Asuntos Regulatorios',
            'garantia_calidad' => 'Garantía de Calidad',
            'direccion_tecnica' => 'Dirección Técnica',
        ];

        foreach ($sectores as $slug => $nombre) {
            Sector::firstOrCreate(['slug' => $slug], ['nombre' => $nombre]);
        }
    }
}
