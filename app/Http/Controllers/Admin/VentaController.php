<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncVentasJob;
use App\Models\Venta;
use App\Models\VentaPartida;
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
            ))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        if (! $puedeVerMontos) {
            $ventas->through(fn (Venta $venta) => $venta->makeHidden(Venta::COLUMNAS_DE_IMPORTE));
        }

        // Qué lote salió en cada renglón. Se resuelve acá y no con un eager
        // load porque la clave es compuesta (`compro_nro` + `articulo`) — ver
        // `VentaPartida::adjuntarAVentas()`. Una sola query para la página.
        VentaPartida::adjuntarAVentas($ventas->getCollection());

        return inertia('Admin/Ventas/Index', [
            'ventas' => $ventas,
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

        return redirect()->route('ventas.index')
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }
}
