<?php

namespace App\Services;

use App\Models\Cliente;
use App\Support\Documentacion;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta clientes (ya filtrados por el controller) a un .xlsx armado a mano
 * con PhpSpreadsheet, mismo criterio que `ProveedorExportService` y
 * `ObservacionExportService`: no hay `laravel-excel` instalado.
 *
 * ⚠️ **Este archivo es además la plantilla del import**: los encabezados son
 * exactamente los que busca `ClienteImportService`, así que el circuito
 * exportar → editar en Excel → volver a subir cierra sin que nadie tenga que
 * armar una planilla a mano. Cambiar un encabezado acá rompe ese circuito.
 *
 * El libro tiene dos hojas:
 *   1. **Clientes** — los datos. Es la hoja activa, que es la que lee el
 *      import (`getActiveSheet()`), así que tiene que quedar primera y activa.
 *   2. **Instructivo** — cómo completar cada columna y la tabla completa de
 *      tipos de cliente con su documentación. Se genera desde
 *      `config/documentacion.php`, no se escribe a mano: si mañana se
 *      agrega un tipo o un documento, el instructivo sale actualizado solo.
 *
 * El formato de los datos es **ancho**: dos columnas por cada documento del
 * catálogo (presentado y vencimiento), no una fila por documento. Es la forma
 * en la que ya venían las planillas de Tublood y la única que se puede
 * completar a mano sin agregar filas.
 *
 * Las columnas calculadas (completa / faltante / vencida / próximo vto / días)
 * salen para leer, no para editar: el import las ignora porque se recalculan
 * solas a partir del checklist.
 */
class ClienteExportService
{
    private const COLUMNAS_BASE = [
        'N°', 'Razón Social', 'CUIT', 'Localidad', 'Provincia', 'Mail',
        'Tipo de cliente', 'Tiene legajo', 'Habilitado', 'Observaciones',
    ];

    private const COLUMNAS_CALCULADAS = [
        'Documentación completa', 'Documentación faltante', 'Documentación vencida',
        'Próximo vencimiento', 'Días para vencer', 'Vencimiento del cliente',
    ];

