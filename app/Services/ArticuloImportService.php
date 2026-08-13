<?php

namespace App\Services;

use App\Models\Articulo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Carga masiva de los cuatro campos propios de `articulos` (fecha de
 * vencimiento, PM, legajo y observaciones) desde la planilla suelta que hoy
 * lleva Calidad a mano.
 *
 * A diferencia de `UserImportService`, el mapeo va **por nombre de columna y
 * no por posición**: en la planilla real las columnas vienen salteadas (A, B,
 * E, F, I) y cualquiera puede agregar una columna nueva y correr el resto.
 *
 * Solo actualiza artículos que ya existen en el catálogo (sincronizado del
 * ERP): un código que no matchea se saltea con advertencia en vez de crear un
 * artículo fantasma sin stock ni agrupaciones que la sync nunca va a reconocer.
 */
class ArticuloImportService
{
    private array $advertencias = [];

    /** @return array{actualizados: int, advertencias: array<int, string>} */
    public function import(UploadedFile $file): array
    {
        $this->advertencias = [];
        $actualizados = 0;

        $filas = IOFactory::load($file->getRealPath())
            ->getActiveSheet()
            ->toArray();

        $indices = $this->indicesDeColumnas($filas[0] ?? []);

        if ($indices['codigo'] === null) {
            throw new \InvalidArgumentException(
                'El archivo no tiene una columna de código de artículo (se esperaba algo como "cod_articulo").'
            );
        }

        foreach (array_slice($filas, 1) as $index => $fila) {
            $numeroFila = $index + 2;

            $codigo = $this->limpiar($fila[$indices['codigo']] ?? null);

            if ($codigo === null) {
                continue;
            }

            $articulo = Articulo::where('codigo', $codigo)->first();

            if (! $articulo) {
                $this->advertencias[] = "Fila {$numeroFila}: el código '{$codigo}' no está en el catálogo, se omitió.";

                continue;
            }

            [$pm, $legajo] = $this->pmYLegajo(
                $indices['pm'] !== null ? $this->limpiar($fila[$indices['pm']] ?? null) : null,
                $indices['legajo'] !== null ? $this->limpiar($fila[$indices['legajo']] ?? null) : null,
            );

            $articulo->update([
                'fecha_vencimiento' => $indices['fecha_vencimiento'] !== null
                    ? $this->fecha($fila[$indices['fecha_vencimiento']] ?? null)
                    : $articulo->fecha_vencimiento,
                'pm' => $pm ?? $articulo->pm,
                'legajo' => $legajo ?? $articulo->legajo,
                'observaciones' => $indices['observaciones'] !== null
                    ? ($this->limpiar($fila[$indices['observaciones']] ?? null) ?? $articulo->observaciones)
                    : $articulo->observaciones,
            ]);

            $actualizados++;
        }

        return [
            'actualizados' => $actualizados,
            'advertencias' => $this->advertencias,
        ];
    }

    /**
     * Ubica cada columna por su encabezado normalizado (sin acentos, en
     * minúscula), no por posición. Devuelve el índice de la primera columna
     * que contenga alguna de las palabras clave del campo.
     *
     * @return array{codigo: ?int, fecha_vencimiento: ?int, pm: ?int, legajo: ?int, observaciones: ?int}
     */
    private function indicesDeColumnas(array $encabezado): array
    {
        $normalizado = array_map(
            fn ($h) => (string) Str::of((string) $h)->ascii()->lower()->squish(),
            $encabezado
        );

        $buscar = function (array $palabrasClave) use ($normalizado): ?int {
            foreach ($normalizado as $i => $header) {
                foreach ($palabrasClave as $palabra) {
                    if (str_contains($header, $palabra)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        return [
            'codigo' => $buscar(['codigo', 'cod']),
            'fecha_vencimiento' => $buscar(['vencimiento', 'vto']),
            'pm' => $buscar(['pm']),
            'legajo' => $buscar(['legajo']),
            'observaciones' => $buscar(['observacion', 'obs']),
            'link_registro' => $buscar(['link', 'url', 'registro']),
        ];
    }

    /**
     * Separa PM y legajo cuando vienen mezclados en la misma columna, como en
     * la planilla vieja ("PM 236-80" en unas filas, "LEGAJO 133" en otras).
     *
     * Si el archivo trae además una columna `legajo` propia, esa tiene
     * prioridad sobre lo que se pueda extraer del prefijo.
     *
     * @return array{0: ?string, 1: ?string} [pm, legajo]
     */
    private function pmYLegajo(?string $pmColumna, ?string $legajoColumna): array
    {
        if ($pmColumna !== null && preg_match('/^legajo\b[:\s]*(.*)$/i', $pmColumna, $m)) {
            $resto = trim($m[1]);

            return [null, $legajoColumna ?? ($resto !== '' ? $resto : $pmColumna)];
        }

        return [$pmColumna, $legajoColumna];
    }

    /**
     * Las fechas de Excel llegan como serial numérico (días desde 1900) o como
     * texto ya formateado, según cómo esté cargada la celda.
     */
    private function fecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $s = trim((string) $valor);

        if ($s === '' || $s === '-') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $s)->format('Y-m-d');
        } catch (\Throwable) {
            try {
                return Carbon::parse($s)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function limpiar(mixed $valor): ?string
    {
        $valor = Str::squish((string) $valor);

        return ($valor === '' || $valor === '-') ? null : $valor;
    }
}
