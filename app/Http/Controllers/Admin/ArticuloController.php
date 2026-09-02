<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncArticulosJob;
use App\Models\Articulo;
use App\Services\ArticuloExportService;
use App\Services\ArticuloImportService;
use App\Services\VinculacionProveedores;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArticuloController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('articulos.view');

        $search = $request->string('search')->trim()->value();
        $estado = $request->string('estado')->trim()->value();

        $proveedor = $request->string('proveedor')->trim()->value();

        $articulos = $this->filtrados($request)
            ->orderBy('descripcion')
            ->paginate(50)
            ->withQueryString();

        $lastSync = Articulo::max('synced_at');

        return inertia('Admin/Articulos/Index', [
            'articulos' => $articulos,
            'filters' => ['search' => $search, 'estado' => $estado, 'proveedor' => $proveedor],
            'lastSync' => $lastSync,
            // Sin filtrar: el paginador ya trae el total de la búsqueda vigente.
            'total' => Articulo::count(),
            'totalInactivos' => Articulo::where('activo', false)->count(),
            // Para seguir el avance de la carga de `codigo_proveedor` en RP.
            'totalSinProveedor' => Articulo::whereNull('proveedor_id')->count(),
            // La lista concreta de valores mal cargados, para mandarle a RP.
            'codigosInvalidos' => VinculacionProveedores::codigosInvalidos(),
        ]);
    }

    /**
     * Exporta el catálogo a Excel, con el mismo filtro que el listado: lo que
     * ves es lo que baja. Suma columnas de Estado y Origen que el listado no
     * muestra — ver ArticuloExportService.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('articulos.view');

        $articulos = $this->filtrados($request)->orderBy('descripcion')->get();

        return (new ArticuloExportService)->exportar($articulos);
    }

    /**
     * La query del listado con los filtros de la request aplicados. La
     * comparten el listado y la exportación a Excel.
     */
    private function filtrados(Request $request): Builder
    {
        $search = $request->string('search')->trim()->value();
        $estado = $request->string('estado')->trim()->value();
        $proveedor = $request->string('proveedor')->trim()->value();

        return Articulo::query()
            ->with('proveedor:id,numero,razon_social')
            ->when($search, fn ($q) => $q->buscar($search))
            // Encadenado después del buscador: buscar "AGUJA" dentro de los
            // discontinuados tiene que funcionar.
            ->when($estado, fn ($q) => $q->where('activo', $estado === 'activos'))
            // Encadenado igual que `estado`: buscar dentro de los que no tienen
            // proveedor tiene que funcionar. Como vive acá, el export hereda el
            // filtro solo — bajar la lista de pendientes sale gratis.
            ->when($proveedor, fn ($q) => $proveedor === 'sin'
                ? $q->whereNull('proveedor_id')
                : $q->whereNotNull('proveedor_id'));
    }

    public function sync(): RedirectResponse
    {
        $this->authorize('articulos.sync');

        SyncArticulosJob::dispatch();

        return redirect()->route('articulos.index')
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }

    public function edit(Articulo $articulo): Response
    {
        $this->authorize('articulos.edit');

        return inertia('Admin/Articulos/Edit', [
            'articulo' => $articulo->load('proveedor:id,numero,razon_social'),
        ]);
    }

    public function update(Request $request, Articulo $articulo): RedirectResponse
    {
        $this->authorize('articulos.edit');

        $data = $request->validate([
            'fecha_vencimiento' => ['nullable', 'date'],
            'pm' => ['nullable', 'string', 'max:255'],
            'legajo' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string'],
            'link_registro' => ['nullable', 'url', 'max:500'],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
        ]);

        // Marcar el origen solo si el proveedor cambió de verdad: guardar la
        // ficha sin tocarlo no debería blindarlo contra el dato del ERP.
        if (array_key_exists('proveedor_id', $data) && $data['proveedor_id'] !== $articulo->proveedor_id) {
            $data['proveedor_origen'] = $data['proveedor_id'] === null
                ? null
                : VinculacionProveedores::ORIGEN_MANUAL;
        }

        $articulo->update($data);

        return redirect()->route('articulos.edit', $articulo)
            ->with('success', 'Artículo actualizado correctamente.');
    }

    public function import(Request $request, ArticuloImportService $service): RedirectResponse
    {
        $this->authorize('articulos.import');

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'crear_faltantes' => ['boolean'],
        ]);

        try {
            $resultado = $service->import($data['archivo'], $request->boolean('crear_faltantes'));
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('articulos.index')->with('error', $e->getMessage());
        }

        $mensaje = "Importación completa: {$resultado['actualizados']} artículos actualizados";

        if ($resultado['creados'] > 0) {
            $mensaje .= ", {$resultado['creados']} creados";
        }

        if ($resultado['proveedoresCreados'] > 0) {
            $mensaje .= ", {$resultado['proveedoresCreados']} proveedores nuevos";
        }

        $mensaje .= '.';

        $redirect = redirect()->route('articulos.index')->with('success', $mensaje);

        if ($resultado['advertencias'] !== []) {
            $redirect->with('error', implode(' | ', $resultado['advertencias']));
        }

        return $redirect;
    }
}
