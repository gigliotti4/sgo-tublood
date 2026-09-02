<?php

namespace App\Services;

use App\Models\Articulo;
use App\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Carga masiva de los campos propios de `articulos` (fecha de vencimiento, PM,
 * legajo, observaciones, link de registro y proveedor) desde las planillas
 * sueltas que hoy se llevan a mano.
 *
 * A diferencia de `UserImportService`, el mapeo va **por nombre de columna y
 * no por posición**: en la planilla real las columnas vienen salteadas (A, B,
 * E, F, I) y cualquiera puede agregar una columna nueva y correr el resto. Por
 * eso el mismo servicio atiende dos planillas distintas: la de Calidad
 * (PM/legajo/vencimiento) y la de proveedores (`proveedor_principal`).
 *
 * Por default **solo actualiza artículos que ya existen** en el catálogo
 * sincronizado del ERP: un código que no matchea se saltea con advertencia en
 * vez de crear un artículo fantasma. `$crearFaltantes` invierte esa política
 * para las planillas que sí traen códigos que RP todavía no tiene; se activa
 * con una tilde explícita en el modal de importación, nunca sola. El mismo
 * flag da de alta los **proveedores** cuya razón social no esté en el padrón
 * (sin número, porque esta planilla no lo trae).
 */
class ArticuloImportService
{
    /** Tope de advertencias en el flash: con miles de filas, todas serían ilegibles. */
    private const MAX_ADVERTENCIAS = 20;

    private array $advertencias = [];

    /**
     * Razones sociales del Excel que no matchearon ningún proveedor, con la
     * cantidad de artículos afectados. Se avisan una sola vez cada una al
     * final y no una por fila.
     *
     * @var array<string, int>
     */
    private array $proveedoresSinMatch = [];

    private int $proveedoresCreados = 0;

