<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Controller;
use App\Jobs\SyncVentasJob;
use App\Models\Venta;
use App\Models\VentaPartida;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Consulta del detalle de ventas espejado del ERP.
 *
 * Solo lectura: la tabla se reemplaza entera en cada sincronización, así que no
 * hay edición ni borrado que tenga sentido ofrecer.
 */
class VentaController extends Controller
{
    use OrdenaListados;

    /**
     * Qué columnas se pueden ordenar. La clave es lo que viaja en la URL.
     *
     * Quedan afuera a propósito:
     *
     * - **Lote**: se adjunta *después* del paginado con clave compuesta
     *   (`VentaPartida::adjuntarAVentas()`), así que ordenarlo obligaría a
     *   joinear las ~166.000 filas del kardex y a elegir cuál de los lotes
     *   manda cuando el renglón salió de varios (3,5% de los casos).
     * - **Remito**: `remito_nro` es un entero pelado sin la serie y el 29% se
     *   repite entre series, así que el orden sería ambiguo y además
     *   contradiría al remito "bueno" que la fila muestra cuando viene del
     *   kardex (ver `VentaPartida::SERIES_DE_REMITO`).
     *
     * @return array<string, string>
     */
    private function ordenables(bool $puedeVerMontos): array
    {
        $ordenables = [
            'fecha' => 'fecha',
            'comprobante' => 'compro_nro',
            'cliente' => 'razon_social',
            'articulo' => 'descrip_arti',
            'cantidad' => 'cantidad',
            'vendedor' => 'vendedor',
        ];

        if ($puedeVerMontos) {
            // Sin el permiso la columna ni se muestra, y dejarla siempre en la
            // whitelist filtraría el dato por ordenamiento: con
            // `?sort=sub_total&dir=desc` la primera fila es la venta más cara,
            // que es justo lo que `ventas.montos` esconde. Mismo criterio que
            // el `makeHidden(COLUMNAS_DE_IMPORTE)` de más abajo.
            $ordenables['sub_total'] = 'sub_total';
        }

        return $ordenables;
    }

    public function index(Request $request): Response
    {
        $this->authorize('ventas.view');

        $search = $request->string('search')->trim()->value();
        $desde = $request->string('desde')->trim()->value();
        $hasta = $request->string('hasta')->trim()->value();
        $vendedor = $request->string('vendedor')->trim()->value();
        $lote = $request->string('lote')->trim()->value();

        // Los importes son de la Direccion: cualquiera con `ventas.view` puede
        // consultar qué se vendió, pero no por cuánta plata. Se decide acá y no
        // en la pantalla — si solo lo escondiera el template, los montos
        // seguirían viajando en las props de Inertia y se leerían del HTML.
        $puedeVerMontos = $request->user()->can('ventas.montos');

        $ordenables = $this->ordenables($puedeVerMontos);

        $ventas = Venta::query()
            ->when($search, fn ($q) => $q->buscar($search))
            ->when($desde, fn ($q, $desde) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn ($q, $hasta) => $q->whereDate('fecha', '<=', $hasta))
            ->when($vendedor, fn ($q, $vendedor) => $q->where('vendedor', $vendedor))
            // Filtro propio y no parte de scopeBuscar(): ese scope cruza dos
            // términos entre sí y meterle el lote enturbiaría esa semántica.
            ->when($lote, fn ($q, $lote) => $q->whereExists(
                fn ($sub) => $sub->selectRaw('1')
                    ->from('venta_partidas as vp')
                    ->whereColumn('vp.compro_nro', 'ventas.compro_nro')
                    ->whereColumn('vp.codigo_articulo', 'ventas.articulo')
                    ->where('vp.codigo_partida', 'like', '%'.mb_strtoupper($lote).'%')
            ));

        $ventas = $this->aplicarOrden(
            $ventas,
            $request,
            $ordenables,
            fn (Builder $q) => $q->orderByDesc('fecha'),
        )->paginate(50)->withQueryString();

        if (! $puedeVerMontos) {
            $ventas->through(fn (Venta $venta) => $venta->makeHidden(Venta::COLUMNAS_DE_IMPORTE));
        }

        // Qué lote salió en cada renglón. Se resuelve acá y no con un eager
        // load porque la clave es compuesta (`compro_nro` + `articulo`) — ver
        // `VentaPartida::adjuntarAVentas()`. Una sola query para la página.
        VentaPartida::adjuntarAVentas($ventas->getCollection());

        return inertia('Admin/Ventas/Index', [
            'ventas' => $ventas,
            'orden' => $this->orden($request, $ordenables),
            'filters' => [
                'search' => $search,
                'desde' => $desde,
                'hasta' => $hasta,
                'vendedor' => $vendedor,
                'lote' => $lote,
            ],
            // Para el select de vendedores: son pocos y salen de los datos
            // mismos, así que no hace falta una tabla aparte.
            'vendedores' => Venta::query()
                ->whereNotNull('vendedor')
                ->distinct()
                ->orderBy('vendedor')
                ->pluck('vendedor'),
            // La decisión la toma el servidor y la pantalla solo la respeta, en
            // vez de repetir la regla con hasPermission() del lado del cliente.
            'puedeVerMontos' => $puedeVerMontos,
            'lastSync' => Venta::max('synced_at'),
            // Sin filtrar: el paginador ya trae el total de la búsqueda vigente.
            'total' => Venta::count(),
        ]);
    }

    public function sync(): RedirectResponse
    {
        $this->authorize('ventas.sync');

        SyncVentasJob::dispatch();

        // `back()`: el botón está en el listado y sincronizar no tiene por qué
        // descartar los filtros que el usuario tenía puestos.
        return back(fallback: route('ventas.index'))
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }
}
