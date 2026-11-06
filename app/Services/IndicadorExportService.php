<?php

namespace App\Services;

use App\Models\Indicador;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Baja los objetivos vigentes en el mismo formato de la planilla "OBJETIVOS 2026".
 *
 * ⚠️ **Lo que se baja se puede volver a subir**: los encabezados son exactamente
 * los que busca `IndicadorImportService`, y la hoja se llama igual. Cambiar uno
 * rompe ese circuito. Es el mismo contrato que `ClienteExportService`.
 *
 * Los meses (SEP-26…) llevan solo los valores cargados a mano; los indicadores del
 * SGO los cuenta el tablero de las observaciones, así que van vacíos.
 */
class IndicadorExportService
{
    private const NOMBRES_MES = [1 => 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC'];

    private const ENCABEZADOS = [
        'SECTOR', 'FALLO', 'FUENTE / TIPO SGO', 'META', 'META MEDICIÓN', 'CANT. DE PEDIDOS',
        'OBJETIVO MENSUAL', 'TOLERANCIA MÁXIMA', 'TIPO DE META', 'INDICADOR', 'ESTADO DEL VALOR', '% DE IMPORTANCIA',
    ];

    /** @param  Collection<int, Indicador>  $indicadores */
    public function exportar(Collection $indicadores): StreamedResponse
    {
        $meses = $indicadores->flatMap(fn ($i) => $i->valores->pluck('mes'))->unique()->sort()->values();

        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('OBJETIVOS 2026');

        $encabezados = [...self::ENCABEZADOS, ...$meses->map(fn ($m) => $this->encabezadoDeMes($m))];
        $hoja->fromArray($encabezados, null, 'A1');
        $hoja->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($encabezados)).'1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($indicadores as $i) {
            $valores = $i->valores->pluck('valor', 'mes');

            $hoja->fromArray([
                $i->sector, $i->fallo, $i->fuente, $i->meta, $i->meta_medicion, $i->cant_pedidos,
                $i->objetivo, $i->tolerancia, Indicador::TIPOS_META[$i->tipo_meta] ?? $i->tipo_meta,
                $i->descripcion, Indicador::ESTADOS[$i->estado] ?? $i->estado, $i->importancia,
                ...$meses->map(fn ($m) => $valores[$m] ?? null),
            ], null, "A{$fila}");
            $fila++;
        }

        foreach (range(1, count($encabezados)) as $c) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        // La de datos tiene que quedar primera y activa: es la que busca el import.
        $libro->setActiveSheetIndex(0);

        $writer = new Xlsx($libro);

        return response()->streamDownload(fn () => $writer->save('php://output'), 'objetivos-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** 2026-09 → SEP-26 */
    private function encabezadoDeMes(string $mes): string
    {
        [$anio, $m] = explode('-', $mes);

        return self::NOMBRES_MES[(int) $m].'-'.substr($anio, 2);
    }
}