    /** @return array{creados: int, actualizados: int, proveedoresCreados: int, advertencias: array<int, string>} */
    public function import(UploadedFile $file, bool $crearFaltantes = false): array
    {
        $this->advertencias = [];
        $this->proveedoresSinMatch = [];
        $this->proveedoresCreados = 0;
        $creados = 0;
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

        // El padrón entero en memoria, una sola vez: la planilla real tiene
        // miles de filas y una query por fila sería inviable.
        $padron = $this->padronDeProveedores();

        foreach (array_slice($filas, 1) as $index => $fila) {
            $numeroFila = $index + 2;

            $codigo = $this->limpiar($fila[$indices['codigo']] ?? null);

            if ($codigo === null) {
                continue;
            }

            $articulo = Articulo::where('codigo', $codigo)->first();

            if (! $articulo) {
                if (! $crearFaltantes) {
                    $this->advertencias[] = "Fila {$numeroFila}: el código '{$codigo}' no está en el catálogo, se omitió.";

                    continue;
                }

                $descripcion = $indices['descripcion'] !== null
                    ? $this->limpiar($fila[$indices['descripcion']] ?? null)
                    : null;

                // `descripcion` es NOT NULL: sin ella no hay artículo que crear.
                if ($descripcion === null) {
                    $this->advertencias[] = "Fila {$numeroFila}: el código '{$codigo}' no está en el catálogo y la fila no trae descripción, se omitió.";

                    continue;
                }

                $articulo = Articulo::create([
                    'codigo' => $codigo,
                    'descripcion' => $descripcion,
                ]);

                $creados++;
            } else {
                $actualizados++;
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
                'link_registro' => $indices['link_registro'] !== null
                    ? ($this->limpiar($fila[$indices['link_registro']] ?? null) ?? $articulo->link_registro)
                    : $articulo->link_registro,
            ]);

            // El proveedor va aparte del update de arriba porque no siempre se
            // puede escribir: si el artículo ya tiene un proveedor de una
            // fuente con más autoridad (el ERP, o una corrección a mano), la
            // planilla no lo pisa. Ver VinculacionProveedores::PRECEDENCIA.
            if ($indices['proveedor'] !== null) {
                $proveedorId = $this->proveedorId($fila[$indices['proveedor']] ?? null, $padron, $crearFaltantes);

                if ($proveedorId !== null
                    && VinculacionProveedores::puedePisar($articulo->proveedor_origen, VinculacionProveedores::ORIGEN_EXCEL)) {
                    $articulo->update([
                        'proveedor_id' => $proveedorId,
                        'proveedor_origen' => VinculacionProveedores::ORIGEN_EXCEL,
                    ]);
                }
            }
        }

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'proveedoresCreados' => $this->proveedoresCreados,
            'advertencias' => $this->advertenciasFinales(),
        ];
    }

    /**
     * Padrón indexado por razón social normalizada.
     *
     * Una clave que apunta a más de un proveedor queda marcada como ambigua
     * (`null`): asignar el primero de los dos sería asignar mal en silencio.
     *
     * @return array<string, int|null>
     */
    private function padronDeProveedores(): array
    {
        $padron = [];

        foreach (Proveedor::query()->get(['id', 'razon_social']) as $proveedor) {
            $clave = Proveedor::normalizarRazonSocial($proveedor->razon_social);

            if ($clave === '') {
                continue;
            }

            $padron[$clave] = array_key_exists($clave, $padron) ? null : $proveedor->id;
        }

        return $padron;
    }

    /**
     * Resuelve la razón social del Excel contra el padrón, dando de alta al
     * proveedor si no está y `$crearFaltantes` lo permite.
     *
     * Devuelve `null` si la celda está vacía, si la razón social no matchea y
     * no se puede crear, o si es ambigua. Quien llama interpreta ese `null`
     * como "no toques lo que ya estaba", así que una planilla mal escrita nunca
     * borra un proveedor corregido a mano.
     *
     * El padrón se pasa por referencia para que un proveedor recién creado
     * quede disponible para las filas siguientes del mismo archivo: si no, la
     * misma empresa entraría una vez por artículo.
     *
     * @param  array<string, int|null>  $padron
     */
    private function proveedorId(mixed $valor, array &$padron, bool $crearFaltantes): ?int
    {
        $razonSocial = $this->limpiar($valor);

        if ($razonSocial === null) {
            return null;
        }

        $clave = Proveedor::normalizarRazonSocial($razonSocial);

        if (! array_key_exists($clave, $padron)) {
            if (! $crearFaltantes) {
                $this->proveedoresSinMatch[$razonSocial] = ($this->proveedoresSinMatch[$razonSocial] ?? 0) + 1;

                return null;
            }

            // Sin `numero`: esta planilla no trae el NUM_PROV. Lo completa
            // después el import del padrón, que adopta a los que no lo tienen
            // en vez de duplicarlos.
            $proveedor = Proveedor::create(['razon_social' => $razonSocial]);
            $padron[$clave] = $proveedor->id;
            $this->proveedoresCreados++;

            return $proveedor->id;
        }

        if ($padron[$clave] === null) {
            $this->advertencias[] = "Hay más de un proveedor con la razón social '{$razonSocial}' en el padrón: no se asignó ninguno.";

            return null;
        }

        return $padron[$clave];
    }

    /**
     * Junta las advertencias por fila con las agrupadas por proveedor y corta
     * el total, para que el flash del import siga siendo legible.
     *
     * @return array<int, string>
     */
    private function advertenciasFinales(): array
    {
        $advertencias = $this->advertencias;

        foreach ($this->proveedoresSinMatch as $razonSocial => $cantidad) {
            $articulos = $cantidad === 1 ? '1 artículo quedó' : "{$cantidad} artículos quedaron";
            $advertencias[] = "No se encontró el proveedor '{$razonSocial}' en el padrón ({$articulos} sin proveedor).";
        }

        if (count($advertencias) <= self::MAX_ADVERTENCIAS) {
            return $advertencias;
        }

        $resto = count($advertencias) - self::MAX_ADVERTENCIAS;

        return [
            ...array_slice($advertencias, 0, self::MAX_ADVERTENCIAS),
            "…y {$resto} advertencias más.",
        ];
    }

    /**
     * Ubica cada columna por su encabezado normalizado (sin acentos, en
     * minúscula), no por posición. Devuelve el índice de la primera columna
     * que contenga alguna de las palabras clave del campo.
     *
     * @return array{codigo: ?int, descripcion: ?int, fecha_vencimiento: ?int, pm: ?int, legajo: ?int, observaciones: ?int, link_registro: ?int, proveedor: ?int}
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
            // Solo se usa para dar de alta un artículo que no está en el
            // catálogo; nunca pisa la descripción de uno que ya existe, que es
            // dato del ERP.
            'descripcion' => $buscar(['descrip']),
            'fecha_vencimiento' => $buscar(['vencimiento', 'vto']),
            'pm' => $buscar(['pm']),
            'legajo' => $buscar(['legajo']),
            'observaciones' => $buscar(['observacion', 'obs']),
            'link_registro' => $buscar(['link', 'url', 'registro']),
            'proveedor' => $buscar(['proveedor', 'razon']),
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
