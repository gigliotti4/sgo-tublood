<?php

namespace App\Services;

use App\Models\Indicador;
use App\Models\IndicadorCarga;
use App\Models\IndicadorValor;
use App\Models\Sector;
use App\Models\User;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Lee la planilla "OBJETIVOS 2026" del cliente, muestra qué cambiaría y la aplica.
 *
 * Son DOS pasos a propósito (pedido del cliente): `leer()` + `previsualizar()`
 * no escriben nada, y `aplicar()` solo corre si la planilla no tiene errores.
 * Subir un archivo equivocado no puede pisar los objetivos vigentes.
 *
 * Mismo molde que `ClienteImportService`: PhpSpreadsheet a mano y columnas
 * **por nombre de encabezado, no por posición**. Pero acá el libro tiene muchas
 * hojas (OBJETIVOS 2026, CONCILIACIÓN SGO, RESUMEN…), así que no se lee la hoja
 * activa: se busca la que tenga los encabezados.
 *
 * Qué se hace con cada columna:
 * - SECTOR, FALLO, OBJETIVO MENSUAL y TOLERANCIA MÁXIMA son obligatorias.
 * - TIPO DE META dice si se evalúa como máximo (menos es mejor) o mínimo (más es mejor).
 * - FUENTE / TIPO SGO se traduce a los tipos que cuenta (ver `config/objetivos.php`).
 * - Las columnas de mes (SEP-26…) se leen **solo** en los indicadores que no salen del
 *   SGO: en los que sí, el número de la planilla es una fórmula sobre su hoja de
 *   conciliación y el tablero los cuenta de las observaciones.
 * - "% DE IMPORTANCIA" es opcional: la planilla real no lo trae.
 */
