<?php

namespace App\Services;

use App\Models\Proveedor;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta proveedores (ya filtrados por el controller) a un .xlsx armado a mano
 * con PhpSpreadsheet, mismo criterio que `ObservacionExportService`: no hay
 * `laravel-excel` instalado.
 */
class ProveedorExportService
{
    private const COLUMNAS = [
        'N°', 'Razón Social', 'Nombre Fantasía', 'CUIT', 'Domicilio',
        'Localidad', 'Provincia', 'CP', 'Teléfono', 'Celular', 'Mail',
        'Contacto', 'Estado', 'Observaciones',
    ];

    /** @param  Collection<int, Proveedor>  $proveedores */
    public function exportar(Collection $proveedores): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Proveedores');
        $hoja->fromArray(self::COLUMNAS, null, 'A1');

        $fila = 2;
        foreach ($proveedores as $proveedor) {
            $hoja->fromArray([
                // Como texto: son códigos, no cantidades. Sin esto Excel se come
                // los ceros a la izquierda si algún día aparecen.
                (string) ($proveedor->numero ?? 'sin número'),
                $proveedor->razon_social,
                $proveedor->nombre_fantasia ?? '',
                $proveedor->cuit ?? '',
                $proveedor->domicilio ?? '',
                $proveedor->localidad ?? '',
                $proveedor->provincia ?? '',
                $proveedor->codigo_postal ?? '',
                $proveedor->telefono ?? '',
                $proveedor->celular ?? '',
                $proveedor->mail ?? '',
                $proveedor->contacto ?? '',
                $proveedor->estado ?? '',
                $proveedor->observaciones ?? '',
            ], null, "A{$fila}");
            $fila++;
        }

        foreach (range('A', 'N') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $nombreArchivo = 'proveedores-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
