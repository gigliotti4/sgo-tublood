<?php

namespace App\Services;

use App\Models\Cliente;
use App\Support\Documentacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Carga masiva de la clasificación documental de clientes desde Excel.
 *
 * El archivo que baja `ClienteExportService` es la plantilla: mismos
 * encabezados, así que el circuito exportar → editar → subir cierra solo.
 *
 * Igual que `ArticuloImportService` y `ProveedorImportService`, el mapeo va
 * **por nombre de columna y no por posición**: el archivo puede traer las
 * columnas en otro orden, o solo algunas, y se usa lo que haya.
 *
 * ⚠️ **Nunca crea clientes.** La tabla `clientes` se espeja de RP Sistemas; un
 * número que no está en la base es un error del archivo, no un cliente nuevo,
 * y darlo de alta acá crearía un registro que la próxima sincronización no
 * reconocería. Se reporta como advertencia y se sigue.
 *
 * ⚠️ **Una celda vacía no borra lo que ya estaba.** Vale para el tipo, las
 * notas y cada documento: es lo que permite subir una planilla parcial (solo
 * las columnas que a alguien le interesa corregir) sin arrasar con el resto, y
 * lo que protege lo cargado a mano cuando se reimporta un archivo viejo. Para
 * dar de baja un documento hay que escribir "NO" explícito.
 *
 * Las columnas calculadas del export (completa / faltante / vencida / próximo
 * vto / días para vencer) se ignoran: se recalculan solas desde el checklist.
 */
class ClienteImportService
{
    /** Tope de advertencias en el flash: la planilla real tiene miles de filas. */
    private const MAX_ADVERTENCIAS = 20;

    private array $advertencias = [];

    private int $advertenciasOmitidas = 0;

    /** Números que no existen en la base, contados una vez cada uno. */
    private array $desconocidos = [];

    /** @return array{actualizados: int, documentos: int, advertencias: array<int, string>} */
    public function import(UploadedFile $file): array
    {
        $this->advertencias = [];
        $this->advertenciasOmitidas = 0;
        $this->desconocidos = [];

        $filas = IOFactory::load($file->getRealPath())
            ->getActiveSheet()
            ->toArray();

        $encabezado = $filas[0] ?? [];
        $indices = $this->indicesDeColumnas($encabezado);

        if ($indices['numero'] === null) {
            throw new \InvalidArgumentException(
                'El archivo no tiene una columna de número de cliente (se esperaba algo como "N°" o "Número").'
            );
        }

        $columnasDocumento = $this->columnasDeDocumentos($encabezado);

        $actualizados = 0;
        $documentos = 0;

        foreach (array_slice($filas, 1) as $index => $fila) {
            $numero = $this->numero($fila[$indices['numero']] ?? null);

            if ($numero === null) {
                continue;
            }

            $cliente = Cliente::with('documentos')->where('numero', $numero)->first();

            if (! $cliente) {
                $this->desconocidos[$numero] = true;

                continue;
            }

            $documentos += $this->aplicarFila($cliente, $fila, $indices, $columnasDocumento, $index + 2);
            $actualizados++;
        }

        foreach (array_keys($this->desconocidos) as $numero) {
            $this->advertir("El cliente '{$numero}' no existe en la base y no se creó: los clientes vienen de RP Sistemas.");
        }

        if ($this->advertenciasOmitidas > 0) {
            $this->advertencias[] = "…y {$this->advertenciasOmitidas} advertencia(s) más.";
        }

        return [
            'actualizados' => $actualizados,
            'documentos' => $documentos,
            'advertencias' => $this->advertencias,
        ];
    }

