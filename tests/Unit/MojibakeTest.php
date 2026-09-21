<?php

namespace Tests\Unit;

use App\Support\Mojibake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El reparador de texto doble-codificado.
 *
 * Lo que importa no es solo que arregle "AsociaciÃ³n": es que **no toque** el
 * texto que ya está bien. Un `mb_convert_encoding()` a ciegas destruiría
 * "BAÑO", y ese es el modo de falla peligroso, porque rompería datos sanos en
 * silencio cada cinco minutos.
 */
class MojibakeTest extends TestCase
{
    public static function rotos(): array
    {
        return [
            'ó' => ['AsociaciÃ³n Mutual', 'Asociación Mutual'],
            'é' => ['FarmacÃ©uticos', 'Farmacéuticos'],
            'í' => ['BioquÃ­micos', 'Bioquímicos'],
            // La Ñ es el caso feo: el segundo byte cae en un carácter de
            // control invisible, así que en pantalla se ve "ALVARIÃO".
            'Ñ' => ["ALVARI\u{00C3}\u{0091}O MACARENA", 'ALVARIÑO MACARENA'],
            'Ó' => ["AVENIDA GENERAL L\u{00C3}\u{0093}PEZ", 'AVENIDA GENERAL LÓPEZ'],
            // Ojo el par: la Ü mayúscula lleva un carácter de control invisible
            // como segundo byte, mientras que Ã¼ da la ü minúscula. Son dos
            // caracteres distintos, no variantes del mismo.
            'Ü' => ["AG\u{00C3}\u{009C}ERO SRL", 'AGÜERO SRL'],
            'ü' => ["AG\u{00C3}\u{00BC}ERO SRL", 'AGüERO SRL'],
            // Espacio duro: no hay ninguna letra acentuada en el resultado y
            // aun así hay que repararlo.
            'espacio duro' => ["BECCAR\u{00C2}\u{00A0} (Susp)", "BECCAR\u{00A0} (Susp)"],
        ];
    }

    #[DataProvider('rotos')]
    public function test_repara_el_texto_doble_codificado(string $roto, string $esperado): void
    {
        $this->assertSame($esperado, Mojibake::reparar($roto));
    }

    public static function sanos(): array
    {
        return [
            'ascii' => ['LABORATORIO CENTRAL SRL'],
            // Estos tres salen de `articulos.descripcion`, que viene del mismo
            // ERP y llega bien: son la prueba de que no se puede convertir a
            // ciegas.
            'ñ mayúscula' => ['BAÑO TERMOSTATICO DE 13L'],
            'í acentuada' => ['BOMBA ELASTOMERICA DE 2 DÍAS'],
            'ñ minúscula' => ['CAMISOLIN DE TELA SMS C/PUÑO ELASTICO'],
            'ü' => ['AGÜERO SHAMAIM SRL'],
            'ya reparado' => ['Asociación Mutual Farbiq de Farmacéuticos'],
            // Ã seguida de algo que NO forma UTF-8 válido: se deja como está.
            'Ã legítima' => ['SÃO PAULO COMERCIO'],
            'vacío' => [''],
            'numérico' => ['30-66320340-8'],
            'con salto de línea' => ["SANTAMARIA SAS\n"],
            // Arriba de U+00FF la conversión a Latin-1 sería con pérdida.
            'fuera de Latin-1' => ['Cliente ☎ Ñandú'],
        ];
    }

    #[DataProvider('sanos')]
    public function test_no_toca_el_texto_que_ya_esta_bien(string $sano): void
    {
        $this->assertSame($sano, Mojibake::reparar($sano));
    }

    /** La sync corre cada 5 minutos: repararlo de nuevo no puede moverlo otra vez. */
    public function test_es_idempotente(): void
    {
        $unaVez = Mojibake::reparar('AsociaciÃ³n Mutual FarmacÃ©uticos');

        $this->assertSame('Asociación Mutual Farmacéuticos', $unaVez);
        $this->assertSame($unaVez, Mojibake::reparar($unaVez));
        $this->assertSame($unaVez, Mojibake::reparar(Mojibake::reparar($unaVez)));
    }

    public function test_deja_pasar_el_null(): void
    {
        $this->assertNull(Mojibake::reparar(null));
    }

    /** Texto que ni siquiera es UTF-8 válido no es este problema: no se toca. */
    public function test_no_toca_lo_que_no_es_utf8_valido(): void
    {
        $invalido = "Asociaci\xF3n";

        $this->assertSame($invalido, Mojibake::reparar($invalido));
    }

    public function test_repara_los_arreglos_anidados_y_respeta_los_no_strings(): void
    {
        $reparado = Mojibake::repararArreglo([
            'razon' => 'AsociaciÃ³n Mutual',
            'codigo_iva' => 1,
            'porcen_descuen' => 0.0,
            'nulo' => null,
            'anidado' => ['localidad' => 'NeuquÃ©n', 'ok' => 'BAÑO'],
        ]);

        $this->assertSame('Asociación Mutual', $reparado['razon']);
        $this->assertSame('Neuquén', $reparado['anidado']['localidad']);
        $this->assertSame('BAÑO', $reparado['anidado']['ok']);
        $this->assertSame(1, $reparado['codigo_iva']);
        $this->assertSame(0.0, $reparado['porcen_descuen']);
        $this->assertNull($reparado['nulo']);
    }
}
