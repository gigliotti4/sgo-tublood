<?php

namespace App\Support;

/**
 * Lee config/documentacion.php y expone el catálogo de clasificación
 * documental: qué documentos requiere cada tipo, cuáles son obligatorios,
 * cuáles vencen y cuál determina el vencimiento del registro.
 *
 * Mismo rol que TaxonomiaIncidencias para las incidencias internas: el
 * catálogo vive en config y de acá salen el select, el checklist de la ficha y
 * las reglas de validación, sin que ninguna de esas tres duplique la lista.
 */
class Documentacion
{
    /** Todos los tipos del catálogo con sus documentos. */
    public static function tipos(): array
    {
        return config('documentacion.tipos', []);
    }

    /** Mapa [slug => label] para los selects y para mostrar en listados. */
    public static function etiquetasTipos(): array
    {
        return array_map(fn ($tipo) => $tipo['label'], static::tipos());
    }

    /** Etiqueta legible de un tipo, o null si no está en el catálogo. */
    public static function etiqueta(?string $tipo): ?string
    {
        return $tipo === null ? null : (static::tipos()[$tipo]['label'] ?? null);
    }

    /**
     * Nombre canónico de cada documento del catálogo, [clave => label].
     *
     * Es el nombre con el que el mismo papel aparece en todos los tipos, y por
     * eso es el que usan los encabezados del Excel: un tipo puede mostrarlo con
     * otra etiqueta en pantalla (`label` propio), pero la columna es una sola.
     */
    public static function documentosCanonicos(): array
    {
        return config('documentacion.documentos', []);
    }

    /**
     * Documentos que requiere un tipo, con su def completa y el `label` ya
     * resuelto (el propio del tipo si lo declara, si no el canónico).
     *
     * Devuelve `[]` tanto para un cliente sin tipo como para un tipo que ya no
     * está en el catálogo: en los dos casos no hay nada que exigirle.
     *
     * @return array<string, array{label: string, obligatorio: bool, vence: bool, determina_vencimiento?: bool}>
     */
    public static function documentos(?string $tipo): array
    {
        if ($tipo === null) {
            return [];
        }

        $canonicos = static::documentosCanonicos();
        $documentos = [];

        foreach (static::tipos()[$tipo]['documentos'] ?? [] as $clave => $def) {
            $documentos[$clave] = ['label' => $canonicos[$clave] ?? $clave] + $def;
        }

        return $documentos;
    }

    /**
     * Clave del documento que determina el vencimiento del cliente, o null si
     * el tipo no tiene ninguno (los tipos cuyos documentos no vencen).
     */
    public static function documentoDeterminante(?string $tipo): ?string
    {
        foreach (static::documentos($tipo) as $clave => $def) {
            if (! empty($def['determina_vencimiento'])) {
                return $clave;
            }
        }

        return null;
    }

    /**
     * Reglas de validación del checklist de documentos de un tipo.
     *
     * Las reglas se arman documento por documento (y no con el comodín
     * `documentos.*`) para que la fecha solo se acepte donde el catálogo dice
     * que ese documento vence, y para rechazar cualquier clave que el tipo no
     * pida: un documento que no está en pantalla no puede entrar por el POST y
     * después ensuciar el cálculo del estado documental.
     */
    public static function reglasValidacion(?string $tipo): array
    {
        $documentos = static::documentos($tipo);
        $claves = array_keys($documentos);

        $reglas = [
            'documentos' => [
                'present',
                'array',
                function (string $attribute, mixed $value, \Closure $fail) use ($claves) {
                    $extra = array_diff(array_keys((array) $value), $claves);

                    if ($extra !== []) {
                        $fail('Hay documentos que no corresponden a este tipo de cliente: '.implode(', ', $extra).'.');
                    }
                },
            ],
        ];

        foreach ($documentos as $clave => $def) {
            $reglas["documentos.{$clave}.presentado"] = ['required', 'boolean'];

            $reglas["documentos.{$clave}.fecha_vencimiento"] = empty($def['vence'])
                ? ['nullable', 'prohibited']
                : ['nullable', 'date'];
        }

        return $reglas;
    }

    /**
     * Nombres legibles para el bloque documentos.* al validar. Sin esto los
     * errores dirían "El campo documentos.bpf.fecha vencimiento es obligatorio".
     * Los documentos son dinámicos (config), así que no pueden vivir en
     * lang/es/validation.php.
     */
    public static function atributosValidacion(?string $tipo): array
    {
        $atributos = [];

        foreach (static::documentos($tipo) as $clave => $def) {
            $atributos["documentos.{$clave}.presentado"] = mb_strtolower($def['label']);
            $atributos["documentos.{$clave}.fecha_vencimiento"] = 'vencimiento de '.mb_strtolower($def['label']);
        }

        return $atributos;
    }
}
