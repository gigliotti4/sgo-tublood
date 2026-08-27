<?php

namespace App\Support;

use App\Models\ObservationProduct;

/**
 * Las reglas de validación que comparten las dos puertas de entrada de una
 * observación con productos: el portal público (`Portal\ObservacionController`)
 * y la carga interna de tipos especiales
 * (`Admin\ObservacionController::storeInternaEspecial()`).
 *
 * Estaban copiadas tal cual en los dos lados. Es el mismo formulario cargado
 * por dos personas distintas, así que si las copias se desincronizan el portal
 * termina aceptando algo que el panel rechaza (o al revés) sin que nadie se
 * entere hasta que un reclamo se cae.
 *
 * Además de las reglas, acá viven los **nombres legibles** de cada campo. Sin
 * ellos Laravel arma el mensaje con la clave cruda y le dice al cliente "El
 * campo productos.0.lote es obligatorio", que no le indica qué tiene que tocar.
 * Se pasan como tercer argumento de `validate()` — mismo mecanismo que usa
 * `TaxonomiaIncidencias::atributosValidacion()` para los datos específicos.
 */
class ReglasObservacion
{
    /** Tope por archivo adjunto, en KB. */
    public const ADJUNTO_MAX_KB = 3072;

    /** Extensiones que acepta el formulario de carga. */
    public const ADJUNTO_FORMATOS = 'jpg,jpeg,png,pdf';

    /**
     * Los datos del producto reclamado, más los campos que son únicos por
     * observación aunque haya varios productos (institución, provincia…).
     */
    public static function productos(): array
    {
        return [
            'institucion' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'provincia' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'equipamiento' => ['nullable', 'string', 'max:255'],
            'ejecutivo_cuenta' => ['nullable', 'string', 'max:255'],

            'productos' => ['required_if:tipo,falla_producto', 'array'],
            'productos.*.producto' => ['required', 'string', 'max:255'],
            'productos.*.codigo' => ['required', 'string', 'max:255'],
            'productos.*.cantidad_afectada' => ['required', 'integer', 'min:1'],
            'productos.*.tipo_presentacion' => ['required', 'in:'.implode(',', array_keys(ObservationProduct::PRESENTACIONES))],
            'productos.*.lote' => ['required', 'string', 'max:255'],
            'productos.*.fecha_vencimiento' => ['required', 'date'],
            'productos.*.numero_remito' => ['nullable', 'string', 'max:255'],
            'productos.*.tipo_comprobante' => ['nullable', 'in:factura,remito'],
        ];
    }

    /**
     * Los archivos adjuntos.
     *
     * @param  string  $campo  `attachments` en las altas, `archivos` en la
     *                         bitácora y en la subida suelta de un caso.
     */
    public static function adjuntos(string $campo = 'attachments'): array
    {
        return [
            $campo => ['array'],
            $campo.'.*' => ['file', 'mimes:'.self::ADJUNTO_FORMATOS, 'max:'.self::ADJUNTO_MAX_KB],
        ];
    }

    /**
     * Los archivos de la gestión interna de un caso (subida suelta y adjuntos
     * de un comentario de bitácora).
     *
     * ⚠️ Es **más permisiva a propósito** que la del portal: acá adjunta
     * personal de Tublood, que sube informes y planillas, y el archivo no viaja
     * por una conexión de la que no sabemos nada. No unificar las dos.
     */
    public static function adjuntosDelPanel(string $campo = 'archivos'): array
    {
        return [
            $campo => ['array'],
            $campo.'.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:10240'],
        ];
    }

    /**
     * Nombres legibles para los mensajes de error.
     *
     * El `*` del medio lo resuelve Laravel contra el índice real, así que
     * "productos.2.lote" sale como "lote del producto". No lleva el número:
     * el formulario ya muestra el error dentro del bloque del producto que
     * corresponde, y "lote del producto 3" ahí sería ruido.
     */
    public static function atributos(string $campoAdjuntos = 'attachments'): array
    {
        return [
            'tipo' => 'tipo',
            'titulo' => 'asunto',
            'descripcion' => 'descripción',
            'contacto_nombre' => 'nombre / razón social',
            'contacto_email' => 'email',
            'contacto_telefono' => 'teléfono',
            'contacto_numero_cliente' => 'n° de cliente',
            'institucion' => 'institución',
            'provincia' => 'provincia',
            'equipamiento' => 'equipamiento',
            'ejecutivo_cuenta' => 'ejecutivo de cuenta',

            'productos' => 'productos',
            'productos.*.producto' => 'producto',
            'productos.*.codigo' => 'código del producto',
            'productos.*.cantidad_afectada' => 'cantidad afectada',
            'productos.*.tipo_presentacion' => 'tipo de presentación',
            'productos.*.lote' => 'lote',
            'productos.*.fecha_vencimiento' => 'fecha de vencimiento',
            'productos.*.numero_remito' => 'n° de remito',
            'productos.*.tipo_comprobante' => 'tipo de comprobante',

            $campoAdjuntos => 'archivos adjuntos',
            $campoAdjuntos.'.*' => 'archivo adjunto',
        ];
    }

    /**
     * El mensaje que ve quien cargaba la observación cuando el guardado se cae
     * por algo que no es culpa de lo que completó.
     *
     * Dice qué hacer y no qué pasó: "Integrity constraint violation" no le
     * sirve a nadie, y menos a un cliente externo. Lo que pasó queda en el log.
     */
    public static function mensajeDeFalla(): string
    {
        return 'No pudimos guardar la observación por un problema del sistema. '
            .'Los datos que cargaste siguen acá: volvé a intentarlo en un momento. '
            .'Si el problema persiste, avisanos.';
    }
}
