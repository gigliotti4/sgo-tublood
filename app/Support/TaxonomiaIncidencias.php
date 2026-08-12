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
     * Mapa plano [tipoKey => "código label"] de todos los tipos, para mostrar la
     * etiqueta legible en listados. Las claves de tipo son únicas entre sectores.
     */
    public static function etiquetasTipos(): array
    {
        $labels = [];

        foreach (static::taxonomia() as $tipos) {
            foreach ($tipos as $key => $def) {
                $labels[$key] = trim(($def['codigo'] ?? '').' '.($def['label'] ?? $key));
            }
        }

        return $labels;
    }

    /**
     * Slug del sector al que pertenece un tipo de incidencia, o null si el tipo
     * no está en la taxonomía. Se apoya en la misma invariante que
     * {@see static::etiquetasTipos()}: las claves de tipo son únicas entre sectores.
     *
     * Lo usa el portal público, donde el cliente elige el tipo pero no el sector.
     */
    public static function sectorDeTipo(string $tipoKey): ?string
    {
        foreach (static::taxonomia() as $sectorSlug => $tipos) {
            if (array_key_exists($tipoKey, $tipos)) {
                return $sectorSlug;
            }
        }

        return null;
    }

    /**
     * Rol que atiende ese tipo de reclamo, o null si el tipo no tiene uno
     * asignado en `incidencias.roles_por_tipo`.
     *
     * Reparte los reclamos del portal entre las personas de Garantía de Calidad:
     * el sector no alcanza para eso porque los dos tipos externos cuelgan del
     * mismo sector.
     */
    public static function rolDeTipo(?string $tipoKey): ?string
    {
        if ($tipoKey === null) {
            return null;
        }

        return config("incidencias.roles_por_tipo.{$tipoKey}");
    }

    /** Todos los roles que atienden algún tipo de reclamo externo. */
    public static function rolesPorTipo(): array
    {
        return config('incidencias.roles_por_tipo', []);
    }

    /** Def del tipo de incidencia para un sector, o null si no existe. */
    public static function tipo(string $sectorSlug, string $tipoKey): ?array
    {
        return config("incidencias.sectores.{$sectorSlug}.{$tipoKey}");
    }

    /**
     * True si el tipo es un "tipo especial" de ese sector: los del canal externo
     * (Falla de producto / Disconformidad de servicio), que no usan el mecanismo
     * genérico de "Datos específicos" sino productos repetibles validados en
     * Admin\ObservacionController::storeInternaEspecial().
     */
    public static function esTipoEspecial(string $sectorSlug, string $tipoKey): bool
    {
        return ! empty(static::tipo($sectorSlug, $tipoKey)['especial']);
    }

    /**
     * True si el tipo exige cargar el N° de cliente del bloque base "Cliente".
     *
     * Reemplaza a los campos `numero_cliente` que estos tipos declaraban en
     * "Datos específicos": duplicaban el campo base y, al no ser el base, no
     * resolvían el vínculo con la tabla `clientes`.
     */
    public static function requiereCliente(string $sectorSlug, string $tipoKey): bool
    {
        return ! empty(static::tipo($sectorSlug, $tipoKey)['requiere_cliente']);
    }

    /**
     * True si el tipo lleva la lista de productos afectados.
     *
     * Hoy solo "Falla de producto": "Disconformidad de servicio" comparte el
     * resto del formulario pero no tiene productos. Lo consultan el portal
     * público y la carga interna para descartar las filas vacías que manda el
     * bloque oculto del formulario antes de validar.
     */
    public static function llevaProductos(?string $tipoKey): bool
    {
        return $tipoKey === 'falla_producto';
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
