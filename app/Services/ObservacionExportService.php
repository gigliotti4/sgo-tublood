<?php

namespace App\Services;

use App\Models\Observacion;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta observaciones (ya filtradas por el controller) a un .xlsx armado a
 * mano con PhpSpreadsheet — no hay `laravel-excel` instalado, y el hosting
 * compartido no tiene Node, mismo motivo por el que el PDF de detalle usa
 * DomPDF en vez de Browsershot (ver ObservacionController::pdf()).
 */
class ObservacionExportService
{
    private const COLUMNAS = [
        'N°', 'Tipo', 'Origen', 'Título', 'Cliente', 'Sector',
        'Responsable', 'Creado por', 'Prioridad', 'Estado', 'Creada', 'Vence',
        'Proveedores',
    ];

    /** @param  Collection<int, Observacion>  $observaciones */
    public function exportar(Collection $observaciones): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Observaciones');
        $hoja->fromArray(self::COLUMNAS, null, 'A1');

        $prioridades = config('incidencias.prioridades');

        $fila = 2;
        foreach ($observaciones as $observacion) {
            $hoja->fromArray([
                $observacion->numero,
                $observacion->tipo,
                Observacion::ORIGENES[$observacion->origen] ?? $observacion->origen,
                $observacion->titulo,
                $observacion->cliente?->razon_social ?? $observacion->contacto_nombre,
                $observacion->sector?->nombre ?? '',
                $observacion->responsable?->name ?? '',
                $observacion->creador?->name ?? '',
                $observacion->prioridad ? ($prioridades[$observacion->prioridad] ?? $observacion->prioridad) : '',
                Observacion::ESTADOS[$observacion->estado] ?? $observacion->estado,
                $observacion->created_at?->format('d/m/Y'),
                $observacion->vence_at?->format('d/m/Y'),
                $this->proveedores($observacion),
            ], null, "A{$fila}");
            $fila++;
        }

        foreach (range('A', 'M') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $nombreArchivo = 'observaciones-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Los proveedores de los productos afectados, sin repetir.
     *
     * El Excel es una fila por observación y no por renglón, así que un caso
     * con varios productos del mismo proveedor lo nombra una sola vez. Un
     * producto cuyo código no matchea ningún artículo, o cuyo artículo todavía
     * no tiene proveedor cargado, simplemente no aporta nada.
     */
    private function proveedores(Observacion $observacion): string
    {
        return $observacion->productos
            ->map(fn ($producto) => $producto->articulo?->proveedor?->razon_social)
            ->filter()
            ->unique()
            ->implode(', ');
    }
}