    /** @param  Collection<int, Cliente>  $clientes */
    public function exportar(Collection $clientes): StreamedResponse
    {
        $documentos = Documentacion::documentosCanonicos();
        $encabezados = $this->encabezados($documentos);

        $spreadsheet = new Spreadsheet;

        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('Clientes');
        $hoja->fromArray($encabezados, null, 'A1');

        $fila = 2;
        foreach ($clientes as $cliente) {
            $hoja->fromArray($this->fila($cliente, $documentos), null, "A{$fila}");
            $fila++;
        }

        $this->formatearHojaDeDatos($hoja, $encabezados, $documentos, max($fila - 1, 2));
        $this->agregarInstructivo($spreadsheet, $documentos);

        // El import lee `getActiveSheet()`: la hoja de datos tiene que quedar
        // activa aunque el instructivo se haya agregado después.
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $nombreArchivo = 'clientes-'.now()->format('Y-m-d').'.xlsx';

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
    private function fila(Cliente $cliente, array $documentos): array
    {
        $estado = $cliente->estadoDocumentacion();
        $delTipo = Documentacion::documentos($cliente->tipo_cliente);
        $cargados = $cliente->documentos->keyBy('documento');

        $valores = [
            // Como texto: es un código, no una cantidad. Sin esto Excel se come
            // los ceros a la izquierda.
            (string) $cliente->numero,
            $cliente->razon_social,
            $cliente->cuit ?? '',
            $cliente->localidad ?? '',
            $cliente->descripcion_provincia ?? '',
            $cliente->mail_nuevo ?: ($cliente->mail ?? ''),
            Documentacion::etiqueta($cliente->tipo_cliente) ?? '',
            $this->siNo($cliente->tiene_legajo),
            $this->siNo($cliente->habilitado),
            $cliente->notas ?? '',
        ];

        foreach ($documentos as $clave => $label) {
            $pedido = isset($delTipo[$clave]);
            $doc = $cargados->get($clave);

            // Un documento que el tipo del cliente no pide queda en blanco, no
            // en "NO": no es que falte, es que no corresponde.
            $valores[] = $pedido ? $this->siNo((bool) $doc?->presentado) : '';
            $valores[] = $pedido && ! empty($delTipo[$clave]['vence'])
                ? ($doc?->fecha_vencimiento?->format('d/m/Y') ?? '')
                : '';
        }

        return array_merge($valores, [
            $cliente->tipo_cliente === null ? '' : $this->siNo($estado['completa']),
            implode(', ', $estado['faltantes']),
            implode(', ', array_column($estado['vencidos'], 'label')),
            $estado['proximo_vencimiento'] ? date('d/m/Y', strtotime($estado['proximo_vencimiento'])) : '',
            $estado['dias_para_vencer'] ?? '',
            $cliente->fecha_vencimiento?->format('d/m/Y') ?? '',
        ]);
    }

    /**
     * Encabezado en negrita, anchos automáticos y listas desplegables en las
     * columnas que se completan a mano.
     *
     * Las listas son la mitad del instructivo: el import ignora en silencio un
     * valor que no entiende ("Si señor" en vez de "SÍ"), así que es mejor que
     * Excel no deje escribirlo.
     *
     * @param  array<string, string>  $documentos
     */
    private function formatearHojaDeDatos(Worksheet $hoja, array $encabezados, array $documentos, int $ultimaFila): void
    {
        $ultimaColumna = Coordinate::stringFromColumnIndex(count($encabezados));

        $hoja->getStyle("A1:{$ultimaColumna}1")->getFont()->setBold(true);
        $hoja->freezePane('B2');

        foreach (range(1, count($encabezados)) as $indice) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice))->setAutoSize(true);
        }

        // Tipo de cliente, Tiene legajo y Habilitado son las columnas 7, 8 y 9.
        $this->desplegable($hoja, 'G', $ultimaFila, array_values(Documentacion::etiquetasTipos()));
        $this->desplegable($hoja, 'H', $ultimaFila, ['SÍ', 'NO']);
        $this->desplegable($hoja, 'I', $ultimaFila, ['SÍ', 'NO']);

        // Y una por cada columna de "presentado" (la del vencimiento, la
        // siguiente, va libre: es una fecha).
        $columna = count(self::COLUMNAS_BASE) + 1;
        for ($i = 0; $i < count($documentos); $i++) {
            $this->desplegable($hoja, Coordinate::stringFromColumnIndex($columna), $ultimaFila, ['SÍ', 'NO']);
            $columna += 2;
        }
    }

    /**
     * Lista desplegable sobre toda una columna de datos.
     *
     * `setShowDropDown(true)` y no `setAllowBlank(false)`: una celda vacía es un
     * valor válido a propósito — significa "no cambiar nada" para el import.
     */
    private function desplegable(Worksheet $hoja, string $columna, int $ultimaFila, array $opciones): void
    {
        $validacion = new DataValidation;
        $validacion->setType(DataValidation::TYPE_LIST);
        $validacion->setErrorStyle(DataValidation::STYLE_STOP);
        $validacion->setAllowBlank(true);
        $validacion->setShowDropDown(true);
        $validacion->setShowErrorMessage(true);
        $validacion->setErrorTitle('Valor no válido');
        $validacion->setError('Elegí uno de los valores de la lista. Ver la hoja "Instructivo".');
        $validacion->setFormula1('"'.implode(',', $opciones).'"');

        $hoja->setDataValidation("{$columna}2:{$columna}{$ultimaFila}", $validacion);
    }

    /**
     * Hoja "Instructivo": cómo completar cada columna y qué documentación pide
     * cada tipo de cliente.
     *
     * Se arma desde el catálogo (`config/documentacion.php`), nunca a
     * mano: un tipo o un documento nuevo aparece acá solo, sin que nadie se
     * acuerde de actualizar un texto.
     *
     * @param  array<string, string>  $documentos
     */
    private function agregarInstructivo(Spreadsheet $spreadsheet, array $documentos): void
    {
        $hoja = $spreadsheet->createSheet();
        $hoja->setTitle('Instructivo');

        $fila = 1;

        $titulo = function (string $texto) use ($hoja, &$fila) {
            $hoja->setCellValue("A{$fila}", $texto);
            $hoja->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(13);
            $fila += 2;
        };

        $encabezadoTabla = function (array $columnas) use ($hoja, &$fila) {
            $hoja->fromArray($columnas, null, "A{$fila}");
            $ultima = Coordinate::stringFromColumnIndex(count($columnas));
            $hoja->getStyle("A{$fila}:{$ultima}{$fila}")->getFont()->setBold(true);
            $fila++;
        };

        $hoja->setCellValue('A1', 'INSTRUCTIVO — Carga masiva de clientes por Excel');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $fila = 3;

        $hoja->setCellValue("A{$fila}", 'Completá la hoja "Clientes" de este mismo archivo y volvé a subirla desde el botón "Importar Excel". Las columnas se buscan por el nombre del encabezado, así que podés borrar las que no vayas a usar y las filas de los clientes que no vayas a tocar.');
        $hoja->getStyle("A{$fila}")->getAlignment()->setWrapText(true);
        $hoja->mergeCells("A{$fila}:D{$fila}");
        $hoja->getRowDimension($fila)->setRowHeight(32);
        $fila += 2;

        $titulo('1. Reglas generales');

        foreach ([
            'No cambies los nombres de la fila 1: son los que identifican cada columna.',
            'Una celda vacía significa "no cambiar": deja lo que ya estaba cargado. Para dar de baja un documento hay que escribir NO.',
            'Un N° de cliente que no exista en el sistema NO se crea: los clientes vienen de RP Sistemas. Se avisa al terminar la importación.',
            'Las columnas de Sí/No y la de Tipo de cliente tienen lista desplegable en la hoja "Clientes": elegí de ahí en vez de tipear.',
            'Las fechas van en formato dd/mm/aaaa (por ejemplo 10/03/2027).',
        ] as $regla) {
            $hoja->setCellValue("A{$fila}", '•  '.$regla);
            $fila++;
        }

        $fila++;
        $titulo('2. Qué se carga en cada columna');

        $encabezadoTabla(['Columna', 'Qué se carga', 'Ejemplo']);

        $primerDocumento = reset($documentos) ?: 'Documento';

        foreach ([
            ['N°', 'Identifica al cliente; es lo único obligatorio de cada fila. No lo edites.', '1066'],
            ['Razón Social, CUIT, Localidad, Provincia, Mail', 'Solo para que reconozcas al cliente. Vienen de RP Sistemas y el sistema los ignora al importar: editarlos no hace nada.', '—'],
            ['Tipo de cliente', 'Uno de los tipos del punto 3. Es lo que define qué documentación se le exige.', 'Importador'],
            ['Tiene legajo', 'SÍ o NO.', 'SÍ'],
            ['Habilitado', 'SÍ o NO.', 'NO'],
            ['Observaciones', 'Texto libre.', 'Pidió prórroga por el BPF.'],
            [$primerDocumento.', y una columna por cada documento', 'SÍ si el cliente ya lo presentó, NO si no. Dejalo vacío si no querés cambiarlo. Un documento que el tipo de cliente no pide se ignora (y se avisa).', 'SÍ'],
            [$primerDocumento.' - Vto, y su par por cada documento', 'Fecha de vencimiento del documento, en dd/mm/aaaa. Solo tiene efecto si el documento está en SÍ y si vence para ese tipo de cliente (ver el punto 3).', '10/03/2027'],
            ['Las últimas 6 columnas', 'Documentación completa, faltante, vencida, próximo vencimiento, días para vencer y vencimiento del cliente. Las calcula el sistema solo: podés leerlas, pero editarlas no hace nada.', '—'],
        ] as $columnaDoc) {
            $hoja->fromArray($columnaDoc, null, "A{$fila}");
            $hoja->getStyle("B{$fila}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $hoja->getStyle("A{$fila}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $hoja->getRowDimension($fila)->setRowHeight(-1);
            $fila++;
        }

        $fila += 2;
        $titulo('3. Tipos de cliente y documentación que pide cada uno');

        $hoja->setCellValue("A{$fila}", 'El vencimiento del cliente sale del documento marcado en la última columna. Un tipo sin ninguno marcado no vence nunca.');
        $fila += 2;

        $encabezadoTabla(['Tipo de cliente', 'Documento', '¿Obligatorio?', '¿Tiene vencimiento?', '¿Determina el vencimiento del cliente?']);

        foreach (Documentacion::tipos() as $slug => $tipo) {
            $primera = true;

            foreach (Documentacion::documentos($slug) as $def) {
                $hoja->fromArray([
                    $primera ? $tipo['label'] : '',
                    $def['label'],
                    $def['obligatorio'] ? 'Obligatorio' : 'Opcional',
                    $def['vence'] ? 'Sí, cargar fecha' : 'No',
                    ! empty($def['determina_vencimiento']) ? 'SÍ' : '',
                ], null, "A{$fila}");

                if ($primera) {
                    $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
                }

                $primera = false;
                $fila++;
            }

            // Fila en blanco entre tipos: la tabla real tiene 40 filas y sin el
            // corte no se distingue dónde empieza cada uno.
            $fila++;
        }

        $hoja->getColumnDimension('A')->setWidth(42);
        $hoja->getColumnDimension('B')->setWidth(78);
        $hoja->getColumnDimension('C')->setWidth(24);
        $hoja->getColumnDimension('D')->setWidth(24);
        $hoja->getColumnDimension('E')->setWidth(38);
    }

    private function siNo(bool $valor): string
    {
        return $valor ? 'SÍ' : 'NO';
    }
}
