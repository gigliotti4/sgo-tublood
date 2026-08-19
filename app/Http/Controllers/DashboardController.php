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
                // TODO: requiere sla_configs para calcular el % de cumplimiento (pendiente).
                'tiempoSla' => null,
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
            // TODO: requiere tabla sectors (pendiente).
            'porSector' => [],
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
