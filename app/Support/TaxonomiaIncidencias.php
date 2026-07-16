<?php

namespace App\Support;

/**
 * Lee config/incidencias.php y expone la taxonomía de incidencias internas:
 * qué tipos tiene cada sector, qué campos "Datos específicos" tiene cada tipo,
 * y las reglas de validación dinámicas derivadas de esos campos.
 */
class TaxonomiaIncidencias
{
    /** Todos los sectores con sus tipos y campos (para pasar como prop a Inertia). */
    public static function taxonomia(): array
    {
        return config('incidencias.sectores', []);
    }

    /**
     * Mapa plano [tipoKey => "código label"] de todos los tipos internos, para
     * mostrar la etiqueta legible en listados. Las claves de tipo son únicas
     * entre sectores. Incluye los dos tipos de la carga externa.
     */
    public static function etiquetasTipos(): array
    {
        $labels = [
            'falla_producto' => 'Falla de Producto',
            'disconformidad_servicio' => 'Disconformidad de Servicio',
        ];

        foreach (static::taxonomia() as $tipos) {
            foreach ($tipos as $key => $def) {
                $labels[$key] = trim(($def['codigo'] ?? '').' '.($def['label'] ?? $key));
            }
        }

        return $labels;
    }

    /** Def del tipo de incidencia para un sector, o null si no existe. */
    public static function tipo(string $sectorSlug, string $tipoKey): ?array
    {
        return config("incidencias.sectores.{$sectorSlug}.{$tipoKey}");
    }

    /** Campos "Datos específicos" de un tipo. */
    public static function campos(string $sectorSlug, string $tipoKey): array
    {
        return static::tipo($sectorSlug, $tipoKey)['campos'] ?? [];
    }

    /**
     * Reglas de validación Laravel para el bloque datos_especificos.* de un tipo.
     * required según el flag del campo; el resto nullable, con tipo (integer/date)
     * y opciones (in:...) cuando corresponde.
     */
    public static function reglasValidacion(string $sectorSlug, string $tipoKey): array
    {
        $reglas = [];

        foreach (static::campos($sectorSlug, $tipoKey) as $campo) {
            $item = [empty($campo['required']) ? 'nullable' : 'required'];

            $item[] = match ($campo['tipo'] ?? 'text') {
                'number' => 'numeric',
                'date' => 'date',
                default => 'string',
            };

            if (in_array($campo['tipo'] ?? 'text', ['select', 'radio'], true) && ! empty($campo['opciones'])) {
                $item[] = 'in:'.implode(',', $campo['opciones']);
            }

            $reglas["datos_especificos.{$campo['id']}"] = $item;
        }

        return $reglas;
    }

    /**
     * Nombres legibles para el bloque datos_especificos.* de un tipo, tomados del
     * `label` de cada campo. Van como atributos custom al validar: sin esto los
     * errores dirían "El campo datos_especificos.numero_comprobante es obligatorio".
     * Los campos son dinámicos (config), así que no pueden vivir en lang/es/validation.php.
     */
    public static function atributosValidacion(string $sectorSlug, string $tipoKey): array
    {
        $atributos = [];

        foreach (static::campos($sectorSlug, $tipoKey) as $campo) {
            $atributos["datos_especificos.{$campo['id']}"] = mb_strtolower($campo['label'] ?? $campo['id']);
        }

        return $atributos;
    }
}