    /**
     * Aplica una fila del Excel a un cliente. Devuelve cuántos documentos tocó.
     *
     * El tipo se resuelve **antes** que los documentos y en el mismo guardado,
     * porque es el que decide qué documentos corresponden: una fila que
     * reclasifica y carga papeles del tipo nuevo tiene que funcionar de una.
     */
    private function aplicarFila(Cliente $cliente, array $fila, array $indices, array $columnasDocumento, int $numeroFila): int
    {
        $generales = [];

        if ($indices['tipo_cliente'] !== null) {
            $valor = $this->limpiar($fila[$indices['tipo_cliente']] ?? null);

            if ($valor !== null) {
                $tipo = Documentacion::tipoDesdeEtiqueta($valor);

                if ($tipo === null) {
                    $this->advertir("Fila {$numeroFila}: el tipo de cliente '{$valor}' no está en el catálogo, se dejó el que tenía.");
                } else {
                    $generales['tipo_cliente'] = $tipo;
                }
            }
        }

        foreach (['tiene_legajo', 'habilitado'] as $campo) {
            if ($indices[$campo] === null) {
                continue;
            }

            $valor = $this->siNo($fila[$indices[$campo]] ?? null);

            if ($valor !== null) {
                $generales[$campo] = $valor;
            }
        }

        if ($indices['notas'] !== null) {
            $valor = $this->limpiar($fila[$indices['notas']] ?? null);

            if ($valor !== null) {
                $generales['notas'] = $valor;
            }
        }

        $tocados = 0;

        DB::transaction(function () use ($cliente, $generales, $fila, $columnasDocumento, $numeroFila, &$tocados) {
            if ($generales !== []) {
                $cliente->update($generales);
            }

            $delTipo = Documentacion::documentos($cliente->tipo_cliente);

            foreach ($columnasDocumento as $clave => $columnas) {
                $presentado = $this->siNo($fila[$columnas['presentado']] ?? null);
                $vencimiento = $columnas['vencimiento'] === null
                    ? null
                    : $this->fecha($fila[$columnas['vencimiento']] ?? null, $numeroFila);

                if ($presentado === null && $vencimiento === null) {
                    continue;
                }

                if (! isset($delTipo[$clave])) {
                    $label = Documentacion::documentosCanonicos()[$clave] ?? $clave;
                    $etiqueta = Documentacion::etiqueta($cliente->tipo_cliente) ?? 'sin tipo';
                    $this->advertir("Fila {$numeroFila}: '{$label}' no corresponde a un cliente de tipo {$etiqueta}, se ignoró.");

                    continue;
                }

                $doc = $cliente->documentos->firstWhere('documento', $clave);

                // Una celda vacía no pisa: se conserva lo que ya estaba.
                $presentadoFinal = $presentado ?? (bool) $doc?->presentado;

                $cliente->documentos()->updateOrCreate(
                    ['documento' => $clave],
                    [
                        'presentado' => $presentadoFinal,
                        // Misma regla que el formulario: la fecha solo vale en un
                        // documento que vence y que además está presentado.
                        'fecha_vencimiento' => empty($delTipo[$clave]['vence']) || ! $presentadoFinal
                            ? null
                            : ($vencimiento ?? $doc?->fecha_vencimiento),
                    ]
                );

                $tocados++;
            }

            // Los documentos que quedaron de un tipo anterior no se muestran ni
            // cuentan, así que tampoco se guardan — igual que al guardar el
            // checklist desde la ficha.
            $cliente->documentos()
                ->whereNotIn('documento', array_keys($delTipo))
                ->delete();
        });

        $cliente->recalcularEstadoDocumental();

        return $tocados;
    }

    /**
     * Ubica las columnas generales por su encabezado normalizado.
     *
     * @return array{numero: ?int, tipo_cliente: ?int, tiene_legajo: ?int, habilitado: ?int, notas: ?int}
     */
    private function indicesDeColumnas(array $encabezado): array
    {
        $normalizado = array_map(fn ($h) => Documentacion::normalizar((string) $h), $encabezado);

        $buscar = function (array $candidatos) use ($normalizado): ?int {
            foreach ($candidatos as $candidato) {
                $indice = array_search($candidato, $normalizado, true);

                if ($indice !== false) {
                    return $indice;
                }
            }

            return null;
        };

        return [
            // Exacto y no `str_contains`: "numero" suelto también matchearía
            // "Número de documento" o cualquier otra columna que lo contenga.
            'numero' => $buscar(['n', 'numero', 'nro', 'ncliente', 'numerocliente', 'nrocliente', 'codigo']),
            'tipo_cliente' => $buscar(['tipodecliente', 'tipocliente', 'tipo']),
            'tiene_legajo' => $buscar(['tienelegajo', 'legajo']),
            'habilitado' => $buscar(['habilitado']),
            'notas' => $buscar(['observaciones', 'notas']),
        ];
    }

