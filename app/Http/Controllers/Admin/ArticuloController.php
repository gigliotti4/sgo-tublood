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
            ->when($search, fn ($q) => $q->buscar($search))
            ->orderBy('descripcion')
            ->paginate(50)
            ->withQueryString();

        $lastSync = Articulo::max('synced_at');

        return inertia('Admin/Articulos/Index', [
            'articulos' => $articulos,
            'filters' => ['search' => $search],
            'lastSync' => $lastSync,
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
            'articulo' => $articulo,
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
        ]);

        try {
            $resultado = $service->import($data['archivo']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('articulos.index')->with('error', $e->getMessage());
        }

        $redirect = redirect()->route('articulos.index')
            ->with('success', "Importación completa: {$resultado['actualizados']} artículos actualizados.");

        if ($resultado['advertencias'] !== []) {
            $redirect->with('error', implode(' | ', $resultado['advertencias']));
        }

        return $redirect;
    }
}
