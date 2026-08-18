<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Proveedor;
use App\Services\RpSistemas\ProveedorSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * No se puede levantar un SQL Server en los tests (corren contra SQLite en
 * memoria), así que lo que se cubre acá es el **mapeo** de la vista a columnas
 * locales, que es donde se rompe todo cuando el ERP cambia `powerbi_proveedores_vista`.
 *
 * La adopción de huérfanos y el respeto por los campos propios se verifican
 * aplicando el resultado del mapeo contra la base local, sin tocar el ERP.
 */
class ProveedorSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Una fila como la devuelve la vista, con los nombres de columna reales. */
    private function fila(array $attrs = []): array
    {
        return array_merge([
            'NUM_PROV' => 1500,
            'RAZON' => 'PROPATO HNOS S A I C',
            'NOM_FANTASIA' => 'PROPATO',
            'DOMICILIO' => 'AV. CORRIENTES 1234',
            'CUIT' => '30-12345678-9',
            'TELEFONO' => '11 4444-5555',
            'CELULAR' => '11 6666-7777',
            'MAIL' => 'compras@propato.com.ar',
            'LOCALIDAD' => 'CABA',
            'PROVINCIA' => 'BUENOS AIRES',
            'CP' => '1414',
            'CONTACTO' => 'Juan Pérez',
            'ESTADO' => 'A',
            'FECHA_MODI' => '2026-08-14 14:48:00',
        ], $attrs);
    }

    private function service(): ProveedorSyncService
    {
        return new ProveedorSyncService;
    }

    public function test_mapea_las_columnas_de_la_vista(): void
    {
        $fila = $this->service()->mapear($this->fila(), Carbon::parse('2026-08-14 15:00:00'));

        $this->assertSame('1500', $fila['numero']);
        $this->assertSame('PROPATO HNOS S A I C', $fila['razon_social']);
        $this->assertSame('PROPATO', $fila['nombre_fantasia']);
        $this->assertSame('30-12345678-9', $fila['cuit']);
        $this->assertSame('CABA', $fila['localidad']);
        $this->assertSame('BUENOS AIRES', $fila['provincia']);
        $this->assertSame('Juan Pérez', $fila['contacto']);
        $this->assertSame('A', $fila['estado']);
        $this->assertSame('2026-08-14 14:48:00', $fila['modificado_en']);
    }

    /** El ERP rellena los `char` con espacios y usa cadenas vacías en vez de null. */
    public function test_convierte_vacios_y_espacios_en_null(): void
    {
        $fila = $this->service()->mapear($this->fila([
            'MAIL' => '',
            'ESTADO' => ' ',
            'CONTACTO' => null,
            'FECHA_MODI' => null,
        ]), Carbon::now());

        $this->assertNull($fila['mail']);
        $this->assertNull($fila['estado']);
        $this->assertNull($fila['contacto']);
        $this->assertNull($fila['modificado_en']);
    }

    /** El número llega como int desde SQL Server y la columna local es string. */
    public function test_el_numero_llega_como_entero_y_se_guarda_como_texto(): void
    {
        $fila = $this->service()->mapear($this->fila(['NUM_PROV' => 933]), Carbon::now());

        $this->assertSame('933', $fila['numero']);
    }

    /**
     * La regla de oro del proyecto: los campos propios del panel no entran en la
     * lista de columnas del upsert.
     */
    public function test_el_upsert_no_incluye_observaciones(): void
    {
        $proveedor = Proveedor::create([
            'numero' => '1500',
            'razon_social' => 'VIEJA RAZON',
            'observaciones' => 'Entrega los martes.',
        ]);

        $fila = $this->service()->mapear($this->fila(), Carbon::now());

        Proveedor::upsert([$fila], ['numero'], [
            'razon_social', 'nombre_fantasia', 'domicilio', 'cuit',
            'telefono', 'celular', 'mail', 'localidad', 'provincia',
            'codigo_postal', 'contacto', 'estado', 'modificado_en',
            'synced_at', 'updated_at',
        ]);

        $proveedor->refresh();
        $this->assertSame('PROPATO HNOS S A I C', $proveedor->razon_social);
        $this->assertSame('Entrega los martes.', $proveedor->observaciones);
    }

    /**
     * La regresión más importante: los proveedores que creó el Excel de
     * artículos no tienen número, y hay artículos apuntándoles. Si el sync no
     * los adopta, se duplican y esos artículos quedan colgados de un fantasma.
     */
    public function test_adopta_al_proveedor_sin_numero_conservando_su_id(): void
    {
        $huerfano = Proveedor::create(['razon_social' => 'PROPATO HNOS. S.A.I.C.']);
        $articulo = Articulo::create([
            'codigo' => 'RE-4680',
            'descripcion' => 'DEA PAD ADULTO',
            'proveedor_id' => $huerfano->id,
        ]);

        $this->adoptar([$this->fila()]);

        $huerfano->refresh();
        $this->assertSame('1500', $huerfano->numero);
        $this->assertSame(1, Proveedor::count());
        $this->assertSame($huerfano->id, $articulo->fresh()->proveedor_id);
    }

    public function test_no_adopta_si_la_razon_social_no_matchea(): void
    {
        $huerfano = Proveedor::create(['razon_social' => 'OTRA EMPRESA SRL']);

        $this->adoptar([$this->fila()]);

        $this->assertNull($huerfano->fresh()->numero);
    }

    /** Ponerle el número al que no era es peor que dejarlo suelto. */
    public function test_no_adopta_si_dos_filas_del_erp_normalizan_igual(): void
    {
        $huerfano = Proveedor::create(['razon_social' => 'HILOS TUCUMAN SRL']);

        $this->adoptar([
            $this->fila(['NUM_PROV' => 10, 'RAZON' => 'HILOS TUCUMAN SRL']),
            $this->fila(['NUM_PROV' => 11, 'RAZON' => 'HILOS TUCUMAN S.R.L.']),
        ]);

        $this->assertNull($huerfano->fresh()->numero);
    }

    /** Si el número ya está tomado localmente, adoptar violaría el índice único. */
    public function test_no_adopta_si_el_numero_ya_existe_en_otra_fila(): void
    {
        Proveedor::create(['numero' => '1500', 'razon_social' => 'PROPATO HNOS S A I C']);
        $huerfano = Proveedor::create(['razon_social' => 'PROPATO HNOS. S.A.I.C.']);

        $this->adoptar([$this->fila()]);

        $this->assertNull($huerfano->fresh()->numero);
        $this->assertSame(2, Proveedor::count());
    }

    /** Ejecuta solo la adopción, que es privada, sin tocar el ERP. */
    private function adoptar(array $filas): void
    {
        $service = $this->service();
        $metodo = new \ReflectionMethod($service, 'adoptarSinNumero');
        $metodo->invoke($service, collect($filas)->map(fn ($f) => (object) $f));
    }
}
