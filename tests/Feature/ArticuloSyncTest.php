<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Services\RpSistemas\ArticuloSyncService;
use App\Services\RpSistemas\RpSistemasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticuloSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Respuesta con el envoltorio real de articulos.php. */
    private function respuesta(array $articulos, string $tieneSiguiente = '0'): array
    {
        return [[
            'datos_recibidos' => [],
            'info' => [
                'servicio' => 'articulos.php',
                'estado' => 200,
                'datos' => $articulos,
                'datos_paginado' => [[
                    'total_registros' => (string) count($articulos),
                    'pagina_actual' => '1',
                    'tamano_pagina' => (string) count($articulos),
                    'tiene_pagina_siguiente' => $tieneSiguiente,
                    'tiene_pagina_anterior' => '0',
                ]],
            ],
        ]];
    }

    private function articulo(array $attrs = []): array
    {
        return [
            'codigo_articulo' => 'RE-1631',
            'descripcion_articulo' => 'AGUJA 40/12 TERUMO',
            'descripcion_adicional' => '',
            'stock' => 12,
            'cantidad_reservada' => 2,
            'stock_disponible' => 10,
            'precio_venta' => 19.89,
            'UM' => 'UNI',
            'codigo_agrupacion_1' => 'DIS',
            'descripcion_agrupacion_1' => 'DISTRIBUCION',
            'deposito' => '',
            'fecha_modi' => '2026-04-22 11:55:54.807',
            'codigo_proveedor' => 'PRO1',
            'codigo_barras' => '011011528-1',
            ...$attrs,
        ];
    }

    /** @return array{procesados: int, activos: int, desactivados: int} */
    private function sincronizar(): array
    {
        return (new ArticuloSyncService(new RpSistemasClient))->sync();
    }

    public function test_guarda_los_articulos_del_erp(): void
    {
        Http::fake(['*articulos.php' => Http::response($this->respuesta([$this->articulo()]))]);

        $this->assertSame(1, $this->sincronizar()['procesados']);

        $a = Articulo::first();
        $this->assertSame('RE-1631', $a->codigo);
        $this->assertSame('AGUJA 40/12 TERUMO', $a->descripcion);
        $this->assertSame('UNI', $a->unidad_medida);
        $this->assertSame('011011528-1', $a->codigo_barras);
        $this->assertSame('DIS', $a->codigo_agrupacion_1);
        $this->assertSame('10.0000', $a->stock_disponible);
        $this->assertSame('2026-04-22 11:55:54', $a->modificado_en->toDateTimeString());
    }

    /**
     * articulos.php devuelve el texto con doble codificación: toma bytes UTF-8
     * y los re-codifica como latin-1, así que "1½" llega como "1Â½". Afecta al
     * ~97% del catálogo real.
     */
    public function test_corrige_el_doble_encoding_del_texto(): void
    {
        Http::fake(['*articulos.php' => Http::response($this->respuesta([
            $this->articulo([
                // "1½": el ½ (U+00BD) sobrevive tal cual, precedido de Â.
                'descripcion_articulo' => "AGUJA 40/12 (18G x 1\u{00C2}\u{00BD}) TERUMO",
                // "Ó" (C3 93) se parte en Ã (U+00C3) + U+0093, que es un
                // carácter de control: la forma real en la que llega el dato.
                'descripcion_agrupacion_1' => "DISTRIBUCI\u{00C3}\u{0093}N",
            ]),
        ]))]);

        $this->sincronizar();

        $a = Articulo::first();
        $this->assertSame('AGUJA 40/12 (18G x 1½) TERUMO', $a->descripcion);
        $this->assertSame('DISTRIBUCIÓN', $a->descripcion_agrupacion_1);
    }

    /**
     * La otra variante: el byte 0x80-0x9F llegó como su carácter de CP1252
     * (comilla tipográfica) en vez de como control. Hay que cubrir las dos.
     */
    public function test_corrige_tambien_la_variante_cp1252(): void
    {
        Http::fake(['*articulos.php' => Http::response($this->respuesta([
            $this->articulo(['descripcion_articulo' => "IMPORTACI\u{00C3}\u{201C}N"]),
        ]))]);

        $this->sincronizar();

        $this->assertSame('IMPORTACIÓN', Articulo::first()->descripcion);
    }

    /** Un texto que ya viene bien no se tiene que romper al "corregirlo". */
    public function test_no_toca_el_texto_que_ya_esta_bien(): void
    {
        Http::fake(['*articulos.php' => Http::response($this->respuesta([
            $this->articulo(['descripcion_articulo' => 'ALCOHOL AL 70° X 1000CC']),
        ]))]);

        $this->sincronizar();

        $this->assertSame('ALCOHOL AL 70° X 1000CC', Articulo::first()->descripcion);
    }

    public function test_es_idempotente_y_actualiza_por_codigo(): void
    {
        // Secuencia y no dos Http::fake(): con el mismo patrón, Laravel se
        // queda con el primer stub registrado y la segunda corrida vería lo viejo.
        Http::fake(['*articulos.php' => Http::sequence()
            ->push($this->respuesta([$this->articulo(['descripcion_articulo' => 'Descripción vieja'])]))
            ->push($this->respuesta([$this->articulo(['descripcion_articulo' => 'Descripción nueva', 'stock_disponible' => 3])])),
        ]);

        $this->sincronizar();
        $this->sincronizar();

        $this->assertSame(1, Articulo::count());
        $this->assertSame('Descripción nueva', Articulo::first()->descripcion);
        $this->assertSame('3.0000', Articulo::first()->stock_disponible);
    }

    /**
     * Los errores de articulos.php no vienen con el envoltorio habitual, sino
     * como {"success":false,...}. Tiene que llegar el motivo real.
     */
    public function test_reporta_el_error_propio_de_articulos(): void
    {
        Http::fake(['*articulos.php' => Http::response([
            'success' => false,
            'code' => 400,
            'message' => 'Debe ingresar codigo de lista de precios',
            'info' => ['servicio' => 'articulos.php'],
        ])]);

        $this->expectExceptionMessage('Debe ingresar codigo de lista de precios');
        $this->sincronizar();
    }

    /**
     * El contrato central de los cuatro campos propios: el sync trae datos
     * nuevos del ERP pero no los toca. Mismo test que
     * ClienteSyncTest::test_sync_actualiza_datos_del_erp_pero_preserva_fecha_vencimiento.
     */
    public function test_sync_actualiza_datos_del_erp_pero_preserva_los_campos_propios(): void
    {
        Articulo::create([
            'codigo' => 'RE-1631',
            'descripcion' => 'Descripción vieja',
            'fecha_vencimiento' => '2030-10-06',
            'pm' => '236-80',
            'legajo' => '133',
            'observaciones' => 'Cargado a mano por Calidad',
        ]);

        Http::fake(['*articulos.php' => Http::response($this->respuesta([
            $this->articulo(['descripcion_articulo' => 'Descripción nueva del ERP']),
        ]))]);

        $this->sincronizar();

        $a = Articulo::first();
        $this->assertSame('Descripción nueva del ERP', $a->descripcion);
        $this->assertSame('2030-10-06', $a->fecha_vencimiento->toDateString());
        $this->assertSame('236-80', $a->pm);
        $this->assertSame('133', $a->legajo);
        $this->assertSame('Cargado a mano por Calidad', $a->observaciones);
    }

    /** El buscador del selector tiene que encontrar por código y por descripción. */
    public function test_el_buscador_encuentra_por_codigo_y_descripcion(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO']);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'GASA ESTERIL']);

        $this->assertSame(['RE-1631'], Articulo::buscar('RE-1631')->pluck('codigo')->all());
        $this->assertSame(['RE-999'], Articulo::buscar('gasa')->pluck('codigo')->all());
        $this->assertSame([], Articulo::buscar('inexistente')->pluck('codigo')->all());
    }

    /** Todo lo que vino en el feed de esta corrida queda activo. */
    public function test_lo_que_viene_en_el_feed_queda_activo(): void
    {
        Http::fake(['*articulos.php' => Http::response($this->respuesta([$this->articulo()]))]);

        $resultado = $this->sincronizar();

        $this->assertTrue(Articulo::first()->activo);
        $this->assertSame(1, $resultado['activos']);
        $this->assertSame(0, $resultado['desactivados']);
    }

    /** Un artículo que estaba y deja de venir en la corrida siguiente queda desactivado. */
    public function test_un_articulo_que_deja_de_venir_se_desactiva(): void
    {
        Http::fake(['*articulos.php' => Http::sequence()
            ->push($this->respuesta([
                $this->articulo(['codigo_articulo' => 'RE-1631']),
                $this->articulo(['codigo_articulo' => 'RE-999']),
            ]))
            ->push($this->respuesta([
                $this->articulo(['codigo_articulo' => 'RE-1631']),
            ])),
        ]);

        $this->sincronizar();
        $resultado = $this->sincronizar();

        $this->assertTrue(Articulo::where('codigo', 'RE-1631')->first()->activo);
        $this->assertFalse(Articulo::where('codigo', 'RE-999')->first()->activo);
        $this->assertSame(1, $resultado['desactivados']);
    }

    /**
     * Los artículos que solo cargó el Excel de Calidad ("crear faltantes",
     * synced_at null) no vienen de ningún feed, así que no pueden "dejar de
     * venir": tienen que seguir activos aunque RP no los mencione.
     */
    public function test_un_articulo_creado_solo_por_excel_sigue_activo(): void
    {
        Articulo::create([
            'codigo' => 'EXCEL-1',
            'descripcion' => 'Cargado a mano por Calidad',
            'synced_at' => null,
        ]);

        Http::fake(['*articulos.php' => Http::response($this->respuesta([$this->articulo()]))]);

        $this->sincronizar();

        $this->assertTrue(Articulo::where('codigo', 'EXCEL-1')->first()->activo);
    }

    /** Un feed vacío no puede dejar el selector de productos sin nada: no desactiva nada. */
    public function test_un_feed_vacio_no_desactiva_nada(): void
    {
        // Secuencia, no dos Http::fake(): con el mismo patrón, Laravel se
        // queda con el primer stub registrado y la segunda corrida vería lo viejo.
        Http::fake(['*articulos.php' => Http::sequence()
            ->push($this->respuesta([$this->articulo()]))
            ->push($this->respuesta([])),
        ]);

        $this->sincronizar();
        $resultado = $this->sincronizar();

        $this->assertTrue(Articulo::first()->activo);
        $this->assertSame(0, $resultado['procesados']);
        $this->assertSame(0, $resultado['desactivados']);
    }

    /** Un artículo desactivado que vuelve a aparecer en el feed se reactiva solo. */
    public function test_un_articulo_desactivado_que_vuelve_al_feed_se_reactiva(): void
    {
        Http::fake(['*articulos.php' => Http::sequence()
            ->push($this->respuesta([
                $this->articulo(['codigo_articulo' => 'RE-1631']),
                $this->articulo(['codigo_articulo' => 'RE-999']),
            ]))
            ->push($this->respuesta([
                $this->articulo(['codigo_articulo' => 'RE-1631']),
            ]))
            ->push($this->respuesta([
                $this->articulo(['codigo_articulo' => 'RE-1631']),
                $this->articulo(['codigo_articulo' => 'RE-999']),
            ])),
        ]);

        $this->sincronizar();
        $this->sincronizar();
        $this->sincronizar();

        $this->assertTrue(Articulo::where('codigo', 'RE-999')->first()->activo);
    }

    /** El buscador público (articulos.buscar) no puede ofrecer un artículo discontinuado. */
    public function test_el_buscador_publico_no_devuelve_articulos_inactivos(): void
    {
        Articulo::create(['codigo' => 'RE-1631', 'descripcion' => 'AGUJA 40/12 TERUMO', 'activo' => true]);
        Articulo::create(['codigo' => 'RE-999', 'descripcion' => 'AGUJA DESCARTABLE', 'activo' => false]);

        $response = $this->getJson('/articulos/buscar?q=aguja');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['codigo' => 'RE-1631']);
        $response->assertJsonMissing(['codigo' => 'RE-999']);
    }
}
