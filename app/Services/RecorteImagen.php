<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Le saca a una imagen los márgenes transparentes de alrededor.
 *
 * Existe por un problema concreto: un logo exportado con el lienzo más grande
 * que el dibujo se ve chico en pantalla sin que haya nada mal en el CSS. El
 * primer logo cargado tenía 2000x1414 de lienzo con el logo ocupando 1741x621
 * en el medio — el 56% del alto era aire, así que pedirle 40px de alto dejaba
 * 17px de logo y 23px de nada.
 *
 * Recortarlo al subir lo arregla para siempre y para cualquier archivo que
 * venga después, sin depender de cómo lo haya exportado cada quien.
 *
 * ⚠️ Solo toca formatos rasterizados con canal alfa (PNG y WebP). Un SVG no se
 * puede recortar con GD y encima no lo necesita (escala solo); un JPG no tiene
 * transparencia, así que "recortar el margen" implicaría adivinar qué es fondo
 * y qué no — y equivocarse ahí es comerse parte del logo.
 */
class RecorteImagen
{
    /** Debajo de esto un píxel se considera transparente (0 = opaco, 127 = invisible). */
    private const UMBRAL_ALFA = 100;

    /**
     * Recorta el archivo en su lugar. Devuelve true si lo modificó.
     *
     * Nunca lanza: es un retoque cosmético dentro de una subida que ya
     * funcionó. Si algo falla, el archivo original queda intacto y se loguea —
     * es preferible un logo con aire a una subida rota.
     */
    public function recortar(string $path): bool
    {
        try {
            return $this->intentar($path);
        } catch (\Throwable $e) {
            Log::warning('RecorteImagen: no se pudo recortar', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function intentar(string $path): bool
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return false;
        }

        [$ancho, $alto] = $info;

        $imagen = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => null,
        };

        if (! $imagen) {
            return false;
        }

        $caja = $this->cajaVisible($imagen, $ancho, $alto);

        if ($caja === null) {
            // Imagen entera transparente: no hay nada que recortar y dejarla en
            // 0x0 rompería todo lo que la muestre.
            imagedestroy($imagen);

            return false;
        }

        [$x1, $y1, $x2, $y2] = $caja;
        $nuevoAncho = $x2 - $x1 + 1;
        $nuevoAlto = $y2 - $y1 + 1;

        // Sin margen que sacar, no se reescribe el archivo: reescribirlo solo
        // recomprimiría la imagen y le bajaría la calidad sin ganar nada.
        if ($nuevoAncho === $ancho && $nuevoAlto === $alto) {
            imagedestroy($imagen);

            return false;
        }

        $recortada = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

        // Sin esto el recorte sale con fondo negro en vez de transparente.
        imagealphablending($recortada, false);
        imagesavealpha($recortada, true);
        imagefill($recortada, 0, 0, imagecolorallocatealpha($recortada, 0, 0, 0, 127));

        imagecopy($recortada, $imagen, 0, 0, $x1, $y1, $nuevoAncho, $nuevoAlto);

        $ok = $info[2] === IMAGETYPE_WEBP
            ? imagewebp($recortada, $path)
            : imagepng($recortada, $path);

        imagedestroy($imagen);
        imagedestroy($recortada);

        return $ok;
    }

    /**
     * Los límites del contenido no transparente, o null si está todo vacío.
     *
     * Recorre por filas y columnas desde los bordes hacia adentro en vez de
     * mirar los ancho*alto píxeles: en una imagen con mucho margen (que es
     * justo el caso que esto resuelve) corta muchísimo antes.
     *
     * @return array{int, int, int, int}|null [x1, y1, x2, y2]
     */
    private function cajaVisible(\GdImage $imagen, int $ancho, int $alto): ?array
    {
        $filaVacia = function (int $y) use ($imagen, $ancho): bool {
            for ($x = 0; $x < $ancho; $x++) {
                if (((imagecolorat($imagen, $x, $y) >> 24) & 0x7F) < self::UMBRAL_ALFA) {
                    return false;
                }
            }

            return true;
        };

        $columnaVacia = function (int $x) use ($imagen, $alto): bool {
            for ($y = 0; $y < $alto; $y++) {
                if (((imagecolorat($imagen, $x, $y) >> 24) & 0x7F) < self::UMBRAL_ALFA) {
                    return false;
                }
            }

            return true;
        };

        $y1 = 0;
        while ($y1 < $alto && $filaVacia($y1)) {
            $y1++;
        }

        if ($y1 === $alto) {
            return null;
        }

        $y2 = $alto - 1;
        while ($y2 > $y1 && $filaVacia($y2)) {
            $y2--;
        }

        $x1 = 0;
        while ($x1 < $ancho && $columnaVacia($x1)) {
            $x1++;
        }

        $x2 = $ancho - 1;
        while ($x2 > $x1 && $columnaVacia($x2)) {
            $x2--;
        }

        return [$x1, $y1, $x2, $y2];
    }
}
