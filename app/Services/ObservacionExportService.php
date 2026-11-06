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

    /**
     * @param  Collection<int, Observacion>  $observaciones
     * @param  bool  $conResponsableFallo  Agrega la columna "Responsable del fallo" al final.
     *                                     ⚠️ Solo para quien puede verlo (jefes y Calidad): para
     *                                     los demás la columna no existe, no va vacía.
     * @param  array<string, string>|null  $corte  Etiqueta => valor. Si viene, el libro lleva una
     *                                             segunda hoja "Corte" con la fecha de corte y los
     *                                             filtros de la vista exportada: sin eso un Excel del
     *                                             tablero no dice de cuándo ni de qué es.
     */
    public function exportar(Collection $observaciones, bool $conResponsableFallo = false, ?array $corte = null): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Observaciones');
        $hoja->fromArray([...self::COLUMNAS, ...($conResponsableFallo ? ['Responsable del fallo'] : [])], null, 'A1');

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
                // "Clasificada" no dice nada de la urgencia: en ese estado la
                // columna muestra la prioridad — ver Observacion::etiquetaEstado().
                $observacion->etiquetaEstado(),
                $observacion->created_at?->format('d/m/Y'),
                $observacion->vencimiento()?->format('d/m/Y'),
                $this->proveedores($observacion),
                ...($conResponsableFallo ? [$observacion->responsableFallo?->nombre ?? ''] : []),
            ], null, "A{$fila}");
            $fila++;
        }

        foreach (range('A', $conResponsableFallo ? 'N' : 'M') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }

        if ($corte !== null) {
            $hojaCorte = $spreadsheet->createSheet();
            $hojaCorte->setTitle('Corte');
            $hojaCorte->fromArray([['Dato', 'Valor'], ...collect($corte)->map(fn ($v, $k) => [$k, $v])->values()->all()], null, 'A1');
            $hojaCorte->getStyle('A1:B1')->getFont()->setBold(true);
            $hojaCorte->getColumnDimension('A')->setAutoSize(true);
            $hojaCorte->getColumnDimension('B')->setAutoSize(true);
            // La de observaciones tiene que quedar primera y activa.
            $spreadsheet->setActiveSheetIndex(0);
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
