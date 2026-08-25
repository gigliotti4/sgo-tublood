<?php

namespace App\Services;

use App\Models\Articulo;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta artículos (ya filtrados por el controller) a un .xlsx armado a mano
 * con PhpSpreadsheet, mismo criterio que `ClienteExportService` y
 * `ProveedorExportService`: no hay `laravel-excel` instalado.
 *
 * Los encabezados de PM, Legajo, Observaciones y Fecha de vencimiento
 * coinciden a propósito con lo que busca `ArticuloImportService` (que mapea
 * por nombre de columna en vez de por posición), así que el archivo que baja
 * de acá se puede editar y volver a subir sin tener que rearmarlo.
 *
 * Las columnas **Estado** y **Origen** son el motivo puntual de este export:
 * separan "activo/discontinuado según el último sync de RP" de "nunca vino
 * de RP" (los artículos cargados por Excel de Calidad con "crear faltantes"),
 * que son cosas distintas y hoy se confundían mirando solo la lista en
 * pantalla — ver `ArticuloSyncService` para la regla completa.
 */
class ArticuloExportService
{
    private const COLUMNAS = [
        'Código', 'Descripción', 'Descripción adicional', 'Código de barras',
        'UM', 'Agrupación 1', 'Agrupación 2', 'Agrupación 3',
        'Stock', 'Stock disponible',
        'Código proveedor (ERP)', 'Proveedor',
        'PM', 'Legajo', 'Fecha de vencimiento', 'Observaciones', 'Link de registro',
        'Estado', 'Origen', 'Última sincronización',
    ];

    /** @param  Collection<int, Articulo>  $articulos */
    public function exportar(Collection $articulos): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Artículos');
        $hoja->fromArray(self::COLUMNAS, null, 'A1');
        $hoja->getStyle('A1:'.Coordinate::stringFromColumnIndex(count(self::COLUMNAS)).'1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($articulos as $articulo) {
            $hoja->fromArray($this->fila($articulo), null, "A{$fila}");
            $fila++;
        }

        foreach (range(1, count(self::COLUMNAS)) as $indice) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $nombreArchivo = 'articulos-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function fila(Articulo $articulo): array
    {
        return [
            // Como texto: el código no es una cantidad, y algunos empiezan
            // con cero. Sin esto Excel se los come.
            (string) $articulo->codigo,
            $articulo->descripcion,
            $articulo->descripcion_adicional ?? '',
            $articulo->codigo_barras ?? '',
            $articulo->unidad_medida ?? '',
            $articulo->descripcion_agrupacion_1 ?? '',
            $articulo->descripcion_agrupacion_2 ?? '',
            $articulo->descripcion_agrupacion_3 ?? '',
            $articulo->stock ?? '',
            $articulo->stock_disponible ?? '',
            $articulo->codigo_proveedor ?? '',
            $articulo->proveedor?->razon_social ?? '',
            $articulo->pm ?? '',
            $articulo->legajo ?? '',
            $articulo->fecha_vencimiento?->format('d/m/Y') ?? '',
            $articulo->observaciones ?? '',
            $articulo->link_registro ?? '',
            $articulo->activo ? 'Activo' : 'Discontinuado',
            // No es lo mismo que "Estado": un artículo puede ser "Activo" y
            // "Carga manual" a la vez (nunca vino de RP, así que nunca pudo
            // "dejar de venir" — ver ArticuloSyncService).
            $articulo->synced_at ? 'RP Sistemas' : 'Carga manual (Excel)',
            $articulo->synced_at?->format('d/m/Y H:i') ?? '',
        ];
    }
}
