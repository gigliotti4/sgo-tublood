<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Observacion;
use App\Models\ObservationProduct;
use App\Models\Proveedor;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requiere_autenticacion(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_muestra_estadisticas_reales_de_observaciones(): void
    {
        $user = User::factory()->create();

        Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'responsable_id' => $user->id,
        ]);

        Observacion::create([
            'numero' => '0002-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'cerrada',
            'contacto_nombre' => 'Cliente Test 2',
            'contacto_email' => 'cliente2@example.com',
            'titulo' => 'Título de prueba 2',
            'descripcion' => 'Descripción de prueba 2',
            'prioridad' => 'critica',
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.total', 2)
                ->where('stats.abiertas', 1)
                ->where('stats.cerradas', 1)
                ->where('stats.asignadasAMi', 1)
                ->where('kpis.critica', 1)
                ->has('ultimas', 2)
                ->has('asignadas', 1)
            );
    }

    public function test_comparte_la_configuracion_publica_de_pusher_sin_el_secret(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'public-key',
            'broadcasting.connections.pusher.secret' => 'private-secret',
            'broadcasting.connections.pusher.options.cluster' => 'sa1',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('broadcasting.driver', 'pusher')
                ->where('broadcasting.key', 'public-key')
                ->where('broadcasting.cluster', 'sa1')
                ->missing('broadcasting.secret')
            );
    }

    /** Helper para las pruebas del hover: una observación con lo mínimo. */
    private function observacion(string $numero, array $attrs = []): Observacion
    {
        return Observacion::create(array_merge([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'en_proceso',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Observación '.$numero,
            'descripcion' => 'Descripción',
        ], $attrs));
    }

    /**
     * El panel que se despliega al pasar el mouse por "Abiertas" tiene que
     * listar lo mismo que cuenta el numerito: si no, se contradicen.
     */
    public function test_la_lista_de_abiertas_usa_el_mismo_criterio_que_el_contador(): void
    {
        $this->observacion('0001-26', ['estado' => 'en_proceso']);
        $this->observacion('0002-26', ['estado' => 'cerrada']);
        $this->observacion('0003-26', ['estado' => 'cancelada']);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('stats.abiertas', 1)
                ->where('listas.abiertas.total', 1)
                ->has('listas.abiertas.items', 1)
                ->where('listas.abiertas.items.0.numero', '0001-26'));
    }

    public function test_la_lista_de_asignadas_es_solo_la_del_usuario_logueado(): void
    {
        $yo = User::factory()->create();
        $otro = User::factory()->create();

        $this->observacion('0001-26', ['responsable_id' => $yo->id]);
        $this->observacion('0002-26', ['responsable_id' => $otro->id]);

        $this->actingAs($yo)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('stats.asignadasAMi', 1)
                ->where('listas.asignadasAMi.total', 1)
                ->where('listas.asignadasAMi.items.0.numero', '0001-26'));
    }

    /**
     * El panel se corta en 15 y el resto se resume en "y N más": el numerito
     * puede ser de miles y la lista viaja en las props de cada carga.
     */
    public function test_la_lista_se_corta_pero_el_total_es_el_real(): void
    {
        foreach (range(1, 18) as $i) {
            $this->observacion(sprintf('%04d-26', $i), ['estado' => 'en_proceso']);
        }

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('listas.abiertas.items', 15)
                ->where('listas.abiertas.total', 18));
    }

    /**
     * El ranking cuenta **renglones de producto y no observaciones**: un caso
     * con dos artículos del mismo proveedor le suma dos.
     */
    public function test_el_ranking_de_proveedores_cuenta_renglones_no_observaciones(): void
    {
        $proveedor = Proveedor::create(['numero' => '1', 'razon_social' => 'PROPATO HNOS SAIC']);
        $otro = Proveedor::create(['numero' => '2', 'razon_social' => 'BETA SRL']);

        Articulo::create(['codigo' => 'A-1', 'descripcion' => 'Aguja', 'proveedor_id' => $proveedor->id]);
        Articulo::create(['codigo' => 'A-2', 'descripcion' => 'Tubo', 'proveedor_id' => $proveedor->id]);
        Articulo::create(['codigo' => 'B-1', 'descripcion' => 'Gasa', 'proveedor_id' => $otro->id]);

        // Una sola observación, pero con dos artículos de PROPATO.
        $unaSola = $this->observacion('0001-26');
        $this->producto($unaSola, 'A-1');
        $this->producto($unaSola, 'A-2');

        $this->producto($this->observacion('0002-26'), 'B-1');

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('porProveedor.items', 2)
                ->where('porProveedor.items.0.razon_social', 'PROPATO HNOS SAIC')
                ->where('porProveedor.items.0.fallas', 2)
                ->where('porProveedor.items.1.razon_social', 'BETA SRL')
                ->where('porProveedor.items.1.fallas', 1)
                ->where('porProveedor.sinProveedor', 0));
    }

    /** Un caso anulado o duplicado no es una falla real del proveedor. */
    public function test_el_ranking_no_cuenta_las_canceladas(): void
    {
        $proveedor = Proveedor::create(['numero' => '1', 'razon_social' => 'PROPATO HNOS SAIC']);
        Articulo::create(['codigo' => 'A-1', 'descripcion' => 'Aguja', 'proveedor_id' => $proveedor->id]);

        $this->producto($this->observacion('0001-26', ['estado' => 'cancelada']), 'A-1');

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('porProveedor.items', 0)
                ->where('porProveedor.sinProveedor', 0));
    }

    /**
     * Los renglones que no se le pueden atribuir a nadie se cuentan aparte: sin
     * ese número el ranking miente por omisión.
     */
    public function test_los_renglones_sin_proveedor_atribuible_se_reportan_aparte(): void
    {
        // Artículo del catálogo, pero todavía sin proveedor cargado.
        Articulo::create(['codigo' => 'A-1', 'descripcion' => 'Aguja']);

        $obs = $this->observacion('0001-26');
        $this->producto($obs, 'A-1');
        // Y un código tipeado a mano que no matchea ningún artículo.
        $this->producto($obs, 'NO-EXISTE');

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('porProveedor.items', 0)
                ->where('porProveedor.sinProveedor', 2));
    }

    private function producto(Observacion $observacion, string $codigo): ObservationProduct
    {
        return $observacion->productos()->create([
            'producto' => 'Producto '.$codigo,
            'codigo' => $codigo,
            'cantidad_afectada' => 1,
            'lote' => 'L1',
            'fecha_vencimiento' => '2027-01-01',
            'numero_remito' => 'R-1',
            'tipo_comprobante' => 'remito',
        ]);
    }

    // ── KPI: tiempo promedio de resolución ──────────────────────────────────

    /**
     * `cerrada_at` se setea a mano en estos tests (no cerrando el caso) para
     * poder fijar duraciones conocidas: el observer siempre pondría now().
     */
    private function cerrada(string $numero, int $horasParaCerrar, int $diasAtras = 0): Observacion
    {
        $cierre = now()->subDays($diasAtras);

        $obs = $this->observacion($numero, ['estado' => 'cerrada']);
        $obs->forceFill([
            'created_at' => $cierre->copy()->subHours($horasParaCerrar),
            'cerrada_at' => $cierre,
        ])->save();

        return $obs;
    }

    public function test_el_kpi_promedia_las_cerradas_de_la_ventana(): void
    {
        $this->cerrada('0001-26', horasParaCerrar: 10);
        $this->cerrada('0002-26', horasParaCerrar: 20);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('kpis.resolucion.horas', 15)
                ->where('kpis.resolucion.casos', 2));
    }

    /** Una cerrada hace más de 90 días no entra: el KPI es de la ventana, no histórico. */
    public function test_el_kpi_ignora_lo_cerrado_fuera_de_la_ventana(): void
    {
        $this->cerrada('0001-26', horasParaCerrar: 10);
        $this->cerrada('0002-26', horasParaCerrar: 500, diasAtras: 120);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('kpis.resolucion.horas', 10)
                ->where('kpis.resolucion.casos', 1));
    }

    /** Un caso anulado no es trabajo terminado: no puede inflar ni bajar el promedio. */
    public function test_el_kpi_no_cuenta_las_canceladas(): void
    {
        $this->cerrada('0001-26', horasParaCerrar: 10);

        $cancelada = $this->observacion('0002-26', ['estado' => 'cancelada']);
        $cancelada->forceFill([
            'created_at' => now()->subHours(999),
            'cerrada_at' => now(),
        ])->save();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('kpis.resolucion.horas', 10)
                ->where('kpis.resolucion.casos', 1));
    }

    /** Sin cierres, el KPI viaja null y NO cero: cero sería un promedio buenísimo. */
    public function test_el_kpi_sin_casos_cerrados_es_null(): void
    {
        $this->observacion('0001-26');

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('kpis.resolucion.horas', null)
                ->where('kpis.resolucion.casos', 0));
    }

    // ── Observaciones por sector ────────────────────────────────────────────

    public function test_agrupa_las_observaciones_por_sector(): void
    {
        $calidad = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);
        $logistica = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);

        $this->observacion('0001-26', ['sector_id' => $calidad->id]);
        $this->observacion('0002-26', ['sector_id' => $calidad->id]);
        $this->observacion('0003-26', ['sector_id' => $logistica->id]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('porSector', 2)
                // Ordenado de mayor a menor.
                ->where('porSector.0.sector', 'Garantía de Calidad')
                ->where('porSector.0.count', 2)
                ->where('porSector.1.sector', 'Logística')
                ->where('porSector.1.count', 1));
    }

    /**
     * Los casos sin sector se muestran, no se esconden: el portal público
     * guarda el reclamo aunque no logre resolver el sector, y si el gráfico los
     * omitiera nadie se enteraría de que hay casos sin derivar. Es la decisión
     * que se rompe sola si alguien cambia el leftJoin por un join.
     */
    public function test_las_observaciones_sin_sector_se_agrupan_aparte(): void
    {
        $calidad = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->observacion('0001-26', ['sector_id' => $calidad->id]);
        $this->observacion('0002-26', ['sector_id' => $calidad->id]);
        // Dos sin sector, para que el orden por cantidad sea determinista.
        $this->observacion('0003-26', ['sector_id' => null]);
        $this->observacion('0004-26', ['sector_id' => null]);
        $this->observacion('0005-26', ['sector_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('porSector', 2)
                ->where('porSector.0.sector', 'Sin sector')
                ->where('porSector.0.count', 3)
                ->where('porSector.1.sector', 'Garantía de Calidad')
                ->where('porSector.1.count', 2));
    }

    public function test_el_grafico_por_sector_no_cuenta_las_canceladas(): void
    {
        $calidad = Sector::create(['nombre' => 'Garantía de Calidad', 'slug' => 'garantia_calidad']);

        $this->observacion('0001-26', ['sector_id' => $calidad->id]);
        $this->observacion('0002-26', ['sector_id' => $calidad->id, 'estado' => 'cancelada']);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('porSector', 1)
                ->where('porSector.0.count', 1));
    }
}