class IndicadorImportService
{
    private const MESES = ['ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12];

    /** Campos que se comparan para decir "cambió", con su nombre para mostrar. */
    private const CAMPOS = [
        'objetivo' => 'Objetivo', 'tolerancia' => 'Tolerancia', 'tipo_meta' => 'Tipo de meta',
        'estado' => 'Estado', 'cant_pedidos' => 'Pedidos base', 'importancia' => 'Importancia',
        'fuente' => 'Fuente', 'meta' => 'Meta', 'meta_medicion' => 'Medición', 'descripcion' => 'Indicador',
    ];

    /**
     * Lee la planilla y devuelve las filas ya interpretadas, con sus errores.
     *
     * @return array{filas: list<array<string, mixed>>, errores: list<string>, hoja: string}
     */
    public function leer(string $ruta): array
    {
        try {
            $libro = IOFactory::load($ruta);
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo leer el archivo: tiene que ser un .xlsx válido.');
        }

        [$hoja, $columnas, $filaEncabezado] = $this->buscarHoja($libro);

        if (! $hoja) {
            throw new RuntimeException('No encontré una hoja con los encabezados SECTOR, FALLO, OBJETIVO MENSUAL y TOLERANCIA MÁXIMA.');
        }

        $filas = [];
        $errores = [];
        $vistos = [];

        for ($r = $filaEncabezado + 1; $r <= $hoja->getHighestRow(); $r++) {
            $sector = $this->texto($this->celda($hoja, $columnas['SECTOR'], $r));
            $fallo = $this->texto($this->celda($hoja, $columnas['FALLO'], $r));

            // Las filas vacías del final de la planilla no son un error.
            if ($sector === '' && $fallo === '') {
                continue;
            }

            $fila = $this->interpretar($hoja, $columnas, $r, $sector, $fallo);

            $clave = Str::lower($sector.'|'.$fallo);
            if (isset($vistos[$clave])) {
                $fila['errores'][] = "Está repetido (también en la fila {$vistos[$clave]}).";
            }
            $vistos[$clave] = $r;

            $filas[] = $fila;
        }

        if ($filas === []) {
            $errores[] = 'La hoja no tiene ninguna fila de indicadores.';
        }

        // La importancia de un sector tiene que sumar 100 % —si se cargó para todos sus indicadores.
        foreach (collect($filas)->groupBy('sector') as $sector => $delSector) {
            if ($delSector->every(fn ($f) => $f['importancia'] !== null)) {
                $suma = round($delSector->sum('importancia'), 2);

                if (abs($suma - 100) > 0.5) {
                    $errores[] = "La importancia de {$sector} suma {$suma} % y tiene que sumar 100 %.";
                }
            }
        }

        return ['filas' => $filas, 'errores' => $errores, 'hoja' => $hoja->getTitle()];
    }

    /**
     * Qué cambiaría contra lo que hay hoy. No escribe nada.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{filas: list<array<string, mixed>>, resumen: array<string, int>}
     */
    public function previsualizar(array $filas): array
    {
        $existentes = Indicador::all()->keyBy(fn ($i) => Str::lower($i->sector.'|'.$i->fallo));
        $resumen = ['nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0, 'con_errores' => 0, 'valores' => 0];

        $vista = [];

        foreach ($filas as $fila) {
            $actual = $existentes->get(Str::lower($fila['sector'].'|'.$fila['fallo']));
            $cambios = [];

            if ($actual) {
                foreach (self::CAMPOS as $campo => $etiqueta) {
                    $nuevo = $fila[$campo];

                    // Una celda vacía no pisa lo cargado (la importancia, sobre todo: la planilla no la trae).
                    if ($nuevo === null || $nuevo === '') {
                        continue;
                    }

                    if ($this->distinto($actual->{$campo}, $nuevo)) {
                        $cambios[] = ['campo' => $etiqueta, 'antes' => $actual->{$campo}, 'despues' => $nuevo];
                    }
                }
            }

            $estado = $fila['errores'] !== [] ? 'error' : (! $actual ? 'nuevo' : ($cambios ? 'cambiado' : 'igual'));
            $resumen[match ($estado) {
                'error' => 'con_errores', 'nuevo' => 'nuevos', 'cambiado' => 'cambiados', 'igual' => 'sin_cambios',
            }]++;
            $resumen['valores'] += count($fila['meses']);

            $vista[] = [
                'fila' => $fila['fila'], 'sector' => $fila['sector'], 'fallo' => $fila['fallo'],
                'estado' => $estado, 'tipo_meta' => $fila['tipo_meta'], 'origen_dato' => $fila['origen_dato'],
                'objetivo' => $fila['objetivo'], 'tolerancia' => $fila['tolerancia'],
                'fuente' => $fila['fuente'], 'tipos' => $fila['tipos'], 'criterios' => $fila['criterios'],
                'sector_sgo' => $fila['sector_slug'],
                'cambios' => $cambios, 'errores' => $fila['errores'], 'avisos' => $fila['avisos'],
            ];
        }

        return ['filas' => $vista, 'resumen' => $resumen];
    }

    /**
     * Escribe la planilla. Todo o nada: con un solo error no se aplica nada.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, int>
     */
    public function aplicar(array $filas, ?User $usuario, string $archivo): array
    {
        if (collect($filas)->contains(fn ($f) => $f['errores'] !== [])) {
            throw new RuntimeException('La planilla tiene errores: corregilos y volvé a subirla.');
        }

        $resumen = ['nuevos' => 0, 'actualizados' => 0, 'valores' => 0];
        $sectores = Sector::pluck('id', 'slug');

        DB::transaction(function () use ($filas, $usuario, $archivo, $sectores, &$resumen) {
            foreach ($filas as $fila) {
                $actual = Indicador::where('sector', $fila['sector'])->where('fallo', $fila['fallo'])->first();

                $datos = [
                    'sector_id' => $sectores[$fila['sector_slug']] ?? null,
                    'origen_dato' => $fila['origen_dato'],
                    'objetivo' => $fila['objetivo'], 'tolerancia' => $fila['tolerancia'], 'tipo_meta' => $fila['tipo_meta'],
                    'estado' => $fila['estado'] ?? 'aprobado',
                    'meta' => $fila['meta'], 'meta_medicion' => $fila['meta_medicion'],
                    'cant_pedidos' => $fila['cant_pedidos'], 'descripcion' => $fila['descripcion'],
                    'fuente' => $fila['fuente'],
                ];

                // La importancia no se pisa con vacío: la planilla real no la trae.
                if ($fila['importancia'] !== null) {
                    $datos['importancia'] = $fila['importancia'];
                }

                // ⚠️ Lo que cuenta cada indicador (`tipos`, `criterios`) se deduce de la FUENTE
                // y se corrige a mano en cada indicador. Se vuelve a deducir solo si la fuente
                // cambió o el indicador es nuevo: si no, volver a subir la misma planilla
                // borraría los ajustes hechos a mano.
                if (! $actual || $actual->fuente !== $fila['fuente']) {
                    $datos['tipos'] = $fila['tipos'];
                    $datos['criterios'] = $fila['criterios'];
                }

                if ($actual) {
                    $actual->update($datos);
                    $indicador = $actual;
                    $resumen['actualizados']++;
                } else {
                    $indicador = Indicador::create(['sector' => $fila['sector'], 'fallo' => $fila['fallo'], 'activo' => true, ...$datos]);
                    $resumen['nuevos']++;
                }

                foreach ($fila['meses'] as $mes => $valor) {
                    IndicadorValor::updateOrCreate(
                        ['indicador_id' => $indicador->id, 'mes' => $mes],
                        ['valor' => $valor, 'cargado_por' => $usuario?->id],
                    );
                    $resumen['valores']++;
                }
            }

            IndicadorCarga::create(['user_id' => $usuario?->id, 'archivo' => $archivo, 'resumen' => $resumen]);
        });

        return $resumen;
    }

    // ── Lectura ─────────────────────────────────────────────────────────────

    /**
     * La hoja que tiene los encabezados, en cualquier lugar del libro.
     *
     * @return array{0: ?Worksheet, 1: array<string, int>, 2: int}
     */
    private function buscarHoja($libro): array
    {
        foreach ($libro->getWorksheetIterator() as $hoja) {
            // Los encabezados pueden no estar en la fila 1 si alguien le puso un título arriba.
            for ($r = 1; $r <= min(5, $hoja->getHighestRow()); $r++) {
                $columnas = $this->columnas($hoja, $r);

                if (isset($columnas['SECTOR'], $columnas['FALLO'], $columnas['OBJETIVO MENSUAL'], $columnas['TOLERANCIA MAXIMA'])) {
                    return [$hoja, $columnas, $r];
                }
            }
        }

        return [null, [], 0];
    }

    /**
     * Encabezado normalizado → índice de columna (1 = A). Los meses van como `MES:2026-09`.
     *
     * @return array<string, int>
     */
    private function columnas(Worksheet $hoja, int $fila): array
    {
        $columnas = [];
        $ultima = Coordinate::columnIndexFromString($hoja->getHighestColumn());

        for ($c = 1; $c <= $ultima; $c++) {
            $nombre = $this->normalizar((string) $hoja->getCell([$c, $fila])->getValue());

            if ($nombre === '') {
                continue;
            }

            if (preg_match('/^(ENE|FEB|MAR|ABR|MAY|JUN|JUL|AGO|SEP|OCT|NOV|DIC)-(\d{2})$/', $nombre, $m)) {
                $columnas['MES:'.sprintf('20%s-%02d', $m[2], self::MESES[$m[1]])] = $c;

                continue;
            }

            $columnas[$nombre] ??= $c;
        }

        return $columnas;
    }

    /** @return array<string, mixed> */
    private function interpretar(Worksheet $hoja, array $col, int $r, string $sector, string $fallo): array
    {
        $errores = [];
        $avisos = [];

        $objetivo = $this->numero($this->celda($hoja, $col['OBJETIVO MENSUAL'], $r));
        $tolerancia = $this->numero($this->celda($hoja, $col['TOLERANCIA MAXIMA'], $r));

        if ($sector === '') {
            $errores[] = 'Falta el sector.';
        }
        if ($fallo === '') {
            $errores[] = 'Falta el fallo.';
        }
        if ($objetivo === null) {
            $errores[] = 'El objetivo mensual no es un número.';
        }
        if ($tolerancia === null) {
            $errores[] = 'La tolerancia máxima no es un número.';
        }

        $tipoMeta = $this->mapa(config('objetivos.tipos_meta'), $this->opcional($hoja, $col, 'TIPO DE META', $r));
        if ($tipoMeta === null) {
            // Sin la columna se asume el caso común (un máximo de fallos), pero se avisa.
            $tipoMeta = Indicador::MAXIMO;
            $avisos[] = 'No dice el tipo de meta: se tomó "Máximo de fallos".';
        }

        // Con un máximo, la tolerancia es el techo (≥ objetivo); con un mínimo, el piso (≤ objetivo).
        if ($objetivo !== null && $tolerancia !== null) {
            if ($tipoMeta === Indicador::MAXIMO && $tolerancia < $objetivo) {
                $errores[] = 'En un máximo de fallos la tolerancia no puede ser menor que el objetivo.';
            }
            if ($tipoMeta === Indicador::MINIMO && $tolerancia > $objetivo) {
                $errores[] = 'En un mínimo a alcanzar la tolerancia no puede ser mayor que el objetivo.';
            }
            if ($tipoMeta === Indicador::MAXIMO && $tolerancia === $objetivo) {
                $avisos[] = 'Objetivo y tolerancia son iguales: nunca va a quedar en amarillo.';
            }
        }

        $fuente = $this->texto($this->opcional($hoja, $col, 'FUENTE / TIPO SGO', $r));
        $origen = Str::startsWith($this->normalizar($fuente), 'SGO') ? 'sgo' : 'manual';
        [$tipos, $criterios, $avisosFuente] = $origen === 'sgo' ? $this->traducirFuente($fuente) : [null, null, []];

        $slug = config('objetivos.sectores.'.$this->normalizar($sector));
        if ($sector !== '' && $slug === null) {
            $avisos[] = "No conozco el sector «{$sector}»: el indicador se carga igual, sin sector del SGO.";
        }

        // Los meses solo se leen en los que no salen del SGO.
        $meses = [];
        if ($origen === 'manual') {
            foreach ($col as $clave => $c) {
                if (str_starts_with($clave, 'MES:') && ($v = $this->numero($this->celda($hoja, $c, $r))) !== null) {
                    $meses[substr($clave, 4)] = $v;
                }
            }
        }

        return [
            'fila' => $r, 'sector' => $sector, 'fallo' => $fallo,
            'origen_dato' => $origen, 'fuente' => $fuente !== '' ? $fuente : null,
            'tipos' => $tipos, 'criterios' => $criterios,
            'sector_slug' => $slug,
            'meta' => $this->texto($this->opcional($hoja, $col, 'META', $r)) ?: null,
            'meta_medicion' => $this->texto($this->opcional($hoja, $col, 'META MEDICION', $r)) ?: null,
            'cant_pedidos' => $this->numero($this->opcional($hoja, $col, 'CANT. DE PEDIDOS', $r)),
            'objetivo' => $objetivo, 'tolerancia' => $tolerancia, 'tipo_meta' => $tipoMeta,
            'descripcion' => $this->texto($this->opcional($hoja, $col, 'INDICADOR', $r)) ?: null,
            'estado' => $this->mapa(config('objetivos.estados'), $this->opcional($hoja, $col, 'ESTADO DEL VALOR', $r)),
            'importancia' => $this->numero($this->opcional($hoja, $col, '% DE IMPORTANCIA', $r) ?? $this->opcional($hoja, $col, 'IMPORTANCIA', $r)),
            'meses' => $meses,
            'errores' => $errores,
            'avisos' => [...$avisos, ...$avisosFuente],
        ];
    }

    /**
     * "SGO · 5.1 + 5.3" → los tipos que cuenta; "SGO · 3.7 (sin 0062)" → además un criterio.
     *
     * @return array{0: list<string>, 1: ?array<string, mixed>, 2: list<string>}
     */
    private function traducirFuente(string $fuente): array
    {
        $avisos = [];
        $claves = [];

        preg_match_all('/\b(?:E\.\d+|\d+\.\d+)\b/', $fuente, $m);

        // ⚠️ `config('objetivos.equivalencias.3.5')` NO sirve: el punto del código se
        // lee como un nivel más del arreglo. Se trae el arreglo entero y se indexa.
        $equivalencias = config('objetivos.equivalencias', []);

        foreach (array_unique($m[0]) as $codigo) {
            // La planilla sigue la clasificación vieja: 3.5 se cuenta como E.1, etc. (ver config).
            foreach ($equivalencias[$codigo] ?? [$codigo] as $equivalente) {
                $clave = $this->claveDeCodigo($equivalente);

                if ($clave === null) {
                    $avisos[] = "El código {$equivalente} no existe en el SGO: no se cuenta.";

                    continue;
                }

                $claves[$clave] = true;
            }
        }

        if ($claves === []) {
            $avisos[] = 'No se pudo deducir qué tipos cuenta: elegilos a mano en el indicador.';
        }

        $criterios = [];
        foreach (config('objetivos.calificadores') as $calificador) {
            if (preg_match($calificador['patron'], $fuente)) {
                $criterios = array_merge($criterios, $calificador['criterios']);
            }
        }

        return [array_keys($claves), $criterios ?: null, $avisos];
    }

    private function claveDeCodigo(string $codigo): ?string
    {
        foreach (TaxonomiaIncidencias::taxonomia() as $tipos) {
            foreach ($tipos as $clave => $def) {
                if (($def['codigo'] ?? null) === $codigo) {
                    return $clave;
                }
            }
        }

        return null;
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function celda(Worksheet $hoja, int $columna, int $fila): mixed
    {
        $celda = $hoja->getCell([$columna, $fila]);

        // Algunos valores son fórmulas (el objetivo de armado es 1 % de los pedidos).
        return $celda->isFormula() ? $celda->getCalculatedValue() : $celda->getValue();
    }

    private function opcional(Worksheet $hoja, array $col, string $nombre, int $fila): mixed
    {
        return isset($col[$nombre]) ? $this->celda($hoja, $col[$nombre], $fila) : null;
    }

    private function texto(mixed $valor): string
    {
        return trim((string) ($valor ?? ''));
    }

    /** Número desde una celda numérica o desde un texto ("35,000", "8,83"). Null si no es un número. */
    private function numero(mixed $valor): ?float
    {
        if ($valor === null || $valor === '' || is_bool($valor)) {
            return null;
        }

        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $t = trim(str_replace(["\u{00A0}", ' '], '', (string) $valor));

        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $t)) {
            $t = str_replace(',', '', $t);
        } elseif (preg_match('/^\d+,\d+$/', $t)) {
            $t = str_replace(',', '.', $t);
        }

        return is_numeric($t) ? (float) $t : null;
    }

    /** Mayúsculas, sin acentos y con un solo espacio: para comparar encabezados y nombres. */
    private function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->upper()->replaceMatches('/\s+/', ' ')->trim()->toString();
    }

    /** @param array<string, string> $mapa */
    private function mapa(array $mapa, mixed $valor): ?string
    {
        $t = $this->normalizar($this->texto($valor));

        return $t === '' ? null : ($mapa[$t] ?? null);
    }

    private function distinto(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) > 0.0005;
        }

        return json_encode($a) !== json_encode($b);
    }
}
