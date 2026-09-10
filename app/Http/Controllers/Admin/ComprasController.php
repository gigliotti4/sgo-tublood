<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncComprasJob;
use App\Models\CompraOrdenPendiente;
use App\Models\CompraPedidoPendiente;
use App\Models\ComprasArticulo;
use App\Models\Venta;
use App\Services\Compras\ReposicionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Tablero de reposición de stock para el sector Compras.
 *
 * Responde una sola pregunta: qué hay que comprar y cuánto.
 *
 * ⚠️ Manda el dataset ENTERO en las props (~1 MB, ~185 KB gzip) y no pagina del
 * lado del servidor. Es a propósito: los KPIs y la fila de TOTAL se calculan
 * sobre todo el conjunto filtrado, el Pareto se recalcula sobre ese mismo
 * conjunto y la tabla ordena por columnas derivadas. Paginar en el servidor
 * obligaría a recorrer los 4.800 grupos en cada request igual, con un roundtrip
 * por cada click de ordenamiento. Ver `resources/js/lib/compras.ts`.
 */
class ComprasController extends Controller
{
    public function index(ReposicionService $reposicion): Response
    {
        $this->authorize('compras.view');

        $dataset = $reposicion->dataset();

        return inertia('Admin/Compras/Index', [
            'meses' => $dataset['meses'],
            'groups' => $dataset['groups'],
            'categorias' => config('compras.categorias'),
            'mesesObjetivo' => config('compras.meses_objetivo'),
            'mesesObjetivoDefault' => config('compras.meses_objetivo_default'),
            // Se muestran las cuatro por separado porque se sincronizan por
            // caminos distintos: las ventas van con `ventas:sync` (04:00) y el
            // resto con `compras:sync` (05:00). Si una queda vieja, el tablero
            // tiene que poder decir cuál.
            'lastSync' => [
                'articulos' => ComprasArticulo::max('synced_at'),
                'ordenes' => CompraOrdenPendiente::max('synced_at'),
                'pedidos' => CompraPedidoPendiente::max('synced_at'),
                'ventas' => Venta::max('synced_at'),
            ],
            // El aviso de "reservado sin conciliar" solo tiene sentido mientras
            // la regla no esté validada contra el ERP. Se apaga desde el config.
            'reservaSinConciliar' => config('compras.reserva.estados_excluidos') === [],
        ]);
    }

    public function sync(): RedirectResponse
    {
        $this->authorize('compras.sync');

        SyncComprasJob::dispatch();

        return redirect()->route('compras.index')
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }
}
