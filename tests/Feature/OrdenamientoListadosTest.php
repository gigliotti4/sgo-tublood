<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Observacion;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Comportamiento transversal del ordenamiento por columna de los listados.
 *
 * Lo resuelve el trait `App\Http\Controllers\Concerns\OrdenaListados`, y lo que
 * fijan estos tests son sus cuatro reglas: whitelist obligatoria, caída
 * silenciosa al orden por defecto, desempate por `id` y el orden que vuelve a
 * la vista ya validado.
 */
class OrdenamientoListadosTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function articulo(string $codigo, string $descripcion): Articulo
    {
        return Articulo::create([
            'codigo' => $codigo,
            'descripcion' => $descripcion,
            'activo' => true,
        ]);
    }

    // ── Reglas del trait ────────────────────────────────────────────────────

    public function test_ordena_por_una_columna_de_la_whitelist(): void
    {
        $this->articulo('B-2', 'Zapallo');
        $this->articulo('A-1', 'Aguja');

        $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos?sort=codigo&dir=asc')
            ->assertInertia(fn ($page) => $page
                ->where('articulos.data.0.codigo', 'A-1')
                ->where('orden.sort', 'codigo')
                ->where('orden.dir', 'asc'));
    }

    public function test_invertir_la_direccion_invierte_el_listado(): void
    {
        $this->articulo('B-2', 'Zapallo');
        $this->articulo('A-1', 'Aguja');

        $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos?sort=codigo&dir=desc')
            ->assertInertia(fn ($page) => $page->where('articulos.data.0.codigo', 'B-2'));
    }

    /**
     * Los listados se comparten por link: un `?sort=` de una versión anterior
     * tiene que seguir abriendo la pantalla, no romperla. Y `orden.sort` vuelve
     * en null para que la flecha del encabezado no mienta.
     */
    public function test_un_sort_desconocido_cae_al_orden_por_defecto_y_no_da_422(): void
    {
        $this->articulo('B-2', 'Zapallo');
        $this->articulo('A-1', 'Aguja');

        $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos?sort=password&dir=asc')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                // El orden por defecto de esta pantalla es por descripción.
                ->where('articulos.data.0.descripcion', 'Aguja')
                ->where('orden.sort', null));
    }

    /**
     * El mismo caso sobre un listado que **valida** sus filtros: sin las reglas
     * de `sort`/`dir` en `reglasDeFiltros()`, `validate()` los descartaría en
     * silencio y el ordenamiento no haría nada.
     */
    public function test_un_sort_desconocido_tampoco_rompe_un_listado_que_valida_filtros(): void
    {
        $this->actingAs($this->userWith('observaciones.view'))
            ->get('/observaciones?sort=password&dir=asc')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('orden.sort', null));
    }

    public function test_un_listado_que_valida_filtros_si_acepta_su_whitelist(): void
    {
        $this->actingAs($this->userWith('observaciones.view'))
            ->get('/observaciones?sort=numero&dir=asc')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('orden.sort', 'numero')
                ->where('orden.dir', 'asc'));
    }

    public function test_un_dir_invalido_cae_a_desc(): void
    {
        $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos?sort=codigo&dir=cualquiera')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('orden.dir', 'desc'));
    }

    /**
     * Sin el desempate por `id`, dos filas con el mismo valor en la columna
     * ordenada pueden salir en distinto orden en cada página y la paginación
     * repite o saltea registros.
     */
    public function test_el_desempate_por_id_hace_estable_la_paginacion(): void
    {
        // 60 artículos con el MISMO estado: la columna ordenada está empatada
        // de punta a punta, que es el caso que rompe sin desempate.
        foreach (range(1, 60) as $i) {
            $this->articulo(sprintf('ART-%03d', $i), 'Artículo '.$i);
        }

        $user = $this->userWith('articulos.view');

        $pagina = function (int $n) use ($user) {
            $ids = [];
            $this->actingAs($user)
                ->get("/articulos?sort=estado&dir=asc&page={$n}")
                ->assertInertia(function ($page) use (&$ids) {
                    $ids = array_column($page->toArray()['props']['articulos']['data'], 'id');

                    return $page;
                });

            return $ids;
        };

        $this->assertEmpty(array_intersect($pagina(1), $pagina(2)));
    }

    // ── Ventas: el orden no puede filtrar los montos ────────────────────────

    private function venta(string $compro, float $subTotal): Venta
    {
        return Venta::create([
            'compro_nro' => $compro,
            'cod_comprobante' => 'FA',
            'fecha' => '2026-01-01',
            'articulo' => 'ART-1',
            'cantidad' => 1,
            'sub_total' => $subTotal,
        ]);
    }

    /**
     * Con `?sort=sub_total&dir=desc` la primera fila es la venta más cara, que
     * es justo lo que el permiso `ventas.montos` esconde. Por eso la clave solo
     * entra en la whitelist cuando el permiso está.
     */
    public function test_ventas_no_ordena_por_subtotal_sin_el_permiso(): void
    {
        $this->venta('FA-00000001', 100);
        $this->venta('FA-00000002', 900);

        $this->actingAs($this->userWith('ventas.view'))
            ->get('/ventas?sort=sub_total&dir=desc')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->where('orden.sort', null));
    }

    public function test_ventas_si_ordena_por_subtotal_con_el_permiso(): void
    {
        $this->venta('FA-00000001', 100);
        $this->venta('FA-00000002', 900);

        $this->actingAs($this->userWith('ventas.view', 'ventas.montos'))
            ->get('/ventas?sort=sub_total&dir=desc')
            ->assertInertia(fn ($page) => $page
                ->where('orden.sort', 'sub_total')
                ->where('ventas.data.0.compro_nro', 'FA-00000002'));
    }

    /** Ver el docblock de `VentaController::ordenables()`. */
    public function test_ventas_no_ordena_por_lote_ni_por_remito(): void
    {
        $user = $this->userWith('ventas.view');

        foreach (['lote', 'remito'] as $clave) {
            $this->actingAs($user)
                ->get("/ventas?sort={$clave}&dir=asc")
                ->assertStatus(200)
                ->assertInertia(fn ($page) => $page->where('orden.sort', null));
        }
    }

    // ── Columnas que no son columnas ────────────────────────────────────────

    public function test_articulos_ordena_por_la_razon_social_del_proveedor(): void
    {
        $zeta = Proveedor::create(['razon_social' => 'ZETA SA']);
        $alfa = Proveedor::create(['razon_social' => 'ALFA SA']);

        $this->articulo('A-1', 'Uno')->update(['proveedor_id' => $zeta->id]);
        $this->articulo('A-2', 'Dos')->update(['proveedor_id' => $alfa->id]);

        $this->actingAs($this->userWith('articulos.view'))
            ->get('/articulos?sort=proveedor&dir=asc')
            ->assertInertia(fn ($page) => $page->where('articulos.data.0.codigo', 'A-2'));
    }

    /** Nombre y apellido juntos: dos "Juan" no pueden salir en cualquier orden. */
    public function test_users_ordena_por_nombre_y_apellido(): void
    {
        User::factory()->create(['name' => 'Juan', 'apellido' => 'Zapata']);
        User::factory()->create(['name' => 'Juan', 'apellido' => 'Acosta']);

        // El usuario que hace el request tambien sale en el listado: se lo
        // nombra para que quede al final y no tape el assert.
        $actor = $this->userWith('users.view');
        $actor->update(['name' => 'Zzz', 'apellido' => 'Zzz']);

        $this->actingAs($actor)
            ->get('/users?sort=nombre&dir=asc')
            ->assertInertia(fn ($page) => $page->where('users.data.0.apellido', 'Acosta'));
    }

    /** Sin `withQueryString()` el orden se perdía al pasar de página. */
    public function test_users_conserva_el_orden_al_paginar(): void
    {
        User::factory()->count(20)->create();

        $this->actingAs($this->userWith('users.view'))
            ->get('/users?sort=email&dir=asc')
            ->assertInertia(function ($page) {
                $links = array_filter(array_column($page->toArray()['props']['users']['links'], 'url'));

                $this->assertNotEmpty($links);
                foreach ($links as $url) {
                    $this->assertStringContainsString('sort=email', $url);
                }

                return $page;
            });
    }

    public function test_roles_ordena_por_cantidad_de_permisos(): void
    {
        $pocos = Role::create(['name' => 'pocos']);
        $muchos = Role::create(['name' => 'muchos']);

        foreach (['a.view', 'a.edit', 'a.delete'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $pocos->givePermissionTo('a.view');
        $muchos->givePermissionTo(['a.view', 'a.edit', 'a.delete']);

        $this->actingAs($this->userWith('roles.view'))
            ->get('/roles?sort=permisos&dir=desc')
            ->assertInertia(fn ($page) => $page->where('roles.data.0.name', 'muchos'));
    }

    /**
     * El orden del catálogo `Observacion::ESTADOS` (el del flujo) y no el
     * alfabético: que "Cerrada" salga antes que "En proceso" no le dice nada a
     * nadie.
     */
    public function test_observaciones_ordena_el_estado_por_el_flujo_y_no_alfabeticamente(): void
    {
        $this->observacion('0001-26', ['estado' => 'cerrada']);
        $this->observacion('0002-26', ['estado' => 'en_proceso']);

        $this->actingAs($this->userWith('observaciones.view'))
            ->get('/observaciones?sort=estado&dir=asc')
            // 'en_proceso' está antes que 'cerrada' en el catálogo; alfabético
            // sería al revés.
            ->assertInertia(fn ($page) => $page->where('observaciones.data.0.estado', 'en_proceso'));
    }

    /**
     * La celda muestra la razón social del cliente vinculado y, si no hay, el
     * nombre que tipeó quien cargó el reclamo: el orden sigue lo mismo.
     */
    public function test_observaciones_ordena_por_cliente_usando_el_contacto_cuando_no_hay_vinculado(): void
    {
        $this->observacion('0001-26', ['contacto_nombre' => 'Zulma SA']);
        $this->observacion('0002-26', ['contacto_nombre' => 'Abel SA']);

        $this->actingAs($this->userWith('observaciones.view'))
            ->get('/observaciones?sort=cliente&dir=asc')
            ->assertInertia(fn ($page) => $page->where('observaciones.data.0.numero', '0002-26'));
    }

    private function observacion(string $numero, array $attrs = []): Observacion
    {
        return Observacion::create([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'clasificada',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Observación '.$numero,
            'descripcion' => 'Descripción',
            ...$attrs,
        ]);
    }

    // ── El export hereda el orden ───────────────────────────────────────────

    /**
     * "Lo que ves es lo que baja" ya valía para los filtros; el orden es parte
     * de lo que se ve. Sale gratis porque el `orderBy` vive en `filtrados()`,
     * que comparten el listado y el export.
     */
    public function test_el_export_de_articulos_hereda_el_orden(): void
    {
        $this->articulo('B-2', 'Zapallo');
        $this->articulo('A-1', 'Aguja');

        $user = $this->userWith('articulos.view');

        foreach ([['asc', 'A-1'], ['desc', 'B-2']] as [$dir, $esperado]) {
            $respuesta = $this->actingAs($user)->get("/articulos/export?sort=codigo&dir={$dir}");
            $respuesta->assertStatus(200);

            $this->assertSame($esperado, $this->primerCodigoDelExcel($respuesta->streamedContent()));
        }
    }

    /** La primera celda de datos (fila 2, columna A) del .xlsx que baja el export. */
    private function primerCodigoDelExcel(string $contenido): string
    {
        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($archivo, $contenido);

        $hoja = IOFactory::load($archivo)->getActiveSheet();
        $valor = (string) $hoja->getCell('A2')->getValue();

        unlink($archivo);

        return $valor;
    }
}
