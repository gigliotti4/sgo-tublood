<?php

namespace App\Services\Compras;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Los productos que no cubren stock, para el mail mensual de Compras.
 *
 * Toma el mismo dataset que el tablero y le aplica los filtros de
 * `config('compras.alerta')` con `CalculoReposicion`, la copia en PHP del
 * cálculo del front.
 */
class AlertaCobertura
{
    private const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public function __construct(
        private ReposicionService $reposicion,
        private CalculoReposicion $calculo,
    ) {}

    /**
     * @return array{filas: list<array<string, mixed>>, periodo: string, filtros: array<string, mixed>}
     */
    public function calcular(): array
    {
        $dataset = $this->reposicion->dataset();
        $meses = $dataset['meses'];
        $config = config('compras.alerta');

        [$desde, $hasta] = $this->periodo($meses, (int) $config['ultimos_meses']);

        $filtros = CalculoReposicion::filtros([
            'mesesObjetivo' => (float) $config['meses_objetivo'],
            'desde' => $desde,
            'hasta' => $hasta,
            'cats' => $config['categorias'],
            'activo' => $config['activo'],
            'stock' => $config['stock'],
            'cubre' => $config['cubre'],
            'pareto' => $config['pareto'],
            'depositos' => $config['depositos'],
            'agrupar' => (bool) $config['agrupar'],
        ]);

        $filas = $meses === [] ? [] : $this->calculo->filtrar($dataset['groups'], $filtros, count($meses));

        // Lo más urgente arriba: es el orden en que se lee un mail.
        usort($filas, fn ($a, $b) => $b['cantComprar'] <=> $a['cantComprar']);

        return [
            'filas' => $filas,
            'periodo' => $meses === [] ? '' : $this->etiquetaMes($meses[$desde]).' – '.$this->etiquetaMes($meses[$hasta]),
            'filtros' => $filtros,
        ];
    }

    /**
     * Índices del período: los últimos N meses CERRADOS.
     *
     * Si el último mes del historial es el mes en curso, queda afuera. Ver la
     * nota en config/compras.php.
     *
     * @param  list<string>  $meses
     * @return array{0: int, 1: int}
     */
    public function periodo(array $meses, int $cantidad): array
    {
        $hasta = count($meses) - 1;

        if ($hasta > 0 && $meses[$hasta] >= now()->format('Y-m')) {
            $hasta--;
        }

        $hasta = max(0, $hasta);

        return [max(0, $hasta - max(1, $cantidad) + 1), $hasta];
    }

    /**
     * El detalle en .xlsx, guardado en el disco privado. Devuelve el path
     * relativo al disco `local`.
     *
     * Se guarda en vez de mandarse en memoria porque el mail va encolado: el
     * worker lo adjunta después, leyéndolo de acá. Queda además como registro
     * de qué se avisó cada mes.
     *
     * @param  list<array<string, mixed>>  $filas
     */
    public function excel(array $filas, string $periodo): string
    {
        $columnas = [
            'Producto', 'Códigos', 'Categoría', 'Proveedor', 'Stock (u.)', 'Reservado (u.)',
            'OC pend. (u.)', 'Próxima entrega', "Prom. mensual (u.) {$periodo}", 'Prom. estimado a mano',
            'Meses que cubre', 'Cant. a comprar (u.)',
        ];

        $categorias = config('compras.categorias');

        $spreadsheet = new Spreadsheet;
        $hoja = $spreadsheet->getActiveSheet();
        $hoja->setTitle('A comprar');
        $hoja->fromArray($columnas, null, 'A1');
        $hoja->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($columnas)).'1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($filas as $r) {
            $hoja->fromArray([
                $r['grupo']['n'],
                // Como texto: hay códigos que empiezan con cero.
                implode(', ', array_map(fn ($it) => (string) $it['c'], $r['items'])),
                implode(' / ', array_map(fn ($c) => $categorias[$c] ?? $c, $r['grupo']['c'])),
                implode(' / ', $r['proveedores']),
                round($r['stockU']),
                round($r['reservado']),
                round($r['ocPend']),
                $r['proximaEntrega'] === null ? '' : date('d/m/Y', strtotime($r['proximaEntrega'])),
                round($r['promMensual'], 1),
                $r['estimada'] ? 'SI' : '',
                is_infinite($r['meses']) ? '' : round($r['meses'], 1),
                round($r['cantComprar'], 1),
            ], null, "A{$fila}", true);
            $fila++;
        }

        foreach (range(1, count($columnas)) as $indice) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice))->setAutoSize(true);
        }

        // Enteros con separador de miles y un decimal donde importa: sin formato,
        // Excel muestra 48266,6999999 por cómo se guarda un float.
        $ultima = max(2, $fila - 1);
        $hoja->getStyle("E2:G{$ultima}")->getNumberFormat()->setFormatCode('#,##0');
        $hoja->getStyle("I2:I{$ultima}")->getNumberFormat()->setFormatCode('#,##0.0');
        $hoja->getStyle("K2:L{$ultima}")->getNumberFormat()->setFormatCode('#,##0.0');

        $path = 'compras/alertas/a-comprar-'.now()->format('Y-m-d').'.xlsx';
        $completo = Storage::disk('local')->path($path);

        if (! is_dir(dirname($completo))) {
            mkdir(dirname($completo), 0775, true);
        }

        (new Xlsx($spreadsheet))->save($completo);

        return $path;
    }

    /**
     * Los filtros de la alerta en palabras, para el pie del mail. Sale de la
     * config y no se escribe a mano: si alguien cambia un filtro, el mail no
     * puede seguir diciendo el viejo.
     */
    public function criterio(): string
    {
        $c = config('compras.alerta');
        $categorias = config('compras.categorias');

        $objetivo = (float) $c['meses_objetivo'];
        $partes = [
            'objetivo de '.str_replace('.', ',', (string) $objetivo).($objetivo == 1 ? ' mes' : ' meses'),
            "últimos {$c['ultimos_meses']} meses cerrados",
            $c['categorias'] === [] ? 'todas las categorías' : implode(', ', array_map(fn ($k) => $categorias[$k] ?? $k, $c['categorias'])),
        ];

        if ($c['activo'] === 'si') {
            $partes[] = 'solo productos activos';
        }
        if ($c['depositos'] !== []) {
            $partes[] = 'depósitos '.implode(', ', $c['depositos']);
        }
        if (! $c['agrupar']) {
            $partes[] = 'sin unificar por GTIN';
        }

        return implode(' · ', $partes);
    }

    private function etiquetaMes(string $mes): string
    {
        [$anio, $numero] = explode('-', $mes);

        return self::MESES[(int) $numero - 1].' '.substr($anio, 2);
    }
}