    /**
     * Empareja cada documento del catálogo con sus dos columnas del archivo
     * (el nombre canónico y ese mismo nombre con " - Vto").
     *
     * Se recorre el catálogo y no el encabezado para que una columna que no
     * corresponde a ningún documento —las calculadas del export, o cualquier
     * cosa que alguien haya agregado— simplemente no participe.
     *
     * @return array<string, array{presentado: int, vencimiento: ?int}>
     */
    private function columnasDeDocumentos(array $encabezado): array
    {
        $normalizado = array_map(fn ($h) => Documentacion::normalizar((string) $h), $encabezado);
        $columnas = [];

        foreach (Documentacion::documentosCanonicos() as $clave => $label) {
            $presentado = array_search(Documentacion::normalizar($label), $normalizado, true);

            if ($presentado === false) {
                continue;
            }

            $vencimiento = array_search(Documentacion::normalizar("{$label} - Vto"), $normalizado, true);

            $columnas[$clave] = [
                'presentado' => $presentado,
                'vencimiento' => $vencimiento === false ? null : $vencimiento,
            ];
        }

        return $columnas;
    }

    /** Sí/No tolerante: null cuando la celda está vacía (o sea, "no tocar"). */
    private function siNo(mixed $valor): ?bool
    {
        $valor = $this->limpiar($valor);

        if ($valor === null) {
            return null;
        }

        return match (Documentacion::normalizar($valor)) {
            'si', 's', 'x', 'true', '1', 'ok', 'presentado' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    /**
     * Fecha de una celda: PhpSpreadsheet la devuelve como serial numérico si la
     * celda está tipada como fecha en Excel, y como texto si la tipearon a mano.
     */
    private function fecha(mixed $valor, int $numeroFila): ?Carbon
    {
        $valor = $this->limpiar($valor);

        if ($valor === null) {
            return null;
        }

        if (is_numeric($valor)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $valor))->startOfDay();
        }

        // Argentina: dd/mm/aaaa. `Carbon::parse` leería 03/04/2027 como el 4 de
        // marzo, así que el formato local se prueba primero.
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, $valor)->startOfDay();
            } catch (\Exception) {
                continue;
            }
        }

        $this->advertir("Fila {$numeroFila}: no se entendió la fecha '{$valor}', se dejó la que tenía.");

        return null;
    }

    /**
     * El número de cliente viene como celda numérica o como texto según cómo
     * esté tipada; se normaliza a entero para que "1066" y "1066.0" sean el
     * mismo cliente. Mismo criterio que ProveedorImportService::numero().
     */
    private function numero(mixed $valor): ?string
    {
        $valor = $this->limpiar($valor);

        if ($valor === null) {
            return null;
        }

        if (is_numeric($valor) && (float) $valor == (int) (float) $valor) {
            return (string) (int) (float) $valor;
        }

        return $valor;
    }

    private function limpiar(mixed $valor): ?string
    {
        $valor = Str::squish((string) $valor);

        return ($valor === '' || $valor === '-') ? null : $valor;
    }

    /** Corta en MAX_ADVERTENCIAS y cuenta el resto: el flash tiene que ser legible. */
    private function advertir(string $mensaje): void
    {
        if (count($this->advertencias) >= self::MAX_ADVERTENCIAS) {
            $this->advertenciasOmitidas++;

            return;
        }

        $this->advertencias[] = $mensaje;
    }
}
