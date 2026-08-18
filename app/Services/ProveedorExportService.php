<?php

namespace App\Services;

use App\Models\Proveedor;
use App\Support\Documentacion;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta proveedores (ya filtrados por el controller) a un .xlsx armado a mano
 * con PhpSpreadsheet, mismo criterio que `ObservacionExportService`: no hay
 * `laravel-excel` instalado.
 *
 * Además del padrón sale la clasificación documental, con el mismo formato
 * **ancho** que `ClienteExportService`: dos columnas por documento del catálogo
 * (el nombre canónico para el SÍ/NO y `"<nombre> - Vto"` para la fecha), más
 * las calculadas al final. Los encabezados son deliberadamente los mismos que
 * los del Excel de clientes: es el mismo catálogo compartido.
 */
class ProveedorExportService
{
    private const COLUMNAS_BASE = [
        'N°', 'Razón Social', 'Nombre Fantasía', 'CUIT', 'Domicilio',
        'Localidad', 'Provincia', 'CP', 'Teléfono', 'Celular', 'Mail',
        'Contacto', 'Estado', 'Observaciones',
        'Tipo de proveedor', 'Tiene legajo', 'Habilitado',
    ];

    private const COLUMNAS_CALCULADAS = [
        'Documentación completa', 'Documentación faltante', 'Documentación vencida',
        'Próximo vencimiento', 'Días para vencer', 'Vencimiento del proveedor',
    ];

    /** @param  Collection<int, Proveedor>  $proveedores */
    public function exportar(Collection $proveedores): StreamedResponse
    {
        $documentos = Documentacion::documentosCanonicos();
        $encabezados = $this->encabezados($documentos);

        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Proveedores');
        $hoja->fromArray($encabezados, null, 'A1');
        $hoja->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($encabezados)).'1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($proveedores as $proveedor) {
            $hoja->fromArray($this->fila($proveedor, $documentos), null, "A{$fila}");
            $fila++;
        }

        foreach (range(1, count($encabezados)) as $indice) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $nombreArchivo = 'proveedores-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @param  array<string, string>  $documentos */
    private function encabezados(array $documentos): array
    {
        $columnas = self::COLUMNAS_BASE;

        foreach ($documentos as $label) {
            $columnas[] = $label;
            $columnas[] = "{$label} - Vto";
        }

        return array_merge($columnas, self::COLUMNAS_CALCULADAS);
    }

    /** @param  array<string, string>  $documentos */
    private function fila(Proveedor $proveedor, array $documentos): array
    {
        $estado = $proveedor->estadoDocumentacion();
        $delTipo = Documentacion::documentos($proveedor->tipo_proveedor);
        $cargados = $proveedor->documentos->keyBy('documento');

        $valores = [
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
            Documentacion::etiqueta($proveedor->tipo_proveedor) ?? '',
            $this->siNo($proveedor->tiene_legajo),
            $this->siNo($proveedor->habilitado),
        ];

        foreach ($documentos as $clave => $label) {
            $pedido = isset($delTipo[$clave]);
            $doc = $cargados->get($clave);

            // Un documento que el tipo del proveedor no pide queda en blanco, no
            // en "NO": no es que falte, es que no corresponde.
            $valores[] = $pedido ? $this->siNo((bool) $doc?->presentado) : '';
            $valores[] = $pedido && ! empty($delTipo[$clave]['vence'])
                ? ($doc?->fecha_vencimiento?->format('d/m/Y') ?? '')
                : '';
        }

        return array_merge($valores, [
            $proveedor->tipo_proveedor === null ? '' : $this->siNo($estado['completa']),
            implode(', ', $estado['faltantes']),
            implode(', ', array_column($estado['vencidos'], 'label')),
            $estado['proximo_vencimiento'] ? date('d/m/Y', strtotime($estado['proximo_vencimiento'])) : '',
            $estado['dias_para_vencer'] ?? '',
            $proveedor->fecha_vencimiento?->format('d/m/Y') ?? '',
        ]);
    }

    private function siNo(bool $valor): string
    {
        return $valor ? 'SÍ' : 'NO';
    }
}
