<?php

namespace Tests\Feature;

use App\Exceptions\RpSistemasException;
use App\Models\Cliente;
use App\Services\RpSistemas\ClienteSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sync de clientes desde la tabla `CLIENTES` del ERP.
 *
 * Desde el 12/11/2026 se lee por SQL y no por `clientes.php`, cuya paginación
 * quedó rota (la página 2 viene vacía y recorriéndolas todas aparecen 1.968 de
 * 3.784 clientes): el sync anterior solo actualizaba 100 clientes por corrida y
 * el 3827 nunca entró. La lectura (`leer()`) necesita SQL Server y se verifica
 * contra el ERP real; acá se prueba lo que no lo necesita: el mapeo y la
 * escritura.
 */
class ClienteSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Una fila como la devuelve `leer()`: las columnas de `CLIENTES` ya con su alias. */
    private function fila(string $numero, string $razon = 'Empresa SA', array $extra = []): array
    {
        return [
            'numero' => $numero,
            'razon' => $razon,
            'nombre_fantasia' => null,
            'cuit' => '30-12345678-9',
            'iva' => '1',
            'telefono' => '01112345678',
            'mail' => 'test@empresa.com',
            'contacto' => null,
            'domicilio' => 'Av. Test 123',
            'localidad' => 'Buenos Aires',
            'codigo_provincia' => 'BA',
            'descripcion_provincia' => 'Buenos Aires',
            'porcen_descuen' => '.0000',
            'usuario_web' => 'C'.$numero,
            'codigo_vendedor' => '07',
            'nombre_vendedor' => 'TUBLOOD SA',
            'codigo_postal' => '1000',
            ...$extra,
        ];
    }

    private function sincronizar(array $filas): int
    {
        return (new ClienteSyncService)->escribir($filas, Carbon::parse('2026-11-12 10:00:00'));
    }

    // ── Mapeo ──────────────────────────────────────────────────────────────

    public function test_mapea_los_campos_del_erp_a_las_columnas_locales(): void
    {
        $c = (new ClienteSyncService)->mapear($this->fila('3827', 'CAROLINA ALEJANDRA MANERA ', [
            'iva' => '5', 'codigo_provincia' => 'COR', 'descripcion_provincia' => 'Cordoba', 'codigo_postal' => '5917',
        ]), Carbon::parse('2026-11-12 10:00:00'));

        $this->assertSame('3827', $c['numero']);
        // El espacio final se conserva: así lo guarda el ERP y así lo mostraba la API.
        $this->assertSame('CAROLINA ALEJANDRA MANERA ', $c['razon_social']);
        $this->assertSame('5', $c['codigo_iva']);
        $this->assertSame('Responsable Monotributo', $c['descripcion_iva']);
        $this->assertSame('COR', $c['codigo_provincia']);
        $this->assertSame('Cordoba', $c['descripcion_provincia']);
        $this->assertSame('C3827', $c['usuario_web']);
        $this->assertSame('07', $c['codigo_vendedor']);
        $this->assertSame('5917', $c['codigo_postal']);
        $this->assertSame(0.0, $c['porcen_descuen']);
    }

    public function test_las_cinco_condiciones_de_iva_conocidas(): void
    {
        $esperado = [
            '1' => 'Responsable Inscripto', '2' => 'Responsable No Inscripto', '3' => 'Exento',
            '4' => 'Consumidor Final', '5' => 'Responsable Monotributo',
        ];

        foreach ($esperado as $codigo => $descripcion) {
            $c = (new ClienteSyncService)->mapear($this->fila('1', 'X', ['iva' => $codigo]), Carbon::now());

            $this->assertSame($descripcion, $c['descripcion_iva'], "IVA {$codigo}");
        }
    }

    /** Un código que el mapa no conoce no se inventa: queda sin descripción. */
    public function test_un_codigo_de_iva_nuevo_queda_sin_descripcion(): void
    {
        $c = (new ClienteSyncService)->mapear($this->fila('1', 'X', ['iva' => '9']), Carbon::now());

        $this->assertSame('9', $c['codigo_iva']);
        $this->assertNull($c['descripcion_iva']);
    }

    public function test_los_vacios_del_erp_quedan_en_null(): void
    {
        $c = (new ClienteSyncService)->mapear($this->fila('1', 'X', [
            'nombre_fantasia' => '', 'contacto' => '   ', 'codigo_postal' => null, 'porcen_descuen' => null, 'iva' => null,
        ]), Carbon::now());

        $this->assertNull($c['nombre_fantasia']);
        $this->assertNull($c['contacto']);
        $this->assertNull($c['codigo_postal']);
        $this->assertNull($c['porcen_descuen']);
        $this->assertNull($c['codigo_iva']);
        $this->assertNull($c['descripcion_iva']);
    }

    /** `NUM_CLIENTE` es un int en SQL Server; localmente es el texto con el que se cruza. */
    public function test_el_numero_de_cliente_se_guarda_como_texto(): void
    {
        $c = (new ClienteSyncService)->mapear($this->fila('1', 'X', ['numero' => 3827]), Carbon::now());

        $this->assertSame('3827', $c['numero']);
    }

    // ── Escritura ──────────────────────────────────────────────────────────

    public function test_inserta_clientes_correctamente(): void
    {
        $total = $this->sincronizar([$this->fila('1', 'Empresa Uno')]);

        $this->assertSame(1, $total);
        $this->assertDatabaseHas('clientes', [
            'numero' => '1',
            'razon_social' => 'Empresa Uno',
            'codigo_postal' => '1000',
            'descripcion_iva' => 'Responsable Inscripto',
        ]);
    }

    /**
     * El ERP manda los datos comerciales; la clasificación documental es del
     * panel y la sync (cada 5 minutos) no la puede pisar.
     */
    public function test_actualiza_datos_del_erp_pero_preserva_los_campos_propios(): void
    {
        $cliente = Cliente::create([
            'numero' => '1',
            'razon_social' => 'Nombre Viejo SA',
            'fecha_vencimiento' => '2027-06-15',
            'tipo_cliente' => 'importador',
            'tiene_legajo' => true,
            'habilitado' => false,
            'notas' => 'Pidió prórroga.',
            'mail_nuevo' => 'nuevo@cliente.com',
            'documentacion_completa' => true,
        ]);

        $this->sincronizar([$this->fila('1', 'Nombre Nuevo SA')]);

        $cliente->refresh();
        $this->assertSame('Nombre Nuevo SA', $cliente->razon_social);
        $this->assertSame('2027-06-15', $cliente->fecha_vencimiento->toDateString());
        $this->assertSame('importador', $cliente->tipo_cliente);
        $this->assertTrue($cliente->tiene_legajo);
        $this->assertFalse($cliente->habilitado);
        $this->assertSame('Pidió prórroga.', $cliente->notas);
        $this->assertSame('nuevo@cliente.com', $cliente->mail_nuevo);
        $this->assertTrue($cliente->documentacion_completa);
    }

    public function test_es_idempotente(): void
    {
        $this->sincronizar([$this->fila('1', 'Empresa Uno')]);
        $this->sincronizar([$this->fila('1', 'Empresa Uno')]);

        $this->assertSame(1, Cliente::count());
    }

    /**
     * El caso que originó el cambio: el 3827 nunca entraba porque el sync solo
     * veía los 100 clientes de la primera página. Ahora entra con todos los demás.
     */
    public function test_un_cliente_nuevo_entra_junto_con_los_que_ya_estaban(): void
    {
        $this->sincronizar([$this->fila('3825'), $this->fila('3826')]);
        $this->sincronizar([$this->fila('3825'), $this->fila('3826'), $this->fila('3827', 'CAROLINA ALEJANDRA MANERA ')]);

        $this->assertSame(3, Cliente::count());
        $this->assertSame('CAROLINA ALEJANDRA MANERA ', Cliente::where('numero', '3827')->value('razon_social'));
    }

    /** ~3.800 filas × 21 columnas pasarían el tope de 65.535 parámetros de MySQL en un solo upsert. */
    public function test_escribe_por_bloques_cuando_son_muchos(): void
    {
        $filas = array_map(fn ($n) => $this->fila((string) $n), range(1, 1250));

        $this->assertSame(1250, $this->sincronizar($filas));
        $this->assertSame(1250, Cliente::count());
    }

    // ── Escribe solo lo que cambió ──────────────────────────────────────────

    /** Marca una fila como "vista hace tiempo": si la sync la toca, esa fecha cambia. */
    private function envejecer(string $numero): void
    {
        DB::table('clientes')->where('numero', $numero)->update(['synced_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
    }

    private function syncedAt(string $numero): string
    {
        return (string) DB::table('clientes')->where('numero', $numero)->value('synced_at');
    }

    /**
     * Reescribir los ~3.800 clientes en cada corrida eran ~560 MB diarios de log
     * binario de MySQL para refrescar una fecha. Sin novedades, no se escribe nada.
     */
    public function test_una_corrida_sin_novedades_no_escribe_nada(): void
    {
        $this->sincronizar([$this->fila('1'), $this->fila('2')]);
        $this->envejecer('1');
        $this->envejecer('2');

        $this->sincronizar([$this->fila('1'), $this->fila('2')]);

        $this->assertSame('2020-01-01 00:00:00', $this->syncedAt('1'));
        $this->assertSame('2020-01-01 00:00:00', $this->syncedAt('2'));
    }

    public function test_escribe_solo_el_cliente_que_cambio(): void
    {
        $this->sincronizar([$this->fila('1'), $this->fila('2')]);
        $this->envejecer('1');
        $this->envejecer('2');

        $this->sincronizar([$this->fila('1', 'Empresa SA', ['mail' => 'nuevo@empresa.com']), $this->fila('2')]);

        $this->assertSame('nuevo@empresa.com', Cliente::where('numero', '1')->value('mail'));
        $this->assertSame('2026-11-12 10:00:00', $this->syncedAt('1'));
        $this->assertSame('2020-01-01 00:00:00', $this->syncedAt('2'), 'el que no cambió no se toca');
    }

    /** El cambio de provincia o de vendedor sale de otra tabla del ERP y no mueve `FECHA_MODI`: igual se ve. */
    public function test_un_cambio_de_vendedor_o_provincia_tambien_se_detecta(): void
    {
        $this->sincronizar([$this->fila('1')]);
        $this->envejecer('1');

        $this->sincronizar([$this->fila('1', 'Empresa SA', ['nombre_vendedor' => 'OTRO VENDEDOR', 'descripcion_provincia' => 'Cordoba'])]);

        $cliente = Cliente::where('numero', '1')->first();
        $this->assertSame('OTRO VENDEDOR', $cliente->nombre_vendedor);
        $this->assertSame('Cordoba', $cliente->descripcion_provincia);
    }

    /** El cliente nuevo entra aunque todos los demás estén iguales. */
    public function test_un_cliente_nuevo_entra_y_los_iguales_no_se_tocan(): void
    {
        $this->sincronizar([$this->fila('1')]);
        $this->envejecer('1');

        $this->sincronizar([$this->fila('1'), $this->fila('2', 'Cliente Nuevo')]);

        $this->assertSame('Cliente Nuevo', Cliente::where('numero', '2')->value('razon_social'));
        $this->assertSame('2020-01-01 00:00:00', $this->syncedAt('1'));
    }

    /** El ERP manda ".0000" y la columna guarda 2 decimales: un 10,5 % no puede reescribirse en cada corrida. */
    public function test_el_porcentaje_de_descuento_no_provoca_reescrituras(): void
    {
        $this->sincronizar([$this->fila('1', 'Empresa SA', ['porcen_descuen' => '10.5000'])]);
        $this->envejecer('1');

        $this->sincronizar([$this->fila('1', 'Empresa SA', ['porcen_descuen' => '10.5000'])]);

        $this->assertSame('2020-01-01 00:00:00', $this->syncedAt('1'));
        $this->assertEquals(10.5, (float) Cliente::where('numero', '1')->value('porcen_descuen'));
    }

    /** Una corrida sin novedades no toca filas, así que el latido es lo único que dice que la sync está viva. */
    public function test_cada_corrida_deja_su_latido_aunque_no_escriba_nada(): void
    {
        $this->sincronizar([$this->fila('1')]);
        (new ClienteSyncService)->escribir([$this->fila('1')], Carbon::parse('2026-11-12 10:05:00'));

        $this->assertSame('2026-11-12 10:05:00', Cache::get(ClienteSyncService::CLAVE_ULTIMA_SYNC));
    }

    // ── Guarda contra lecturas incompletas ──────────────────────────────────

    /** Terminar "bien" con cero filas es justo el fallo silencioso que dejó el sync estancado. */
    public function test_una_lectura_vacia_falla_en_vez_de_terminar_bien(): void
    {
        $this->expectException(RpSistemasException::class);
        $this->expectExceptionMessage('no devolvió ningún cliente');

        $this->sincronizar([]);
    }

    public function test_una_lectura_mucho_mas_corta_que_lo_guardado_falla(): void
    {
        $this->sincronizar(array_map(fn ($n) => $this->fila((string) $n), range(1, 100)));

        $this->expectException(RpSistemasException::class);
        $this->expectExceptionMessage('vino incompleta');

        // Con 100 guardados, 10 filas es el 10 %: no es una baja normal.
        $this->sincronizar(array_map(fn ($n) => $this->fila((string) $n), range(1, 10)));
    }

    /** Un puñado de clientes que pasan a inactivos en el ERP es normal y no frena la sync. */
    public function test_una_baja_chica_no_frena_la_sync(): void
    {
        $this->sincronizar(array_map(fn ($n) => $this->fila((string) $n), range(1, 100)));

        $total = $this->sincronizar(array_map(fn ($n) => $this->fila((string) $n), range(1, 96)));

        $this->assertSame(96, $total);
        // La sync nunca borra: los 4 que ya no vienen quedan como estaban.
        $this->assertSame(100, Cliente::count());
    }
}
