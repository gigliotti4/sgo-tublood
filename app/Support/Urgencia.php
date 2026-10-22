<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Decide si la observación de una proforma la marca como urgente.
 *
 * Es un port exacto de `isUrg()` del tablero suelto que leía el Excel
 * (dashboard_urgencias_proformas_1.html): el cliente ya validaba sus números
 * con esa regla, y si el SGO contara distinto los dos tableros se
 * contradirían. Los parámetros viven en config/urgencias.php.
 *
 * Por palabra, en mayúsculas y sin acentos:
 * 1. menos de 3 letras, no;
 * 2. empieza con un prefijo de config (`URG`, `URJ`…), sí;
 * 3. si no, tiene que empezar con U o R y tener 5 letras o más, y estar a
 *    distancia de edición ≤ 2 de alguna palabra objetivo ("URGNETE").
 *
 * ⚠️ Es a propósito una regla **floja**: el pedido fue "o similar teniendo en
 * cuenta que pudo haberse tipeado mal". La pantalla lista las proformas
 * marcadas con su observación justamente para poder controlarla a ojo.
 */
class Urgencia
{
    public static function detectar(?string $observacion): bool
    {
        if ($observacion === null || trim($observacion) === '') {
            return false;
        }

        $palabras = preg_split('/[^A-Z]+/', mb_strtoupper(Str::ascii($observacion)), -1, PREG_SPLIT_NO_EMPTY);
        $prefijos = config('urgencias.prefijos', []);
        $objetivos = config('urgencias.palabras', []);
        $distancia = (int) config('urgencias.distancia_maxima', 2);
        $largo = (int) config('urgencias.largo_minimo', 5);

        foreach ($palabras as $palabra) {
            if (strlen($palabra) < 3) {
                continue;
            }

            foreach ($prefijos as $prefijo) {
                if (str_starts_with($palabra, $prefijo)) {
                    return true;
                }
            }

            if (! in_array($palabra[0], ['U', 'R'], true) || strlen($palabra) < $largo) {
                continue;
            }

            foreach ($objetivos as $objetivo) {
                if (levenshtein($palabra, $objetivo) <= $distancia) {
                    return true;
                }
            }
        }

        return false;
    }
}
