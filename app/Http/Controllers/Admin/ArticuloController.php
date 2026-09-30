<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Concerns\VuelveAlListado;
use App\Http\Controllers\Controller;
use App\Jobs\SyncArticulosJob;
use App\Models\Articulo;
use App\Models\Proveedor;
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
    use OrdenaListados, VuelveAlListado;

    /**
     * Qué columnas se pueden ordenar y con qué expresión. La clave es lo que
     * viaja en la URL; el valor, el SQL.
     *
     * La comparten el listado y el export: "lo que ves es lo que baja" ya vale
     * para los filtros, y el orden es parte de lo que se ve — si la pantalla
     * está ordenada por vencimiento porque se está armando la lista de lo que
     * vence, el Excel tiene que bajar así o hay que rehacerlo a mano.
     *
     * "Observaciones" queda afuera: es texto libre largo y ordenarlo no
     * responde ninguna pregunta.
     *
     * @return array<string, string|Closure>
     */
    private function ordenables(): array
    {
        return [
            'codigo' => 'articulos.codigo',
            'descripcion' => 'articulos.descripcion',
            'estado' => 'articulos.activo',
            // Subconsulta y **no** un join: `filtrados()` se comparte con el
            // export y devuelve el modelo entero, así que un join traería
            // `proveedores.id` pisando `articulos.id` en el resultado. Laravel
            // inserta la subconsulta en el ORDER BY y la lista sigue siendo de
            // artículos.
            'proveedor' => fn (Builder $q, string $dir) => $q->orderBy(
                Proveedor::query()
                    ->select('razon_social')
                    ->whereColumn('proveedores.id', 'articulos.proveedor_id'),
                $dir,
            ),
            'pm' => 'articulos.pm',
            'legajo' => 'articulos.legajo',
            'vencimiento' => 'articulos.fecha_vencimiento',
        ];
    }

    public function index(Request $request): Response
    {
        $this->authorize('articulos.view');

        $search = $request->string('search')->trim()->value();
        $estado = $request->string('estado')->trim()->value();

        $proveedor = $request->string('proveedor')->trim()->value();

        // Sin `orderBy` acá: lo resuelve `filtrados()`, que es lo que hace que
        // el export herede el mismo orden.
        $articulos = $this->filtrados($request)->paginate(50)->withQueryString();

        $lastSync = Articulo::max('synced_at');

        return inertia('Admin/Articulos/Index', [
            'articulos' => $articulos,
            'filters' => ['search' => $search, 'estado' => $estado, 'proveedor' => $proveedor],
            'orden' => $this->orden($request, $this->ordenables()),
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

        // Sin `orderBy` repetido: el orden ya lo resuelve `filtrados()`, que es
        // el mismo que usa el listado.
        $articulos = $this->filtrados($request)->get();

        return (new ArticuloExportService)->exportar($articulos);
    }

    /**
     * La query del listado con los filtros y el orden de la request aplicados.
     * La comparten el listado y la exportación a Excel.
     */
    private function filtrados(Request $request): Builder
    {
        $search = $request->string('search')->trim()->value();
        $estado = $request->string('estado')->trim()->value();
        $proveedor = $request->string('proveedor')->trim()->value();

        $query = Articulo::query()
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

        return $this->aplicarOrden(
            $query,
            $request,
            $this->ordenables(),
            // El orden propio de esta pantalla cuando no se pidió otro. Antes
            // vivía encadenado al final de este método; movido acá porque
            // encadenado primero dejaba cualquier `?sort=` como criterio
            // secundario, o sea sin efecto.
            fn (Builder $q) => $q->orderBy('articulos.descripcion'),
            'articulos.id',
        );
    }

    public function sync(): RedirectResponse
    {
        $this->authorize('articulos.sync');

        SyncArticulosJob::dispatch();

        // `back()`: el botón está en el listado y sincronizar no tiene por qué
        // descartar los filtros que el usuario tenía puestos.
        return back(fallback: route('articulos.index'))
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }

    public function edit(Articulo $articulo): Response
    {
        $this->authorize('articulos.edit');

        return inertia('Admin/Articulos/Edit', [
            'articulo' => $articulo->load('proveedor:id,numero,razon_social'),
            'tiposAnmat' => config('articulos.tipos_anmat'),
        ]);
    }

    public function update(Request $request, Articulo $articulo): RedirectResponse
    {
        $this->authorize('articulos.edit');

        $data = $request->validate([
            // ⚠️ `pm` no está: desde el 30/9/2026 lo escribe la sincronización
            // desde `ARTICULOS.NRO_REGISTRO`. Dejarlo acá ofrecería un campo
            // que la corrida de las 03:00 pisa sin avisar. La ficha lo muestra
            // junto al resto de los datos del ERP, en solo lectura.
            'fecha_vencimiento' => ['nullable', 'date'],
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

        // Vuelve al listado filtrado y no a la ficha — ver el trait
        // `VuelveAlListado` y el comentario equivalente en ClienteController.
        return $this->alListado($request, 'articulos.index')
            ->with('success', 'Artículo actualizado correctamente.');
    }

    public function import(Request $request, ArticuloImportService $service): RedirectResponse
    {
        $this->authorize('articulos.import');

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'crear_faltantes' => ['boolean'],
        ]);

        // `back()` en las dos salidas: el import se dispara desde un modal del
        // listado, así que el redirect pelado sacaba al usuario de su búsqueda.
        try {
            $resultado = $service->import($data['archivo'], $request->boolean('crear_faltantes'));
        } catch (\InvalidArgumentException $e) {
            return back(fallback: route('articulos.index'))->with('error', $e->getMessage());
        }

        $mensaje = "Importación completa: {$resultado['actualizados']} artículos actualizados";

        if ($resultado['creados'] > 0) {
            $mensaje .= ", {$resultado['creados']} creados";
        }

        if ($resultado['proveedoresCreados'] > 0) {
            $mensaje .= ", {$resultado['proveedoresCreados']} proveedores nuevos";
        }

        $mensaje .= '.';

        $redirect = back(fallback: route('articulos.index'))->with('success', $mensaje);

        if ($resultado['advertencias'] !== []) {
            $redirect->with('error', implode(' | ', $resultado['advertencias']));
        }

        return $redirect;
    }
}
