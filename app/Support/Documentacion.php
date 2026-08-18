<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Lee config/documentacion.php y expone el catálogo de clasificación
 * documental: qué documentos requiere cada tipo, cuáles son obligatorios,
 * cuáles vencen y cuál determina el vencimiento del registro.
 *
 * Mismo rol que TaxonomiaIncidencias para las incidencias internas: el
 * catálogo vive en config y de acá salen el select, el checklist de la ficha y
 * las reglas de validación, sin que ninguna de esas tres duplique la lista.
 *
 * **Lo comparten clientes y proveedores.** El parámetro `$entidad` solo recorta
 * qué tipos se ofrecen en cada uno (`config('documentacion.entidades')`); la
 * definición de cada tipo y de cada documento es una sola para los dos.
 */
class Documentacion
{
    public const CLIENTES = 'clientes';

    public const PROVEEDORES = 'proveedores';

    /** Todos los tipos del catálogo con sus documentos, sin recortar. */
    public static function todosLosTipos(): array
    {
        return config('documentacion.tipos', []);
    }

    /**
     * Los tipos que se ofrecen en una entidad, en el orden en que los declara
     * `config('documentacion.entidades')`.
     *
     * Una entidad sin entrada en ese mapa ofrece el catálogo completo: es un
     * default seguro, no esconde tipos por olvidar configurarla.
     */
    public static function tipos(string $entidad = self::CLIENTES): array
    {
        $todos = static::todosLosTipos();
        $permitidos = config("documentacion.entidades.{$entidad}");

        if ($permitidos === null) {
            return $todos;
        }

        $tipos = [];

        foreach ($permitidos as $slug) {
            if (isset($todos[$slug])) {
                $tipos[$slug] = $todos[$slug];
            }
        }

        return $tipos;
    }

    /** Mapa [slug => label] para los selects y para mostrar en listados. */
    public static function etiquetasTipos(string $entidad = self::CLIENTES): array
    {
        return array_map(fn ($tipo) => $tipo['label'], static::tipos($entidad));
    }

    /**
     * Etiqueta legible de un tipo, o null si no está en el catálogo.
     *
     * Busca en el catálogo completo y no en el de una entidad: un registro
     * clasificado con un tipo que después se sacó de su lista tiene que
     * seguir mostrando su nombre, no un hueco.
     */
    public static function etiqueta(?string $tipo): ?string
    {
        return $tipo === null ? null : (static::todosLosTipos()[$tipo]['label'] ?? null);
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

        foreach (static::todosLosTipos()[$tipo]['documentos'] ?? [] as $clave => $def) {
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

    /**
     * Resuelve el tipo de cliente que escribieron en el Excel: acepta el slug
     * (`laboratorio_analisis_clinicos`) o la etiqueta (`Laboratorio de Análisis
     * Clínicos`), sin distinguir acentos, mayúsculas ni espacios de más.
     *
     * Devuelve null si no matchea ninguno — el import lo reporta como
     * advertencia y deja el tipo que el cliente ya tenía.
     */
    public static function tipoDesdeEtiqueta(string $valor, string $entidad = self::CLIENTES): ?string
    {
        $buscado = static::normalizar($valor);

        if ($buscado === '') {
            return null;
        }

        foreach (static::tipos($entidad) as $slug => $tipo) {
            if ($buscado === static::normalizar($slug) || $buscado === static::normalizar($tipo['label'])) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Forma comparable de un nombre de tipo o de documento: sin acentos, en
     * minúscula y sin nada que no sea letra o número. Es lo que permite que el
     * Excel diga "Habilitación ANMAT", "habilitacion anmat" o
     * "HABILITACION_ANMAT" y las tres caigan en la misma clave.
     *
     * Mismo criterio que Proveedor::normalizarRazonSocial(), y por la misma
     * razón: es matcheo exacto sobre la forma normalizada, nunca aproximado.
     */
    public static function normalizar(string $valor): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(
            Str::ascii($valor)
        ));
    }
}
