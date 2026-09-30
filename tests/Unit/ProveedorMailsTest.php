<?php

namespace Tests\Unit;

use App\Models\Proveedor;
use Tests\TestCase;

/**
 * El accesor que separa las direcciones que el ERP guarda en una sola celda.
 *
 * No toca la base —`make()` no persiste nada— pero hereda de `Tests\TestCase`
 * igual: Eloquent necesita la app levantada para resolver la conexión, aunque
 * no llegue a usarla.
 */
class ProveedorMailsTest extends TestCase
{
    /** @return array<int, string> */
    private function mails(?string $valor): array
    {
        return Proveedor::make(['mail' => $valor])->mails;
    }

    public function test_separa_por_punto_y_coma(): void
    {
        $this->assertSame(
            ['pedidos@icna.com.ar', 'logistica@icna.com.ar', 'ventas@icna.com.ar'],
            $this->mails('pedidos@icna.com.ar;logistica@icna.com.ar;ventas@icna.com.ar')
        );
    }

    /** El ERP deja un espacio después del `;` en 12 de las filas con varias. */
    public function test_recorta_los_espacios_alrededor(): void
    {
        $this->assertSame(
            ['Biogrisa@hotmail.com', 'eduran@tublood.com'],
            $this->mails('Biogrisa@hotmail.com; eduran@tublood.com')
        );
    }

    /**
     * ⚠️ La regresión que importa: partir por espacios además de por `;`
     * destrozaría al proveedor 176, cuyo mail es un nombre para mostrar.
     * Quedarían cuatro pedazos y ninguno sería una dirección.
     */
    public function test_no_parte_un_mail_con_nombre_para_mostrar(): void
    {
        $this->assertSame(
            ['Hugo - Libertador <hlt@libertadorfactoring.com.ar>'],
            $this->mails('Hugo - Libertador <hlt@libertadorfactoring.com.ar>')
        );
    }

    public function test_un_solo_mail_devuelve_un_elemento(): void
    {
        $this->assertSame(['compras@propato.com.ar'], $this->mails('compras@propato.com.ar'));
    }

    /** Sin mail la lista es vacía, nunca `['']`: el listado se apoya en `length`. */
    public function test_vacio_y_null_dan_una_lista_vacia(): void
    {
        $this->assertSame([], $this->mails(null));
        $this->assertSame([], $this->mails(''));
        $this->assertSame([], $this->mails('   '));
    }

    /** Un `;` de más no tiene que dejar un elemento vacío en el medio. */
    public function test_descarta_los_tramos_vacios_y_reindexa(): void
    {
        $this->assertSame(
            ['a@b.com', 'c@d.com'],
            $this->mails('a@b.com;;c@d.com;')
        );
    }
}
