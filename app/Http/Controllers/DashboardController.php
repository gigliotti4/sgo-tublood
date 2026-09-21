<?php

namespace App\Http\Controllers;

use App\Models\Observacion;
use App\Models\ObservationProduct;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const ESTADOS_ABIERTOS = Observacion::ESTADOS_ABIERTOS;

    /** Cuántos proveedores entran en el ranking de fallas. */
    private const TOPE_PROVEEDORES = 10;

    /**
     * Ventana del KPI de tiempo de resolución, en días.
     *
     * No es un promedio histórico a propósito: con el tiempo se vuelve
     * insensible y una mejora real del equipo dejaría de notarse en el número.
     */
    private const DIAS_VENTANA_KPI = 90;

    /**
     * Cuántas observaciones lista el hover de una tarjeta.
     *
     * "Todas" no es viable: el numerito puede ser de miles y el panel tiene que
     * entrar en pantalla y viajar en las props de cada carga del panel. Se
     * muestran las más nuevas y el resto se resume en "y N más".
     */
    private const TOPE_HOVER = 15;

    public function index()
    {
        $asignadasAMi = Observacion::query()
            ->where('responsable_id', auth()->id())
            ->latest()
            ->limit(8)
            ->get(['id', 'numero', 'tipo', 'estado', 'titulo', 'created_at']);

        return inertia('Dashboard', [
            'stats' => [
                'total' => Observacion::count(),
                'abiertas' => Observacion::whereIn('estado', self::ESTADOS_ABIERTOS)->count(),
                // Las canceladas no cuentan como trabajo terminado (por eso tampoco
                // están en el gráfico por estado, más abajo).
                'cerradas' => Observacion::where('estado', 'cerrada')->count(),
                'asignadasAMi' => Observacion::where('responsable_id', auth()->id())->count(),
                // TODO: requiere tabla non_conformities (pendiente).
                'nc' => 0,
                'ncAbiertas' => 0,
            ],
            'kpis' => [
                'resolucion' => $this->tiempoPromedioResolucion(),
                'critica' => Observacion::where('prioridad', 'critica')->count(),
                'sinClasificar' => Observacion::where('estado', 'pendiente_clasificacion')->count(),
            ],
            'porEstado' => collect(Observacion::ESTADOS)
                ->except('cancelada')
                ->map(fn ($label, $estado) => [
                    'estado' => $estado,
                    'label' => $label,
                    'count' => Observacion::where('estado', $estado)->count(),
                ])
                ->values(),
            'porSector' => $this->observacionesPorSector(),
            'resolucionPorSector' => $this->tiempoResolucionPorSector(),
            // Lo que muestra el hover de las tarjetas "Abiertas" y "Asignadas a
            // mí". Cada lista usa **el mismo criterio que el numero de su
            // tarjeta**: si no, el panel contradiria al contador que abre.
            'listas' => [
                'abiertas' => $this->listaDeTarjeta(
                    Observacion::whereIn('estado', self::ESTADOS_ABIERTOS)
                ),
                'asignadasAMi' => $this->listaDeTarjeta(
                    Observacion::where('responsable_id', auth()->id())
                ),
            ],
            'asignadas' => $asignadasAMi,
            'ultimas' => Observacion::query()
                ->latest()
                ->limit(8)
                ->get(['id', 'numero', 'tipo', 'estado', 'titulo', 'created_at']),
            'porProveedor' => $this->proveedoresConMasFallas(),
            'tipoLabels' => TaxonomiaIncidencias::etiquetasTipos(),
            // Todos los estados, incluida `cancelada`. `porEstado` la excluye
            // (no va en el gráfico), así que usarlo de diccionario dejaba a las
            // canceladas mostrando el slug crudo en las tablas.
            'estadoLabels' => Observacion::ESTADOS,
        ]);
    }

    /**
     * Tiempo promedio desde que entra una observación hasta que se cierra.
     *
     * Se mide de punta a punta (`created_at` → `cerrada_at`), incluyendo lo que
     * el caso esperó a ser clasificado: es lo que mide un sistema de calidad y
     * lo que le importa a quien reclamó, no solo el trabajo del sector.
     *
     * Devuelve **horas** y no días: un caso resuelto en 4 h no puede figurar
     * como "0 días". El formato lo decide el frontend según la magnitud.
     *
     * `null` cuando no hay casos cerrados en la ventana — distinto de `0`, que
     * sería un promedio buenísimo y justo lo contrario de lo que pasa.
     *
     * ⚠️ El promedio se hace en PHP y no con `AVG(TIMESTAMPDIFF(...))`: esa
     * función es de MySQL y los tests corren en SQLite (forzado por
     * phpunit.xml), donde reventaría con un error de sintaxis. Son las cerradas
     * de 90 días, no un volumen que justifique pelearse con SQL portable.
     *
     * @return array{horas: float|null, casos: int}
     */
    private function tiempoPromedioResolucion(): array
    {
        $cerradas = $this->cerradasDeLaVentana()->get(['created_at', 'cerrada_at']);

        if ($cerradas->isEmpty()) {
            return ['horas' => null, 'casos' => 0];
        }

        return [
            'horas' => round($cerradas->avg(
                fn (Observacion $o) => $o->created_at->diffInHours($o->cerrada_at)
            ), 1),
            'casos' => $cerradas->count(),
        ];
    }

    /**
     * Las observaciones cerradas dentro de la ventana del KPI.
     *
     * Lo comparten el número general y el gráfico por sector: si el filtro se
     * escribiera dos veces, la tarjeta podría dejar de ser el promedio
     * ponderado de las barras y la pantalla se contradiría sola.
     *
     * Las columnas van calificadas con la tabla porque el gráfico por sector
     * joinea `sectors`, que también tiene `created_at`.
     */
    private function cerradasDeLaVentana(): Builder
    {
        return Observacion::query()
            ->where('observations.estado', 'cerrada')
            ->whereNotNull('observations.cerrada_at')
            ->where('observations.cerrada_at', '>=', now()->subDays(self::DIAS_VENTANA_KPI));
    }

    /**
     * Tiempo promedio de resolución por sector, para el gráfico del panel.
     *
     * Mismo criterio de punta a punta y misma ventana que el KPI general (ver
     * `tiempoPromedioResolucion()`): estas barras tienen que promediar al
     * número de la tarjeta, por eso las dos salen de `cerradasDeLaVentana()`.
     *
     * ⚠️ El promedio se hace en PHP y no con `AVG(TIMESTAMPDIFF(...))` por el
     * mismo motivo que el KPI general: esa función es de MySQL y los tests
     * corren en SQLite. Acá además hay que agrupar, así que se traen las dos
     * fechas con el nombre del sector ya resuelto y se agrupa con Collection.
     *
     * `leftJoin` + `COALESCE`, igual que `observacionesPorSector()`: el bucket
     * "Sin sector" tiene casos reales (el portal guarda el reclamo aunque no
     * resuelva el sector) y acá además es el más accionable — mide cuánto
     * tardan en derivarse los reclamos que entran sin dueño.
     *
     * Los sectores **sin cierres en la ventana no aparecen**: una barra en 0 h
     * se lee como "resuelven al instante", que es lo contrario de lo que pasa.
     * Es el mismo motivo por el que el KPI general devuelve `null` y no `0`.
     *
     * Se alias a `sector_nombre` y no a `sector` para no tapar la relación
     * `Observacion::sector()` en el modelo hidratado, que acá también se usa
     * para leer `created_at` / `cerrada_at`.
     *
     * @return Collection<int, array{sector: string, horas: float, casos: int}>
     */
    private function tiempoResolucionPorSector(): Collection
    {
        return $this->cerradasDeLaVentana()
            ->leftJoin('sectors', 'sectors.id', '=', 'observations.sector_id')
            ->get([
                DB::raw("COALESCE(sectors.nombre, 'Sin sector') as sector_nombre"),
                DB::raw('observations.created_at as created_at'),
                DB::raw('observations.cerrada_at as cerrada_at'),
            ])
            ->groupBy('sector_nombre')
            ->map(fn (Collection $casos, string $sector) => [
                'sector' => $sector,
                'horas' => round($casos->avg(
                    fn (Observacion $o) => $o->created_at->diffInHours($o->cerrada_at)
                ), 1),
                'casos' => $casos->count(),
            ])
            // De mayor a menor: la pregunta del gráfico es dónde se tarda más.
            ->sortByDesc('horas')
            ->values();
    }

    /**
     * Cuántas observaciones tiene cada sector, para el gráfico de barras.
     *
     * ⚠️ `leftJoin` y no `join`: un inner descartaría las observaciones sin
     * sector, que son justo las que hay que ver. El portal público guarda el
     * reclamo aunque no logre resolver el sector (nunca puede perder un
     * reclamo), así que ese bucket tiene casos reales — esconderlos haría que
     * el gráfico mienta por omisión, mismo criterio que el contador "sin
     * proveedor" del ranking de arriba.
     *
     * Las canceladas quedan afuera, igual que en el gráfico por estado.
     *
     * @return Collection<int, array{sector: string, count: int}>
     */
    private function observacionesPorSector(): Collection
    {
        return Observacion::query()
            ->leftJoin('sectors', 'sectors.id', '=', 'observations.sector_id')
            ->where('observations.estado', '!=', 'cancelada')
            ->groupBy('sectors.id', 'sectors.nombre')
            ->orderByDesc('count')
            ->get([
                DB::raw("COALESCE(sectors.nombre, 'Sin sector') as sector"),
                DB::raw('COUNT(*) as count'),
            ])
            ->map(fn ($fila) => ['sector' => $fila->sector, 'count' => (int) $fila->count]);
    }

    /**
     * Ranking de proveedores por cantidad de productos fallados.
     *
     * **Se cuenta por renglón de producto, no por observación**: un caso con
     * tres artículos del mismo proveedor le suma tres. Es la pregunta que
     * importa para evaluarlo — cuántos productos suyos fallaron.
     *
     * El proveedor sale del padrón **en vivo** (`observation_products.codigo` →
     * `articulos.codigo` → `articulos.proveedor_id`) y no de una copia guardada
     * en la observación: completarle el proveedor a un artículo reencuadra
     * también los casos ya cargados.
     *
     * Las canceladas quedan afuera: un caso anulado o duplicado no es una falla
     * real del proveedor. Mismo criterio que el stat "Cerradas" y el gráfico por
     * estado. Las borradas también, por el soft delete de `whereHas`.
     *
     * @return array{items: Collection<int, object>, sinProveedor: int}
     */
    private function proveedoresConMasFallas(): array
    {
        $atribuibles = ObservationProduct::query()
            ->whereHas('observacion', fn (Builder $q) => $q->where('estado', '!=', 'cancelada'));

        $items = (clone $atribuibles)
            ->join('articulos', 'articulos.codigo', '=', 'observation_products.codigo')
            ->join('proveedores', 'proveedores.id', '=', 'articulos.proveedor_id')
            ->groupBy('proveedores.id', 'proveedores.razon_social')
            ->orderByDesc('fallas')
            ->orderBy('proveedores.razon_social')
            ->limit(self::TOPE_PROVEEDORES)
            ->get([
                'proveedores.id as proveedor_id',
                'proveedores.razon_social',
                DB::raw('COUNT(*) as fallas'),
            ]);

        return [
            'items' => $items,
            // Renglones que no se le pueden atribuir a nadie: el código no
            // matchea ningún artículo del catálogo, o el artículo todavía no
            // tiene proveedor cargado. Sin este número el ranking miente por
            // omisión — un proveedor puede figurar bajo solo porque a sus
            // artículos les falta el dato.
            'sinProveedor' => (clone $atribuibles)
                ->whereNotExists(fn ($q) => $q
                    ->select(DB::raw(1))
                    ->from('articulos')
                    ->whereColumn('articulos.codigo', 'observation_products.codigo')
                    ->whereNotNull('articulos.proveedor_id'))
                ->count(),
        ];
    }

    /**
     * Las primeras TOPE_HOVER de una consulta, con cuántas quedaron afuera.
     *
     * @return array{items: Collection<int, Observacion>, total: int}
     */
    private function listaDeTarjeta(Builder $query): array
    {
        return [
            'items' => (clone $query)
                ->latest()
                ->limit(self::TOPE_HOVER)
                ->get(['id', 'numero', 'titulo', 'estado']),
            'total' => $query->count(),
        ];
    }
}
