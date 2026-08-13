<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncArticulosJob;
use App\Models\Articulo;
use App\Services\ArticuloImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ArticuloController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('articulos.view');

        $search = $request->string('search')->trim()->value();

        $articulos = Articulo::query()
            ->with('proveedor:id,numero,razon_social')
            ->when($search, fn ($q) => $q->buscar($search))
            ->orderBy('descripcion')
            ->paginate(50)
            ->withQueryString();

        $lastSync = Articulo::max('synced_at');

        return inertia('Admin/Articulos/Index', [
            'articulos' => $articulos,
            'filters' => ['search' => $search],
            'lastSync' => $lastSync,
            // Sin filtrar: el paginador ya trae el total de la búsqueda vigente.
            'total' => Articulo::count(),
        ]);
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
