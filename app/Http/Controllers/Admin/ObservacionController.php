<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\ObservationAttachment;
use App\Models\ObservationHistory;
use App\Models\ObservationProduct;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionSeguimientoNotification;
use App\Support\TaxonomiaIncidencias;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ObservacionController extends Controller
{
    private const PROVINCIAS = [
        'Buenos Aires', 'Catamarca', 'Chaco', 'Chubut',
        'Ciudad Autónoma de Buenos Aires', 'Córdoba', 'Corrientes', 'Entre Ríos',
        'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
        'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz',
        'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
    ];

    public function index(Request $request)
    {
        $this->authorize('observaciones.view');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'origen' => ['nullable', Rule::in(array_keys(Observacion::ORIGENES))],
            'prioridad' => ['nullable', Rule::in(array_keys(config('incidencias.prioridades')))],
            'tipo_caso' => ['nullable', Rule::in(config('incidencias.tipos_caso'))],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'creado_por' => ['nullable', 'integer', 'exists:users,id'],
            'apertura' => ['nullable', 'in:abierta,cerrada'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        return inertia('Admin/Observaciones/Index', [
            'observaciones' => Observacion::query()
                ->with([
                    'responsable:id,name', 'sector:id,nombre', 'cliente:id,numero,razon_social,mail,telefono', 'productos',
                    ...$this->eagerLoadsDeGestion(),
                ])
                // Texto libre: un solo campo que barre número, título, descripción,
                // cliente (vinculado o los datos tipeados en el portal) y productos.
                ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(function ($query) use ($q) {
                    $query->where('numero', 'like', "%{$q}%")
                        ->orWhere('titulo', 'like', "%{$q}%")
                        ->orWhere('descripcion', 'like', "%{$q}%")
                        ->orWhere('contacto_nombre', 'like', "%{$q}%")
                        ->orWhereHas('cliente', fn ($c) => $c
                            ->where('razon_social', 'like', "%{$q}%")
                            ->orWhere('numero', 'like', "%{$q}%"))
                        ->orWhereHas('productos', fn ($p) => $p
                            ->where('producto', 'like', "%{$q}%")
                            ->orWhere('codigo', 'like', "%{$q}%")
                            ->orWhere('lote', 'like', "%{$q}%"));
                }))
                ->when($filters['origen'] ?? null, fn ($query, $v) => $query->where('origen', $v))
                ->when($filters['prioridad'] ?? null, fn ($query, $v) => $query->where('prioridad', $v))
                ->when($filters['tipo_caso'] ?? null, fn ($query, $v) => $query->where('tipo_caso', $v))
                ->when($filters['responsable_id'] ?? null, fn ($query, $v) => $query->where('responsable_id', $v))
                ->when($filters['creado_por'] ?? null, fn ($query, $v) => $query->where('created_by', $v))
                ->when($filters['apertura'] ?? null, fn ($query, $v) => $v === 'abierta'
                    ? $query->whereIn('estado', Observacion::ESTADOS_ABIERTOS)
                    : $query->whereNotIn('estado', Observacion::ESTADOS_ABIERTOS))
                ->when($filters['desde'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '>=', $v))
                ->when($filters['hasta'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '<=', $v))
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            // Cualquiera que vea el listado puede necesitar reasignar responsable/sector
            // en las filas que sí puede editar (ver ObservacionPolicy::update).
            'usuarios' => $this->usuariosAsignables(),
            'sectores' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'presentaciones' => ObservationProduct::PRESENTACIONES,
            'tipoLabels' => TaxonomiaIncidencias::etiquetasTipos(),
            'prioridades' => config('incidencias.prioridades'),
            'tiposCaso' => config('incidencias.tipos_caso'),
        ]);
    }

    /**
     * Detalle de solo lectura.
     *
     * El modal del listado solo lo abre quien puede editar (responsable o
     * super-admin), así que sin esta pantalla alguien con `observaciones.view`
     * no tenía forma de ver el caso completo ni de bajarse los adjuntos.
     */
    public function show(Request $request, Observacion $observacion)
    {
        $this->authorize('observaciones.view');

        return inertia('Admin/Observaciones/Show', [
            'observacion' => $observacion->load([
                'responsable:id,name,apellido',
                'sector:id,nombre,dias_gestion',
                'cliente:id,numero,razon_social,mail,telefono',
                'productos',
                ...$this->eagerLoadsDeGestion(),
            ]),
            'presentaciones' => ObservationProduct::PRESENTACIONES,
            'tipoLabels' => TaxonomiaIncidencias::etiquetasTipos(),
            'prioridades' => config('incidencias.prioridades'),
            'puedeEditar' => $request->user()?->can('update', $observacion) ?? false,
            // Más amplio que puedeEditar: incluye a los usuarios a notificar.
            'puedeComentar' => $request->user()?->can('comentar', $observacion) ?? false,
        ]);
    }

    /**
     * PDF del detalle, para expediente o para mandarle al cliente.
     *
     * Se usa DomPDF (PHP puro) y no Browsershot: el deploy es hosting
     * compartido, donde no hay Node ni Chromium. La contra es que la plantilla
     * (resources/views/pdf/observacion.blade.php) es CSS 2.1 y no reusa
     * Tailwind, así que hay que mantenerla junto con Show.vue.
     */
    public function pdf(Observacion $observacion): Response
    {
        $this->authorize('observaciones.view');

        $observacion->load([
            'responsable:id,name,apellido',
            'sector:id,nombre',
            'cliente:id,numero,razon_social,mail,telefono',
            'productos',
            'attachments:id,observation_id,original_name,size',
        ]);

        $pdf = Pdf::loadView('pdf.observacion', [
            'observacion' => $observacion,
            'presentaciones' => ObservationProduct::PRESENTACIONES,
            'tipoLabels' => TaxonomiaIncidencias::etiquetasTipos(),
            'prioridades' => config('incidencias.prioridades'),
            'estados' => Observacion::ESTADOS,
            'emitido' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4');

        return $pdf->download("observacion-{$observacion->numero}.pdf");
    }

    /**
     * Suma archivos a una observación ya creada.
     *
     * Hasta acá los adjuntos solo se podían cargar en el alta. Autoriza con la
     * Policy y no con el permiso `observaciones.edit`: subir documentación es
     * parte de gestionar el caso, así que lo hace quien lo tiene asignado.
     *
     * Las reglas son más amplias que las del portal (que es público y por eso
     * acepta solo imágenes y PDF de hasta 3 MB): acá se suben informes y
     * planillas, igual que en los adjuntos de cliente.
     */
    public function uploadArchivo(Request $request, Observacion $observacion): RedirectResponse
    {
        $this->authorize('update', $observacion);

        $data = $request->validate([
            'archivos' => ['required', 'array'],
            'archivos.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:10240'],
        ]);

        foreach ($data['archivos'] as $file) {
            $observacion->guardarAdjunto($file);
        }

        // back() y no la pantalla de detalle: los adjuntos se gestionan desde
        // ahí y también desde el modal del listado, y mandarlo al detalle
        // sacaría al usuario del listado en el que estaba trabajando.
        return back()->with('success', 'Archivos subidos correctamente.');
    }

    public function destroyArchivo(Observacion $observacion, ObservationAttachment $attachment): RedirectResponse
    {
        $this->authorize('update', $observacion);

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return back()->with('success', 'Archivo eliminado correctamente.');
    }

    /**
     * Comentario manual en la bitácora, con adjuntos opcionales.
     *
     * Autoriza con la Policy y no con el permiso `observaciones.edit`: dejar
     * constancia de algo es parte de gestionar el caso, igual que subir un
     * adjunto suelto o cambiar el estado.
     *
     * La entrada de bitácora se crea aunque no haya archivos (una nota sola es
     * válida) y los archivos, si los hay, se guardan atados a esa entrada — es
     * lo que la distingue de `uploadArchivo()`, que sube documentación suelta
     * sin dejar una entrada propia.
     */
    public function comentar(Request $request, Observacion $observacion): RedirectResponse
    {
        // `comentar` y no `update`: también comentan los usuarios sumados como
        // "a notificar", que no pueden gestionar el caso.
        $this->authorize('comentar', $observacion);

        $data = $request->validate([
            'nota' => ['nullable', 'string', 'max:5000'],
            'archivos' => ['array'],
            'archivos.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:10240'],
        ]);

        if (blank($data['nota'] ?? null) && empty($request->file('archivos', []))) {
            throw ValidationException::withMessages([
                'nota' => 'Escribí algo o adjuntá un archivo.',
            ]);
        }

        $entrada = $observacion->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => ObservationHistory::ACCION_COMENTARIO,
            'nota' => $data['nota'] ?? null,
        ]);

        foreach ($request->file('archivos', []) as $file) {
            $observacion->guardarAdjunto($file, [
                'observation_history_id' => $entrada->id,
                'user_id' => $request->user()->id,
            ], subcarpeta: 'bitacora');
        }

        // back() y no la pantalla de detalle: la bitácora se gestiona desde ahí
        // y también desde el modal del listado, igual que los adjuntos sueltos.
        return back()->with('success', 'Comentario agregado a la bitácora.');
    }

    /**
     * Descarga un adjunto de la observación.
     *
     * Los adjuntos se venían guardando desde el alta (portal y carga interna)
     * pero no había ninguna ruta que los sirviera: quedaban en el disco privado
     * sin forma de abrirlos. Espeja a ClienteController::downloadArchivo.
     */
    public function downloadArchivo(Observacion $observacion, ObservationAttachment $attachment): StreamedResponse
    {
        $this->authorize('observaciones.view');

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    public function nuevo(Request $request)
    {
        $this->authorize('observaciones.edit');

        return inertia('Admin/Observaciones/Nuevo');
    }

    public function create(Request $request)
    {
        $this->authorize('observaciones.edit');

        return inertia('Admin/Observaciones/CrearInterna', [
            'sectores' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'slug']),
            'taxonomia' => TaxonomiaIncidencias::taxonomia(),
            'provincias' => self::PROVINCIAS,
            'presentaciones' => ObservationProduct::PRESENTACIONES,
            'prioridades' => config('incidencias.prioridades'),
            'tiposCaso' => config('incidencias.tipos_caso'),
            // Cast a objeto para que llegue como {} y no como [] cuando está
            // vacío: el front lo tipa como Record<string, string>.
            'prioridadSugerida' => (object) config('incidencias.prioridad_sugerida'),
            'usuarios' => $this->usuariosAsignables(),
        ]);
    }

    /**
     * Eager-loads compartidos por `index()` y `show()`, los dos lugares donde
     * se gestiona un caso: bitácora (con su autor y sus adjuntos) y los
     * adjuntos sueltos, que quedan aparte de los que cuelgan de una entrada de
     * bitácora para no mostrar el mismo archivo dos veces.
     *
     * @return array<string, \Closure>
     */
    private function eagerLoadsDeGestion(): array
    {
        return [
            'historial' => fn ($query) => $query->latest()
                ->with(['user:id,name,apellido', 'adjuntos:id,observation_history_id,original_name,size']),
            'attachments' => fn ($query) => $query->whereNull('observation_history_id')
                ->select(['id', 'observation_id', 'original_name', 'size']),
            // Columnas restringidas: esto va a props de Inertia y User tiene password.
            'notificados' => fn ($query) => $query->select(['users.id', 'name', 'apellido']),
        ];
    }

    /**
     * Sincroniza los usuarios a notificar y avisa **solo a los que se sumaron
     * en este guardado** — los que ya estaban no reciben otro mail cada vez que
     * alguien toca el caso.
     *
     * Deja también la entrada en la bitácora: `sync()` sobre una relación no
     * dispara el evento `updated` del modelo, así que ObservacionObserver no se
     * entera solo de este cambio.
     *
     * @param  array<int>  $ids
     */
    private function sincronizarNotificados(Observacion $observacion, array $ids): void
    {
        $previos = $observacion->notificados()->pluck('users.id')->all();

        $observacion->notificados()->sync($ids);

        $sumados = array_values(array_diff($ids, $previos));
        $sacados = array_values(array_diff($previos, $ids));

        if ($sumados === [] && $sacados === []) {
            return;
        }

        $nombres = fn (array $ids) => $ids === []
            ? []
            : User::whereKey($ids)->orderBy('name')->get()->map->nombreCompleto->all();

        ObservationHistory::create([
            'observation_id' => $observacion->id,
            'user_id' => auth()->id(),
            'accion' => ObservationHistory::ACCION_NOTIFICADOS,
            'cambios' => [
                'sumados' => $nombres($sumados),
                'sacados' => $nombres($sacados),
            ],
        ]);

        if ($sumados !== []) {
            Notification::send(
                User::whereKey($sumados)->get(),
                new ObservacionSeguimientoNotification($observacion)
            );
        }
    }

    /**
     * Candidatos a responsable. Traen su sector porque de ahí sale el plazo de
     * gestión: el formulario avisa a los cuántos días hábiles va a vencer la
     * observación, o que no va a alertar si esa persona no tiene plazo.
     */
    private function usuariosAsignables()
    {
        return User::with('sector:id,nombre,dias_gestion')
            ->orderBy('name')
            ->get(['id', 'name', 'apellido', 'sector_id']);
    }

    public function store(Request $request)
    {
        $this->authorize('observaciones.edit');

        return $this->storeInterna($request);
    }

    private function storeInterna(Request $request)
    {
        $base = $request->validate([
            'sector_id' => ['required', 'exists:sectors,id'],
            'tipo' => ['required', 'string'],
            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string'],
            'prioridad' => ['required', Rule::in(array_keys(config('incidencias.prioridades')))],
            'tipo_caso' => ['required', Rule::in(config('incidencias.tipos_caso'))],
            'responsable_id' => ['nullable', 'exists:users,id'],
            // Cliente involucrado (opcional): si el N° matchea un cliente
            // sincronizado de RP Sistemas, la observación queda vinculada.
            'contacto_numero_cliente' => ['nullable', 'string', 'max:255'],
            'contacto_nombre' => ['nullable', 'string', 'max:255'],
            'contacto_email' => ['nullable', 'email', 'max:255'],
            'notificados' => ['array'],
            'notificados.*' => ['integer', 'exists:users,id'],
            'attachments' => ['array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:3072'],
        ]);

        $sector = Sector::findOrFail($base['sector_id']);

        // El tipo de incidencia tiene que pertenecer al sector elegido: o bien está
        // en la taxonomía de "Datos específicos", o es uno de los tipos especiales
        // (Falla de Producto / Disconformidad de Servicio, hoy solo Garantía de Calidad).
        if (! TaxonomiaIncidencias::tipo($sector->slug, $base['tipo'])
            && ! TaxonomiaIncidencias::esTipoEspecial($sector->slug, $base['tipo'])) {
            throw ValidationException::withMessages([
                'tipo' => 'El tipo de incidencia no corresponde al sector seleccionado.',
            ]);
        }

        if (TaxonomiaIncidencias::esTipoEspecial($sector->slug, $base['tipo'])) {
            return $this->storeInternaEspecial($request, $base, $sector);
        }

        // Reglas dinámicas de "Datos específicos" según el tipo, con los labels de
        // la taxonomía como nombres de campo en los mensajes de error.
        $especificos = $request->validate(
            TaxonomiaIncidencias::reglasValidacion($sector->slug, $base['tipo']),
            [],
            TaxonomiaIncidencias::atributosValidacion($sector->slug, $base['tipo'])
        );

        DB::transaction(function () use ($base, $sector, $especificos, $request) {
            $anio = (int) now()->format('Y');

            $observacion = Observacion::create([
                'numero' => Observacion::generarNumero($anio),
                'anio' => $anio,
                'origen' => 'interna',
                'estado' => 'clasificada',
                'tipo' => $base['tipo'],
                'titulo' => $base['titulo'],
                'descripcion' => $base['descripcion'],
                'sector_id' => $sector->id,
                'responsable_id' => $base['responsable_id'] ?? null,
                'created_by' => $request->user()->id,
                'prioridad' => $base['prioridad'],
                'tipo_caso' => $base['tipo_caso'],
                'contacto_numero_cliente' => $base['contacto_numero_cliente'] ?? null,
                'contacto_nombre' => $base['contacto_nombre'] ?? null,
                'contacto_email' => $base['contacto_email'] ?? null,
                'cliente_id' => $this->clienteIdDesdeNumero($base['contacto_numero_cliente'] ?? null),
                'datos_especificos' => $especificos['datos_especificos'] ?? [],
            ]);

            $this->guardarAdjuntos($observacion, $request);
            $this->sincronizarNotificados($observacion, $base['notificados'] ?? []);
        });

        return redirect()->route('observaciones.index')
            ->with('success', 'Observación interna creada correctamente.');
    }

    /**
     * Tipos especiales (Falla de Producto / Disconformidad de Servicio): mismos
     * campos que la carga externa del portal público, sin datos de contacto —
     * acá el registro lo carga personal de Tublood, no el cliente.
     */
    private function storeInternaEspecial(Request $request, array $base, Sector $sector)
    {
        // Misma razón que en el portal público: la fila vacía del bloque de
        // productos oculto haría fallar `productos.*` con errores invisibles.
        if (! TaxonomiaIncidencias::llevaProductos($base['tipo'])) {
            $request->merge(['productos' => []]);
        }

        $data = $request->validate([
            'institucion' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'provincia' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'equipamiento' => ['nullable', 'string', 'max:255'],
            'ejecutivo_cuenta' => ['nullable', 'string', 'max:255'],

            'productos' => ['required_if:tipo,falla_producto', 'array'],
            'productos.*.producto' => ['required', 'string', 'max:255'],
            'productos.*.codigo' => ['required', 'string', 'max:255'],
            'productos.*.cantidad_afectada' => ['required', 'integer', 'min:1'],
            'productos.*.tipo_presentacion' => ['required', 'in:'.implode(',', array_keys(ObservationProduct::PRESENTACIONES))],
            'productos.*.lote' => ['required', 'string', 'max:255'],
            'productos.*.fecha_vencimiento' => ['required', 'date'],
            'productos.*.numero_remito' => ['nullable', 'string', 'max:255'],
            'productos.*.tipo_comprobante' => ['nullable', 'in:factura,remito'],
        ]);

        DB::transaction(function () use ($base, $sector, $data, $request) {
            $anio = (int) now()->format('Y');

            $observacion = Observacion::create([
                'numero' => Observacion::generarNumero($anio),
                'anio' => $anio,
                'origen' => 'interna',
                'estado' => 'clasificada',
                'tipo' => $base['tipo'],
                'titulo' => $base['titulo'],
                'descripcion' => $base['descripcion'],
                'sector_id' => $sector->id,
                'responsable_id' => $base['responsable_id'] ?? null,
                'created_by' => $request->user()->id,
                'prioridad' => $base['prioridad'],
                'tipo_caso' => $base['tipo_caso'],
                'contacto_numero_cliente' => $base['contacto_numero_cliente'] ?? null,
                'contacto_nombre' => $base['contacto_nombre'] ?? null,
                'contacto_email' => $base['contacto_email'] ?? null,
                'cliente_id' => $this->clienteIdDesdeNumero($base['contacto_numero_cliente'] ?? null),
                'institucion' => $data['institucion'] ?? null,
                'provincia' => $data['provincia'] ?? null,
                'equipamiento' => $data['equipamiento'] ?? null,
                'ejecutivo_cuenta' => $data['ejecutivo_cuenta'] ?? null,
            ]);

            foreach ($data['productos'] ?? [] as $producto) {
                $observacion->productos()->create($producto);
            }

            $this->guardarAdjuntos($observacion, $request);
            $this->sincronizarNotificados($observacion, $base['notificados'] ?? []);
        });

        return redirect()->route('observaciones.index')
            ->with('success', 'Observación interna creada correctamente.');
    }

    /** Vincula por N° contra la tabla local de clientes (mismo criterio que el portal). */
    private function clienteIdDesdeNumero(?string $numero): ?int
    {
        return filled($numero) ? Cliente::where('numero', trim($numero))->value('id') : null;
    }

    private function guardarAdjuntos(Observacion $observacion, Request $request): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $observacion->guardarAdjunto($file);
        }
    }

    public function update(Request $request, Observacion $observacion)
    {
        $this->authorize('update', $observacion);

        $data = $request->validate([
            'responsable_id' => ['nullable', 'exists:users,id'],
            'sector_id' => ['nullable', 'exists:sectors,id'],
            'estado' => ['required', 'in:'.implode(',', array_keys(Observacion::ESTADOS))],
            'prioridad' => ['nullable', Rule::in(array_keys(config('incidencias.prioridades')))],
            'tipo_caso' => ['nullable', Rule::in(config('incidencias.tipos_caso'))],
            'notificados' => ['array'],
            'notificados.*' => ['integer', 'exists:users,id'],
        ]);

        $notificados = $data['notificados'] ?? null;
        unset($data['notificados']);

        // Clasificar: si se completó prioridad + tipo de caso y seguía pendiente,
        // pasa automáticamente a "clasificada" (flujo de Garantía de Calidad).
        if ($data['estado'] === 'pendiente_clasificacion' && ! empty($data['prioridad']) && ! empty($data['tipo_caso'])) {
            $data['estado'] = 'clasificada';
        }

        $observacion->update($data);

        if ($notificados !== null) {
            $this->sincronizarNotificados($observacion, $notificados);
        }

        return redirect()->route('observaciones.index')
            ->with('success', 'Observación actualizada correctamente.');
    }
}
