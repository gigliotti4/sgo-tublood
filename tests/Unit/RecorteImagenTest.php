<?php

namespace Tests\Unit;

use App\Services\RecorteImagen;
use PHPUnit\Framework\TestCase;

class RecorteImagenTest extends TestCase
{
    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archivo = sys_get_temp_dir().'/recorte-test-'.uniqid().'.png';
    }

    protected function tearDown(): void
    {
        if (is_file($this->archivo)) {
            unlink($this->archivo);
        }
        parent::tearDown();
    }

    /**
     * PNG transparente con un rectángulo opaco en la posición indicada.
     */
    private function png(int $ancho, int $alto, ?array $contenido = null): void
    {
        $im = imagecreatetruecolor($ancho, $alto);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));

        if ($contenido) {
            [$x1, $y1, $x2, $y2] = $contenido;
            imagefilledrectangle($im, $x1, $y1, $x2, $y2, imagecolorallocate($im, 255, 0, 0));
        }

        imagepng($im, $this->archivo);
        imagedestroy($im);
    }

    /** @return array{int, int} ancho y alto del archivo */
    private function medidas(): array
    {
        $i = getimagesize($this->archivo);

        return [$i[0], $i[1]];
    }

    /** El caso real: un logo chico en un lienzo grande. */
    public function test_recorta_los_margenes_transparentes(): void
    {
        // 200x100 de lienzo, con el "logo" de 80x20 en el medio.
        $this->png(200, 100, [60, 40, 139, 59]);

        $this->assertTrue((new RecorteImagen)->recortar($this->archivo));
        $this->assertSame([80, 20], $this->medidas());
    }

    /** Sin margen que sacar no reescribe: recomprimir bajaría la calidad a cambio de nada. */
    public function test_no_toca_una_imagen_que_ya_esta_ajustada(): void
    {
        $this->png(50, 30, [0, 0, 49, 29]);

        $this->assertFalse((new RecorteImagen)->recortar($this->archivo));
        $this->assertSame([50, 30], $this->medidas());
    }

    /** Una imagen entera transparente no puede quedar en 0x0. */
    public function test_no_recorta_una_imagen_completamente_transparente(): void
    {
        $this->png(40, 40);

        $this->assertFalse((new RecorteImagen)->recortar($this->archivo));
        $this->assertSame([40, 40], $this->medidas());
    }

    /** El recorte conserva la transparencia; si no, saldría con fondo negro. */
    public function test_conserva_la_transparencia(): void
    {
        // Contenido en L: deja una esquina transparente dentro de la caja recortada.
        $im = imagecreatetruecolor(100, 100);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $rojo = imagecolorallocate($im, 255, 0, 0);
        imagefilledrectangle($im, 20, 20, 40, 79, $rojo);
        imagefilledrectangle($im, 20, 60, 79, 79, $rojo);
        imagepng($im, $this->archivo);
        imagedestroy($im);

        $this->assertTrue((new RecorteImagen)->recortar($this->archivo));

        $recortada = imagecreatefrompng($this->archivo);
        // Esquina superior derecha de la caja: quedó fuera de la L.
        $alfa = (imagecolorat($recortada, 55, 5) >> 24) & 0x7F;
        imagedestroy($recortada);

        $this->assertGreaterThan(100, $alfa, 'El recorte tiene que seguir siendo transparente, no negro.');
    }

    /** Un archivo que no es imagen no puede tirar una excepción: la subida ya funcionó. */
    public function test_un_archivo_invalido_no_rompe(): void
    {
        file_put_contents($this->archivo, 'esto no es una imagen');

        $this->assertFalse((new RecorteImagen)->recortar($this->archivo));
    }
}
