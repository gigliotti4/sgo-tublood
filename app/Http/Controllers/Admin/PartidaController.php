<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Controller;
use App\Jobs\SyncPartidasJob;
use App\Models\Partida;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Models\VentaPartida;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Consulta del padrón de partidas (lotes) espejado del ERP.
 *
 * Solo lectura: la tabla se reemplaza entera en cada sincronización, así que no
 * hay edición ni borrado que tenga sentido ofrecer. Mismo criterio que Ventas.
 */
class PartidaController extends Controller
{
    use OrdenaListados;

    /**
     * Qué columnas se pueden ordenar. La clave es lo que viaja en la URL.
     *
     * "Proveedor" va con **subconsulta y no con un join**: la query devuelve el
     * modelo entero, así que un join traería `proveedores.id` pisando
     * `partidas.id`. Se cruza por `numero`, que es como está guardada la
     * relación en `partidas.proveedor_numero`.
     *
     * @return array<string, string|Closure>
     */
    private function ordenables(): array
    {
        return [
            'lote' => 'partidas.codigo_partida',
            'articulo' => 'partidas.codigo_articulo',
            'proveedor' => fn (Builder $q, string $dir) => $q->orderBy(
                Proveedor::query()
                    ->select('razon_social')
                    ->whereColumn('proveedores.numero', 'partidas.proveedor_numero'),
                $dir,
            ),
            'vencimiento' => 'partidas.fecha_vencimiento',
            'ubicacion' => 'partidas.ubicacion',
            'movimiento' => 'partidas.ultimo_movimiento_at',
        ];
    }

    public function index(Request $request): Response
    {
        $this->authorize('partidas.view');

        $search = $request->string('search')->trim()->value();
        $proveedor = $request->string('proveedor')->trim()->value();
        $vencimiento = $request->string('vencimiento')->trim()->value();

        $partidas = Partida::query()
            // `articulo` puede no existir (el ERP nombra códigos que el feed de
            // artículos no trae) y `proveedor` puede ser null: los dos eager
            // loads son opcionales por diseño, no se filtra por ellos.
            ->with(['articulo:codigo,descripcion', 'proveedor:id,numero,razon_social'])
            ->when($search, fn ($q) => $q->buscar($search))
            ->when($proveedor, fn ($q, $proveedor) => $q->where('proveedor_numero', $proveedor))
            ->when($vencimiento, fn ($q, $vencimiento) => $q->vencimiento($vencimiento));

        $partidas = $this->aplicarOrden(
            $partidas,
            $request,
            $this->ordenables(),
            fn (Builder $q) => $q->orderByDesc('partidas.ultimo_movimiento_at'),
            'partidas.id',
        )->paginate(50)->withQueryString();

        return inertia('Admin/Partidas/Index', [
            'partidas' => $partidas,
            'orden' => $this->orden($request, $this->ordenables()),
            'filters' => [
                'search' => $search,
                'proveedor' => $proveedor,
                'vencimiento' => $vencimiento,
            ],
            // Solo los proveedores que efectivamente tienen partidas (97 de los
            // 1.849 del padrón): un select con el padrón entero sería inusable.
            'proveedores' => Proveedor::query()
                ->whereIn('numero', Partida::query()->whereNotNull('proveedor_numero')->distinct()->pluck('proveedor_numero'))
                ->orderBy('razon_social')
                ->get(['numero', 'razon_social']),
            'lastSync' => Partida::max('synced_at'),
            // Sin filtrar: el paginador ya trae el total de la búsqueda vigente.
            'total' => Partida::count(),
        ]);
    }

    /**
     * Ficha de la partida con sus despachos: a qué clientes se les mandó.
     *
     * Es la vuelta completa de la trazabilidad — desde el lote, quién lo
     * recibió — y lo que hace falta para un recall.
     *
     * Se pagina (50 por página) y no se mete en un modal porque hay partidas
     * muy despachadas: 63 superan los 200 despachos y la máxima llega a 1.248.
     *
     * ⚠️ El cliente sale de `ventas`, que **solo cubre desde 2024-08**,
     * mientras que el kardex llega a 2016. Los despachos viejos traen
     * comprobante y cantidad pero no cliente: se marcan como tales en la
     * pantalla en vez de mostrar un guion, que se leería como dato faltante.
     */
    public function show(Partida $partida): Response
    {
        $this->authorize('partidas.view');

        $partida->load(['articulo:codigo,descripcion,pm', 'proveedor:id,numero,razon_social']);

        $despachos = VentaPartida::query()
            ->deLaPartida($partida)
            // LEFT JOIN: un despacho sin venta local igual se lista.
            ->leftJoin('ventas', function ($join) {
                $join->on('ventas.compro_nro', '=', 'venta_partidas.compro_nro')
                    ->on('ventas.articulo', '=', 'venta_partidas.codigo_articulo');
            })
            ->select([
                'venta_partidas.id',
                'venta_partidas.compro_nro',
                'venta_partidas.cantidad',
                'venta_partidas.remito_tipo',
                'venta_partidas.remito_numero',
                'venta_partidas.fecha',
                'ventas.cliente',
                'ventas.razon_social',
                'ventas.provincia',
                'ventas.vendedor',
            ])
            ->orderByDesc('venta_partidas.fecha')
            ->orderByDesc('venta_partidas.id')
            ->paginate(50)
            ->withQueryString();

        return inertia('Admin/Partidas/Show', [
            'partida' => $partida,
            'despachos' => $despachos,
            // Total sin paginar, para el encabezado del bloque.
            'totalDespachos' => VentaPartida::query()->deLaPartida($partida)->count(),
            'unidadesDespachadas' => VentaPartida::query()->deLaPartida($partida)->sum('cantidad'),
            // Desde cuándo hay ventas locales: explica los despachos sin cliente.
            'ventasDesde' => Venta::min('fecha'),
        ]);
    }

    public function sync(): RedirectResponse
    {
        $this->authorize('partidas.sync');

        SyncPartidasJob::dispatch();

        // `back()`: el botón está en el listado y sincronizar no tiene por qué
        // descartar los filtros que el usuario tenía puestos.
        return back(fallback: route('partidas.index'))
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }
}
