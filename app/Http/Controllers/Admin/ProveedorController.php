<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProveedoresJob;
use App\Models\Proveedor;
use App\Services\ProveedorExportService;
use App\Services\ProveedorImportService;
use App\Support\Documentacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProveedorController extends Controller
{
    /** Tope de resultados del buscador: es un autocompletado, no un listado. */
    private const LIMITE = 20;

    /** Menos que esto devolvería medio padrón y no ayuda a completar nada. */
    private const MINIMO = 2;

    public function index(Request $request): Response
    {
        $this->authorize('proveedores.view');

        $proveedores = $this->filtrados($request)
            // El semáforo del listado necesita saber si hay algún documento
            // vencido. Se resuelve con un `exists` en SQL en vez de traer el
            // checklist de las 50 filas y recorrerlo en PHP.
            ->withExists(['documentos as tiene_vencidos' => fn ($q) => $q
                ->where('presentado', true)
                ->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '<', now()),
            ])
            ->paginate(50)
            ->withQueryString();

        return inertia('Admin/Proveedores/Index', [
            'proveedores' => $proveedores,
            'filters' => [
                'search' => $request->string('search')->trim()->value(),
                'tipo_proveedor' => $request->string('tipo_proveedor')->trim()->value(),
                'estado_documental' => $request->string('estado_documental')->trim()->value(),
            ],
            'tipos' => Documentacion::etiquetasTipos(Documentacion::PROVEEDORES),
            // Sin filtrar: el paginador ya trae el total de la búsqueda vigente.
            'total' => Proveedor::count(),
            'lastSync' => Proveedor::max('synced_at'),
        ]);
    }

    /**
     * La query del listado con los filtros de la request aplicados. La
     * comparten el listado y la exportación a Excel: lo que ves es lo que baja.
     */
    private function filtrados(Request $request): Builder
    {
        $search = $request->string('search')->trim()->value();
        $tipo = $request->string('tipo_proveedor')->trim()->value();
        $estado = $request->string('estado_documental')->trim()->value();

        return Proveedor::query()
            ->when($search, fn ($q) => $q->buscar($search))
            ->when($tipo, fn ($q) => $q->where('tipo_proveedor', $tipo))
            ->when($estado, fn ($q) => $this->filtrarPorEstadoDocumental($q, $estado))
            ->orderBy('razon_social');
    }

    /**
     * Filtro del listado por estado documental.
     *
     * `completa` e `incompleta` se apoyan en la columna denormalizada
     * `documentacion_completa` (el estado sale del catálogo en config, no se
     * puede expresar en un `where`); `vencida` sí se consulta sobre el checklist.
     */
    private function filtrarPorEstadoDocumental(Builder $query, string $estado): void
    {
        match ($estado) {
            'completa' => $query->where('documentacion_completa', true),
            'incompleta' => $query->where('documentacion_completa', false)->whereNotNull('tipo_proveedor'),
            'vencida' => $query->whereHas('documentos', fn ($q) => $q
                ->where('presentado', true)
                ->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '<', now())),
            'sin_tipo' => $query->whereNull('tipo_proveedor'),
            default => null,
        };
    }

    /**
     * Exporta el padrón a Excel, con el mismo filtro que el listado: lo que ves
     * es lo que baja.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('proveedores.view');

        $proveedores = $this->filtrados($request)->with('documentos')->get();

        return (new ProveedorExportService)->exportar($proveedores);
    }

    public function sync(): RedirectResponse
    {
        $this->authorize('proveedores.sync');

        SyncProveedoresJob::dispatch();

        return redirect()->route('proveedores.index')
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
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

        $proveedor->load('documentos');

        return inertia('Admin/Proveedores/Edit', [
            'proveedor' => $proveedor,
            'tipos' => Documentacion::etiquetasTipos(Documentacion::PROVEEDORES),
            'documentos' => $this->checklist($proveedor),
            'estado' => $proveedor->estadoDocumentacion(),
            'documentoDeterminante' => Documentacion::documentoDeterminante($proveedor->tipo_proveedor),
        ]);
    }

    /**
     * El checklist que ve la ficha: el catálogo del tipo (que manda) con lo que
     * el proveedor tenga cargado de cada documento. Un documento del catálogo
     * sin fila todavía sale como no presentado, no como ausente.
     */
    private function checklist(Proveedor $proveedor): array
    {
        $cargados = $proveedor->documentos->keyBy('documento');

        $items = [];

        foreach (Documentacion::documentos($proveedor->tipo_proveedor) as $clave => $def) {
            $doc = $cargados->get($clave);

            $items[] = [
                'documento' => $clave,
                'label' => $def['label'],
                'obligatorio' => (bool) $def['obligatorio'],
                'vence' => (bool) $def['vence'],
                'determina_vencimiento' => ! empty($def['determina_vencimiento']),
                'presentado' => (bool) $doc?->presentado,
                'fecha_vencimiento' => $doc?->fecha_vencimiento?->toDateString(),
            ];
        }

        return $items;
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
            // `fecha_vencimiento` no está acá a propósito: es derivado, sale
            // del documento que lo determina. Ver recalcularEstadoDocumental().
            'tipo_proveedor' => ['nullable', Rule::in(array_keys(Documentacion::tipos(Documentacion::PROVEEDORES)))],
            // `sometimes` y no `required`: un request que no los manda deja el
            // valor que ya estaba, en vez de fallar. El formulario siempre los
            // envía; esto es para no romper cualquier otro camino que actualice
            // solo algunos campos.
            'tiene_legajo' => ['sometimes', 'boolean'],
            'habilitado' => ['sometimes', 'boolean'],
        ]);

        $proveedor->update($data);

        // Cambiar de tipo cambia qué documentos se exigen y cuál determina el
        // vencimiento, así que el estado se recalcula también desde acá.
        $proveedor->recalcularEstadoDocumental();

        return redirect()->route('proveedores.edit', $proveedor)
            ->with('success', 'Proveedor actualizado correctamente.');
    }

    /**
     * Guarda el checklist de documentación completo (Sí/No y vencimiento de
     * cada documento) y recalcula el estado derivado del proveedor.
     */
    public function updateDocumentacion(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $this->authorize('proveedores.edit');

        if ($proveedor->tipo_proveedor === null) {
            return back()->with('error', 'Elegí primero un tipo de proveedor: la documentación requerida depende de él.');
        }

        $data = $request->validate(
            Documentacion::reglasValidacion($proveedor->tipo_proveedor),
            attributes: Documentacion::atributosValidacion($proveedor->tipo_proveedor),
        );

        $catalogo = Documentacion::documentos($proveedor->tipo_proveedor);

        DB::transaction(function () use ($proveedor, $catalogo, $data) {
            foreach ($catalogo as $clave => $def) {
                $item = $data['documentos'][$clave] ?? null;

                if ($item === null) {
                    continue;
                }

                $presentado = (bool) $item['presentado'];

                $proveedor->documentos()->updateOrCreate(
                    ['documento' => $clave],
                    [
                        'presentado' => $presentado,
                        // La fecha solo tiene sentido en un documento que vence
                        // y que además está presentado: si se destilda, la del
                        // papel anterior no puede quedar colgada.
                        'fecha_vencimiento' => empty($def['vence']) || ! $presentado
                            ? null
                            : ($item['fecha_vencimiento'] ?? null),
                    ]
                );
            }

            // Los documentos que quedaron de un tipo anterior no se muestran ni
            // cuentan, así que tampoco se guardan.
            $proveedor->documentos()
                ->whereNotIn('documento', array_keys($catalogo))
                ->delete();
        });

        $proveedor->recalcularEstadoDocumental();

        return redirect()->route('proveedores.edit', $proveedor)
            ->with('success', 'Documentación actualizada correctamente.');
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
