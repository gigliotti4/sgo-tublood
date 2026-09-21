<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Concerns\VuelveAlListado;
use App\Http\Controllers\Controller;
use App\Jobs\SyncClientesJob;
use App\Models\Cliente;
use App\Models\ClienteAttachment;
use App\Services\ClienteExportService;
use App\Services\ClienteImportService;
use App\Support\Documentacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClienteController extends Controller
{
    use OrdenaListados, VuelveAlListado;

    /**
     * Qué columnas se pueden ordenar. La clave es lo que viaja en la URL; el
     * valor, el SQL. La comparten el listado y el export.
     *
     * "Documentación" ordena por la columna denormalizada
     * `documentacion_completa` y no por el semáforo completo: ese estado se
     * deriva del catálogo en config y no se puede expresar en SQL — es
     * justamente el motivo por el que esa columna existe.
     *
     * @return array<string, string>
     */
    private function ordenables(): array
    {
        return [
            'numero' => 'clientes.numero',
            'razon_social' => 'clientes.razon_social',
            'cuit' => 'clientes.cuit',
            'iva' => 'clientes.descripcion_iva',
            'localidad' => 'clientes.localidad',
            'telefono' => 'clientes.telefono',
            'mail' => 'clientes.mail',
            'vencimiento' => 'clientes.fecha_vencimiento',
            'tipo' => 'clientes.tipo_cliente',
            'documentacion' => 'clientes.documentacion_completa',
        ];
    }

    public function index(Request $request): Response
    {
        $this->authorize('clientes.view');

        $search = $request->string('search')->trim()->value();
        $tipo = $request->string('tipo_cliente')->trim()->value();
        $estado = $request->string('estado_documental')->trim()->value();

        $clientes = $this->filtrados($request)
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

        $lastSync = Cliente::max('synced_at');

        return inertia('Admin/Clientes/Index', [
            'clientes' => $clientes,
            'filters' => [
                'search' => $search,
                'tipo_cliente' => $tipo,
                'estado_documental' => $estado,
            ],
            'orden' => $this->orden($request, $this->ordenables()),
            'tipos' => Documentacion::etiquetasTipos(),
            'lastSync' => $lastSync,
            // Sin filtrar: el paginador ya trae el total de la búsqueda vigente.
            'total' => Cliente::count(),
        ]);
    }

    /**
     * Resuelve la razón social de un N° de cliente para autocompletar el
     * formulario de carga interna de observaciones
     * (Admin/Observaciones/CrearInterna.vue).
     *
     * Coincidencia **exacta**, no un buscador de texto: es la misma columna
     * que usa `ObservacionController::clienteIdDesdeNumero()` para vincular el
     * caso, así que lo que se ve acá tiene que ser justo lo que va a matchear
     * al guardar. Devuelve solo `id`, `numero` y `razon_social` — nada de
     * mail, teléfono ni documentación, que no hacen falta para esto.
     *
     * Detrás de `clientes.view` a propósito: expone la relación
     * número → razón social del padrón, y no está pensado para exponerse
     * público (a diferencia de `articulos.buscar`, que sí lo está).
     *
     * ⚠️ Sin match responde `{}` y no `null`: `response()->json(null)`
     * serializa como `{}` en esta versión de Symfony (su constructor hace
     * `$data ??= new ArrayObject()` antes de codificar), así que "vacío" no
     * se puede distinguir en el JSON. El frontend no debe chequear el objeto
     * entero como truthy — tiene que mirar la presencia de `id`.
     */
    public function buscarPorNumero(Request $request): JsonResponse
    {
        $this->authorize('clientes.view');

        $numero = trim((string) $request->query('numero', ''));

        if ($numero === '') {
            return response()->json(null);
        }

        $cliente = Cliente::where('numero', $numero)->first(['id', 'numero', 'razon_social']);

        return response()->json($cliente);
    }

    /**
     * La query del listado con los filtros de la request aplicados.
     *
     * La comparten el listado y la exportación a Excel: lo que ves en pantalla
     * es exactamente lo que baja en el archivo.
     */
    private function filtrados(Request $request): Builder
    {
        $search = $request->string('search')->trim()->value();
        $tipo = $request->string('tipo_cliente')->trim()->value();
        $estado = $request->string('estado_documental')->trim()->value();

        $query = Cliente::query()
            ->when($search, function ($q) use ($search) {
                // Agrupado: sin el closure, los `orWhere` se escaparían de los
                // otros filtros y el de tipo/estado dejaría de aplicar.
                $q->where(function ($q) use ($search) {
                    $q->where('razon_social', 'like', "%{$search}%")
                        ->orWhere('cuit', 'like', "%{$search}%")
                        ->orWhere('numero', 'like', "%{$search}%")
                        ->orWhere('mail', 'like', "%{$search}%");
                });
            })
            ->when($tipo, fn ($q) => $q->where('tipo_cliente', $tipo))
            ->when($estado, fn ($q) => $this->filtrarPorEstadoDocumental($q, $estado));

        return $this->aplicarOrden(
            $query,
            $request,
            $this->ordenables(),
            // El orden propio de la pantalla. Va como callback y no encadenado
            // arriba: encadenado primero dejaba cualquier `?sort=` como
            // criterio secundario, o sea sin efecto.
            fn (Builder $q) => $q->orderBy('clientes.razon_social'),
            'clientes.id',
        );
    }

    /**
     * Exporta a Excel lo que muestra el listado, con el mismo filtro.
     *
     * El archivo que sale es además la plantilla del import — ver
     * ClienteExportService.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('clientes.view');

        $clientes = $this->filtrados($request)->with('documentos')->get();

        return (new ClienteExportService)->exportar($clientes);
    }

    public function import(Request $request, ClienteImportService $service): RedirectResponse
    {
        $this->authorize('clientes.import');

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        // `back()` en las dos salidas: el import se dispara desde un modal del
        // listado, así que el redirect pelado sacaba al usuario de la búsqueda
        // que tenía puesta. El `fallback` cubre el caso sin Referer.
        try {
            $resultado = $service->import($data['archivo']);
        } catch (\InvalidArgumentException $e) {
            return back(fallback: route('clientes.index'))->with('error', $e->getMessage());
        }

        $redirect = back(fallback: route('clientes.index'))->with(
            'success',
            "Importación completa: {$resultado['actualizados']} clientes actualizados, {$resultado['documentos']} documentos cargados."
        );

        if ($resultado['advertencias'] !== []) {
            $redirect->with('error', implode(' | ', $resultado['advertencias']));
        }

        return $redirect;
    }

    /**
     * Filtro del listado por estado documental.
     *
     * `completa` y `incompleta` se apoyan en la columna denormalizada
     * `documentacion_completa` (el estado sale del catálogo en config, no se
     * puede expresar en un `where`); `vencida` sí se puede consultar directo
     * sobre el checklist.
     */
    private function filtrarPorEstadoDocumental(Builder $query, string $estado): void
    {
        match ($estado) {
            'completa' => $query->where('documentacion_completa', true),
            'incompleta' => $query->where('documentacion_completa', false)->whereNotNull('tipo_cliente'),
            'vencida' => $query->whereHas('documentos', fn ($q) => $q
                ->where('presentado', true)
                ->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '<', now())),
            'sin_tipo' => $query->whereNull('tipo_cliente'),
            default => null,
        };
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->authorize('clientes.sync');

        SyncClientesJob::dispatch();

        // `back()`: el botón está en el listado y sincronizar no tiene por qué
        // descartar los filtros que el usuario tenía puestos.
        return back(fallback: route('clientes.index'))
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }

    public function edit(Cliente $cliente): Response
    {
        $this->authorize('clientes.edit');

        $cliente->load('attachments', 'documentos');

        return inertia('Admin/Clientes/Edit', [
            'cliente' => $cliente,
            'tipos' => Documentacion::etiquetasTipos(),
            // El catálogo de **todos** los tipos, no el del tipo guardado: la
            // ficha arma el checklist con el que esté elegido en el select, sin
            // esperar a guardar. Ver Documentacion::checklistPorTipo().
            'catalogoDocumentos' => Documentacion::checklistPorTipo(),
            // Lo cargado, por clave de documento. Va aparte del catálogo porque
            // no depende del tipo: las claves se repiten entre tipos a
            // propósito, así reclasificar no borra lo que los dos comparten.
            'documentosCargados' => $this->documentosCargados($cliente),
            'estado' => $cliente->estadoDocumentacion(),
            'documentoDeterminante' => Documentacion::documentoDeterminante($cliente->tipo_cliente),
        ]);
    }

    /**
     * El checklist que ve la ficha: el catálogo del tipo (que manda) con lo que
     * el cliente tenga cargado de cada documento. Un documento del catálogo sin
     * fila todavía sale como no presentado, no como ausente.
     */
    /**
     * Lo que el cliente ya tiene cargado, `[documento => {presentado, fecha}]`.
     *
     * Sin recortar por tipo a propósito: la pantalla cruza esto con el catálogo
     * del tipo elegido, así que si alguien cambia el select y vuelve atrás, lo
     * que había cargado del tipo original sigue ahí.
     */
    private function documentosCargados(Cliente $cliente): array
    {
        return $cliente->documentos
            ->mapWithKeys(fn ($doc) => [$doc->documento => [
                'presentado' => (bool) $doc->presentado,
                'fecha_vencimiento' => $doc->fecha_vencimiento?->toDateString(),
            ]])
            ->all();
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('clientes.edit');

        // `fecha_vencimiento` no está acá a propósito: dejó de cargarse a mano
        // y ahora sale del documento que lo determina — ver
        // Cliente::recalcularEstadoDocumental().
        $reglas = [
            'mail_nuevo' => ['nullable', 'email', 'max:255'],
            'tipo_cliente' => ['nullable', Rule::in(array_keys(Documentacion::tipos()))],
            // `sometimes` y no `required`: un request que no los manda deja el
            // valor que ya estaba, en vez de fallar. El formulario siempre los
            // envía; esto es para no romper cualquier otro camino que actualice
            // solo algunos campos.
            'tiene_legajo' => ['sometimes', 'boolean'],
            'habilitado' => ['sometimes', 'boolean'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];

        // El checklist viaja en el **mismo** guardado que el tipo. Antes tenía
        // su propio endpoint, y como las reglas se armaban contra el tipo ya
        // guardado, había que guardar dos veces: una para el tipo y otra para
        // los documentos. Acá se validan contra el tipo que viene en el request.
        //
        // `has()` y no siempre: así un request que solo actualiza datos
        // generales (o cualquier otro camino) no está obligado a mandarlos,
        // mismo criterio que el `sometimes` de arriba.
        // El tipo con el que se valida el checklist es el que **viene en el
        // request**, no el guardado: es lo que permite reclasificar y cargar los
        // papeles del tipo nuevo en un solo guardado. Si el request no lo manda
        // (una actualización parcial), se cae al que ya tenía.
        $tipo = $request->has('tipo_cliente')
            ? ($request->filled('tipo_cliente') ? $request->input('tipo_cliente') : null)
            : $cliente->tipo_cliente;
        $conDocumentos = $request->has('documentos');

        if ($conDocumentos) {
            $reglas += Documentacion::reglasValidacion($tipo);
        }

        $data = $request->validate(
            $reglas,
            attributes: $conDocumentos ? Documentacion::atributosValidacion($tipo) : [],
        );

        $cliente->update(Arr::except($data, ['documentos']));

        if ($conDocumentos) {
            $cliente->guardarDocumentos($data['documentos'] ?? []);
        }

        // Cambiar de tipo cambia qué documentos se exigen y cuál determina el
        // vencimiento, así que el estado se recalcula también desde acá.
        $cliente->recalcularEstadoDocumental();

        // Vuelve al listado con los filtros y la página que traía, no a la
        // ficha: guardar terminaba dejando al usuario adentro y el único camino
        // de salida era un "← Volver" que descartaba la búsqueda. Ver el trait
        // `VuelveAlListado`.
        return $this->alListado($request, 'clientes.index')
            ->with('success', 'Cliente actualizado correctamente.');
    }

    /**
     * Adjunta archivos sueltos a la ficha del cliente.
     *
     * (El docblock que había acá describía `updateDocumentacion()`, un método
     * que ya no existe: el checklist se fusionó dentro de `update()`.)
     */
    public function uploadArchivo(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('clientes.edit');

        $data = $request->validate([
            'archivos' => ['required', 'array'],
            'archivos.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:10240'],
        ]);

        foreach ($data['archivos'] as $file) {
            $cliente->guardarAdjunto($file);
        }

        // `back()` y no `route('clientes.edit', $cliente)`: son la misma
        // pantalla, pero el redirect armado a mano pierde el `?volver=` que
        // trae la ficha — y con él, la vuelta al listado filtrado. Ver el trait
        // `VuelveAlListado`.
        return back(fallback: route('clientes.edit', $cliente))
            ->with('success', 'Archivos subidos correctamente.');
    }

    public function downloadArchivo(Cliente $cliente, ClienteAttachment $attachment): StreamedResponse
    {
        $this->authorize('clientes.view');

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    public function destroyArchivo(Cliente $cliente, ClienteAttachment $attachment): RedirectResponse
    {
        $this->authorize('clientes.edit');

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        // Ver el comentario de `uploadArchivo()`.
        return back(fallback: route('clientes.edit', $cliente))
            ->with('success', 'Archivo eliminado correctamente.');
    }
}
