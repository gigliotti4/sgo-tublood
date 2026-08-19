<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncVentasJob;
use App\Models\Venta;
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
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        if (! $puedeVerMontos) {
            $ventas->through(fn (Venta $venta) => $venta->makeHidden(Venta::COLUMNAS_DE_IMPORTE));
        }

        return inertia('Admin/Ventas/Index', [
            'ventas' => $ventas,
            'filters' => [
                'search' => $search,
                'desde' => $desde,
                'hasta' => $hasta,
                'vendedor' => $vendedor,
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
