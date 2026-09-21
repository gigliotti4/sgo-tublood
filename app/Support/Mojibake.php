<?php

namespace App\Support;

/**
 * Repara texto UTF-8 que fue leído como Latin-1 y vuelto a codificar
 * ("Asociación" → "AsociaciÃ³n").
 *
 * Existe por el endpoint `clientes.php` de RP Sistemas, que devuelve el texto
 * ya roto (medido el 21/9/2026: 99 razones sociales, 162 domicilios, 54
 * localidades y 33 contactos de 3.729 clientes). ⚠️ **No es un problema de
 * nuestra base ni del cliente HTTP**: `articulos.php`, que sale por el mismo
 * `RpSistemasClient`, devuelve bien sus 44 descripciones con acentos ("BAÑO",
 * "DÍAS", "PUÑO"). Lo correcto a futuro es que RP lo arregle de su lado; hasta
 * entonces se repara al ingresar, que es lo único que está en nuestras manos.
 *
 * La conversión es **condicional y no a ciegas**: un `mb_convert_encoding()`
 * suelto sobre texto ya correcto lo destruiría. Se aplica solo cuando los
 * bytes, releídos como Latin-1, forman UTF-8 válido *y* dan letras acentuadas
 * — eso distingue "AsociaciÃ³n" (roto) de "BAÑO" (correcto), donde la
 * conversión daría bytes inválidos y por lo tanto se descarta.
 *
 * Es **idempotente**: sobre texto ya sano no cambia nada, así que el día que
 * RP corrija su endpoint esto pasa a ser un no-op y no hace falta sacarlo
 * apurado.
 */
class Mojibake
{
    /**
     * Cuántas veces se intenta desandar la conversión.
     *
     * Más de una porque el dato puede venir codificado dos veces seguidas. El
     * tope evita quedarse iterando sobre una cadena patológica.
     */
    private const MAX_PASADAS = 3;

    public static function reparar(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return $valor;
        }

        for ($i = 0; $i < self::MAX_PASADAS; $i++) {
            $candidato = self::unaPasada($valor);

            if ($candidato === null) {
                break;
            }

            $valor = $candidato;
        }

        return $valor;
    }

    /**
     * Repara todos los strings de un arreglo, recorriendo los anidados.
     *
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    public static function repararArreglo(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            $datos[$clave] = match (true) {
                is_string($valor) => self::reparar($valor),
                is_array($valor) => self::repararArreglo($valor),
                default => $valor,
            };
        }

        return $datos;
    }

    /**
     * Una vuelta de reparación, o `null` si la cadena no está rota.
     *
     * Cada guarda descarta un falso positivo distinto; sacar cualquiera rompe
     * texto que hoy está bien.
     */
    private static function unaPasada(string $valor): ?string
    {
        // Si ni siquiera es UTF-8 válido, esto no es doble codificación: es
        // otra cosa y tocarla sería empeorarla.
        if (! mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }

        // Sin alguno de los cuatro caracteres que arrancan una secuencia UTF-8
        // releída como Latin-1 (Â Ã Ä Å) no hay nada que reparar. Corta al toque
        // el caso mayoritario, que es texto ASCII puro.
        if (! preg_match('/[\x{00C2}-\x{00C5}]/u', $valor)) {
            return null;
        }

        // Algo arriba de U+00FF no entra en Latin-1: la conversión perdería
        // caracteres (los reemplaza por "?"), así que no se intenta.
        if (preg_match('/[^\x{0000}-\x{00FF}]/u', $valor)) {
            return null;
        }

        $bytes = mb_convert_encoding($valor, 'ISO-8859-1', 'UTF-8');

        // La prueba de fondo: si releídos como Latin-1 los bytes NO forman
        // UTF-8 válido, el texto estaba bien. Es lo que salva a "BAÑO", donde
        // la Ñ quedaría como un byte suelto inválido.
        if ($bytes === $valor || ! mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }

        // Y el resultado tiene que caer en el Latin-1 imprimible o más arriba,
        // no en los controles C1 (U+0080–U+009F): eso descarta los bytes de
        // control que casualmente formen una secuencia válida. Arranca en
        // U+00A0 y no en las letras acentuadas porque el espacio duro es de los
        // casos más comunes ("BECCARÂ " en vez de "BECCAR ").
        return preg_match('/[\x{00A0}-\x{024F}]/u', $bytes) ? $bytes : null;
    }
}
