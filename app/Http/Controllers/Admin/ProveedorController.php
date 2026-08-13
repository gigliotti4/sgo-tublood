<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use App\Services\ProveedorImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ProveedorController extends Controller
{
    /** Tope de resultados del buscador: es un autocompletado, no un listado. */
    private const LIMITE = 20;

    /** Menos que esto devolvería medio padrón y no ayuda a completar nada. */
    private const MINIMO = 2;

    public function index(Request $request): Response
    {
        $this->authorize('proveedores.view');

        $search = $request->string('search')->trim()->value();

        $proveedores = Proveedor::query()
            ->when($search, fn ($q) => $q->buscar($search))
            ->orderBy('razon_social')
            ->paginate(50)
            ->withQueryString();

        return inertia('Admin/Proveedores/Index', [
            'proveedores' => $proveedores,
            'filters' => ['search' => $search],
            // Sin filtrar: el paginador ya trae el total de la búsqueda vigente.
            'total' => Proveedor::count(),
        ]);
    }

    /**
     * Autocompletado para elegir el proveedor de un artículo.
     *
     * A diferencia del buscador de artículos (`App\Http\Controllers\
     * ArticuloController::buscar`), este endpoint **no es público**: aquel lo
     * usa el portal de carga, que no tiene login. El padrón de proveedores no
     * tiene por qué quedar expuesto, así que va detrás de `proveedores.view`.
     */
    public function buscar(Request $request): JsonResponse
    {
        $this->authorize('proveedores.view');

        // Un término corto devuelve lista vacía en vez de 422: para un
        // autocompletado no es un error, es "todavía no hay nada que sugerir".
        $termino = trim((string) $request->query('q', ''));

        if (mb_strlen($termino) < self::MINIMO) {
            return response()->json([]);
        }

        $proveedores = Proveedor::query()
            ->buscar(mb_substr($termino, 0, 100))
            ->limit(self::LIMITE)
            ->get(['id', 'numero', 'razon_social']);

        return response()->json($proveedores);
    }

    public function edit(Proveedor $proveedor): Response
    {
        $this->authorize('proveedores.edit');

        return inertia('Admin/Proveedores/Edit', [
            'proveedor' => $proveedor,
        ]);
    }

    public function update(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $this->authorize('proveedores.edit');

        // `numero` queda afuera a propósito: es la clave con la que el import
        // reconoce al proveedor, y cambiarla acá lo duplicaría en la próxima
        // importación.
        $data = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'cuit' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'mail' => ['nullable', 'email', 'max:255'],
            'localidad' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $proveedor->update($data);

        return redirect()->route('proveedores.edit', $proveedor)
            ->with('success', 'Proveedor actualizado correctamente.');
    }

    public function import(Request $request, ProveedorImportService $service): RedirectResponse
    {
        $this->authorize('proveedores.import');

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        try {
            $resultado = $service->import($data['archivo']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('proveedores.index')->with('error', $e->getMessage());
        }

        $redirect = redirect()->route('proveedores.index')
            ->with('success', "Importación completa: {$resultado['creados']} proveedores nuevos, {$resultado['actualizados']} actualizados.");

        if ($resultado['advertencias'] !== []) {
            $redirect->with('error', implode(' | ', $resultado['advertencias']));
        }

        return $redirect;
    }
}
