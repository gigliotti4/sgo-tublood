<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\NoConformidad;
use App\Models\NonConformityAction;
use App\Models\NonConformityContainment;
use App\Models\NonConformityHistory;
use App\Models\Observacion;
use App\Models\Proveedor;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\AccionAsignadaNotification;
use App\Notifications\NoConformidadAsignadaNotification;
use App\Support\Configuracion;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Gestión de No Conformidades — `docs/IT- PARA LA GESTIÓN DE NO CONFORMIDADES
 * EN EL SISTEMA.docx`.
 *
 * ⚠️ **Reparto con el observer**: acá se valida *quién* puede hacer cada
 * transición y se escribe en la bitácora el *por qué* (los motivos de texto
 * libre). El "De X a Y" y los timestamps de aprobación y cierre los pone
 * `NoConformidadObserver`, que es el único que los ve pasar. Mismo criterio que
 * `Admin\ObservacionController`.
 */
class NoConformidadController extends Controller
{
    use OrdenaListados;

    /** @return array<string, string|Closure> */
    private function ordenables(): array
    {
        return [
            // Las que no tienen número (borrador, rechazada) quedan juntas en
            // un extremo, que es lo correcto: son las que todavía no entraron
            // al circuito formal.
            'numero' => 'non_conformities.numero',
            'estado' => 'non_conformities.estado',
            'deteccion' => 'non_conformities.fecha_deteccion',
            'creada' => 'non_conformities.created_at',
            'sector' => fn (Builder $q, string $dir) => $q->orderBy(
                Sector::query()->select('nombre')->whereColumn('sectors.id', 'non_conformities.sector_id'),
                $dir,
            ),
        ];
    }

    public function index(Request $request): Response
    {
        $this->authorize('nc.view');

        $filtros = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'string', 'max:40'],
            'sector_id' => ['nullable', 'integer'],
            // ⚠️ `sort` y `dir` tienen que estar acá o `validate()` los descarta
            // en silencio y el click en el encabezado no hace nada.
            'sort' => ['nullable', 'string', 'max:40'],
            'dir' => ['nullable', 'string', 'max:4'],
        ]);

        $query = NoConformidad::query()
            ->with([
                'sector:id,nombre',
                'cliente:id,numero,razon_social',
                'proveedor:id,numero,razon_social',
                'creador:id,name,apellido',
            ])
            ->withCount('observaciones')
            ->when($filtros['search'] ?? null, fn ($q, $t) => $q->buscar($t))
            ->when($filtros['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($filtros['sector_id'] ?? null, fn ($q, $s) => $q->where('sector_id', $s));

        $noConformidades = $this->aplicarOrden(
            $query,
            $request,
            $this->ordenables(),
            fn (Builder $q) => $q->orderByDesc('non_conformities.created_at'),
            'non_conformities.id',
        )->paginate(25)->withQueryString();

        return inertia('Admin/NoConformidades/Index', [
            'noConformidades' => $noConformidades,
            'orden' => $this->orden($request, $this->ordenables()),
            'filters' => [
                'search' => $filtros['search'] ?? '',
                'estado' => $filtros['estado'] ?? '',
                'sector_id' => $filtros['sector_id'] ?? '',
            ],
            'estados' => NoConformidad::ESTADOS,
            'sectores' => Sector::orderBy('nombre')->get(['id', 'nombre']),
            'total' => NoConformidad::count(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('nc.create');

        return inertia('Admin/NoConformidades/Create', $this->opcionesDelFormulario());
    }

    /**
     * Alta en Borrador (§3).
     *
     * ⚠️ **No se asigna número acá.** La NC recién lo recibe al aprobarse
     * (§4.4). Ver `aprobar()`.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('nc.create');

        $data = $this->validarDatos($request);

        $nc = NoConformidad::create([
            ...$data,
            'anio' => (int) now()->format('Y'),
            'estado' => 'borrador',
            'creado_por' => $request->user()->id,
        ]);

        $this->sincronizarObservaciones($nc, $request->input('observaciones', []));

        foreach ($request->file('archivos', []) as $file) {
            $nc->guardarAdjunto($file, ['user_id' => $request->user()->id]);
        }

        return redirect()->route('no-conformidades.show', $nc)
            ->with('success', 'No Conformidad creada como borrador. Completala y enviala a aprobación.');
    }

    public function show(Request $request, NoConformidad $noConformidad): Response
    {
        $this->authorize('nc.view');

        $noConformidad->load([
            'sector:id,nombre',
            'cliente:id,numero,razon_social,mail',
            'proveedor:id,numero,razon_social',
            'creador:id,name,apellido',
            'responsable:id,name,apellido',
            'aprobadaPorUsuario:id,name,apellido',
            'cerradaPorUsuario:id,name,apellido',
            'observaciones:id,numero,titulo,estado',
            'acciones.responsable:id,name,apellido',
            'contenciones.responsable:id,name,apellido',
            // Las dos puntas de la cadena de reemplazo: de cual vino esta NC
            // y cual la reemplaza. La segunda es el "Nuevo desvio N°" de la
            // seccion 7.
            'reemplazaA:id,numero',
            'reemplazadaPor:id,numero,reemplaza_a_id,estado',
            'historial' => fn ($q) => $q->latest()
                ->with(['user:id,name,apellido', 'adjuntos:id,non_conformity_history_id,original_name,size']),
            // Los sueltos, no los que cuelgan de un comentario de bitácora.
            'attachments' => fn ($q) => $q->whereNull('non_conformity_history_id')
                ->select(['id', 'non_conformity_id', 'original_name', 'size']),
        ]);

        return inertia('Admin/NoConformidades/Show', [
            'noConformidad' => $noConformidad,
            ...$this->opcionesDelFormulario(),
            // Las decide la Policy, no la pantalla: si se replicaran en el
            // front, los dos criterios se desincronizarían.
            'permisos' => [
                'editar' => $request->user()->can('update', $noConformidad),
                'enviarAAprobacion' => $request->user()->can('enviarAAprobacion', $noConformidad),
                'aprobar' => $request->user()->can('aprobar', $noConformidad),
                'gestionar' => $request->user()->can('gestionar', $noConformidad),
                // La sección 3 tiene su propia llave: se escribe desde antes de
                // la aprobación, así que no coincide con `gestionar`.
                'contencion' => $request->user()->can('cargarContencion', $noConformidad),
                'reabrir' => $request->user()->can('reabrir', $noConformidad),
                'cancelar' => $request->user()->can('cancelar', $noConformidad),
                'asignarResponsable' => $request->user()->can('asignarResponsable', $noConformidad),
            ],
            // Para que la pantalla pueda decir QUÉ falta para cerrar, en vez de
            // un botón deshabilitado sin explicación.
            'condicionesCierre' => $noConformidad->condicionesDeCierre(),
            // Lo que conviene mirar antes de cerrar pero NO traba — cerrar sin
            // evidencias o con observaciones abiertas está permitido desde el
            // 28/9/2026. Se avisa en el modal de cierre y se sigue.
            'advertenciasCierre' => $noConformidad->advertenciasDeCierre(),
        ]);
    }

    /**
     * El Informe de Desvío en PDF, con el layout del formulario en papel.
     *
     * Mismo patrón que `ObservacionController::pdf()`: DomPDF (PHP puro) y no
     * Browsershot, y la plantilla `resources/views/pdf/no-conformidad.blade.php`
     * es CSS 2.1 sin Tailwind. ⚠️ Eso significa que hay que mantenerla a mano
     * junto con `Show.vue` — son dos vistas del mismo formulario y no comparten
     * una línea de código.
     *
     * Gateado con `nc.view` y no con un permiso propio: bajar el informe es
     * leer el caso, y un permiso nuevo obligaría a reseedear en producción.
     */
    public function pdf(NoConformidad $noConformidad): HttpResponse
    {
        $this->authorize('nc.view');

        $noConformidad->load([
            'sector:id,nombre',
            'cliente:id,numero,razon_social,mail,telefono',
            'proveedor:id,numero,razon_social',
            'creador:id,name,apellido',
            'responsable:id,name,apellido',
            'aprobadaPorUsuario:id,name,apellido',
            'cerradaPorUsuario:id,name,apellido',
            'observaciones:id,numero,titulo,estado',
            'contenciones.responsable:id,name,apellido',
            'acciones.responsable:id,name,apellido',
            'reemplazaA:id,numero',
            'reemplazadaPor:id,numero,reemplaza_a_id',
            'attachments:id,non_conformity_id,original_name,size,path,mime_type,non_conformity_history_id',
            // Orden cronológico ascendente, al revés que la pantalla: un
            // expediente impreso se lee de arriba hacia abajo.
            'historial' => fn ($q) => $q->oldest()->with('user:id,name,apellido'),
        ]);

        $imagenes = $this->imagenesParaPdf($noConformidad);

        $pdf = Pdf::loadView('pdf.no-conformidad', [
            'nc' => $noConformidad,
            'factores' => NoConformidad::FACTORES_CAUSA,
            'estadosAccion' => NonConformityAction::ESTADOS,
            'resultados' => NoConformidad::RESULTADOS_EFICACIA,
            'accionLabels' => NonConformityHistory::ACCION_LABELS,
            'emitido' => now()->format('d/m/Y H:i'),
            'marca' => Configuracion::valores(),
            'logo' => $this->logoParaPdf(),
            'imagenes' => $imagenes['incrustadas'],
            'imagenesOmitidas' => $imagenes['omitidas'],
        ])->setPaper('a4');

        $nombre = $noConformidad->numero ?? 'borrador-'.$noConformidad->id;

        return $pdf->download("informe-de-desvio-{$nombre}.pdf");
    }

    /** Topes del PDF: el mismo criterio y los mismos números que Observaciones. */
    private const PDF_MAX_IMAGENES = 12;

    private const PDF_MAX_PESO_IMAGEN = 4 * 1024 * 1024;

    /**
     * Las imágenes adjuntas, incrustadas en base64.
     *
     * ⚠️ DomPDF corre con `enable_remote => false`: una `<img>` con URL sale
     * vacía sin avisar, así que todo lo que entra al PDF va incrustado. Lo que
     * no entra (SVG, que DomPDF no renderiza; lo que pasa los topes) se lista
     * por nombre en vez de romper la descarga.
     *
     * @return array{incrustadas: array<int, array{nombre: string, src: string}>, omitidas: array<int, string>}
     */
    private function imagenesParaPdf(NoConformidad $nc): array
    {
        $incrustadas = [];
        $omitidas = [];

        foreach ($nc->attachments as $adjunto) {
            if (! str_starts_with((string) $adjunto->mime_type, 'image/')) {
                continue;
            }

            if (str_contains((string) $adjunto->mime_type, 'svg')
                || count($incrustadas) >= self::PDF_MAX_IMAGENES
                || $adjunto->size > self::PDF_MAX_PESO_IMAGEN) {
                $omitidas[] = $adjunto->original_name;

                continue;
            }

            // Un adjunto cuyo archivo ya no está en disco no puede tumbar la
            // descarga: se saltea en silencio.
            if (! Storage::disk('local')->exists($adjunto->path)) {
                continue;
            }

            $incrustadas[] = [
                'nombre' => $adjunto->original_name,
                'src' => 'data:'.$adjunto->mime_type.';base64,'
                    .base64_encode(Storage::disk('local')->get($adjunto->path)),
            ];
        }

        return ['incrustadas' => $incrustadas, 'omitidas' => $omitidas];
    }

    /**
     * El logo como data URI, o null si no hay ninguno cargado.
     *
     * ⚠️ `pathImagen()` devuelve una ruta **absoluta del filesystem**, no una
     * relativa al disco: se lee con `file_get_contents`, no con `Storage::get`.
     */
    private function logoParaPdf(): ?string
    {
        $path = Configuracion::pathImagen('logo');

        if (! $path) {
            return null;
        }

        // El SVG no lo renderiza DomPDF sin extensiones: se ignora y el PDF cae
        // al encabezado de solo texto.
        $mime = @mime_content_type($path) ?: '';

        if (! str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    public function update(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('update', $noConformidad);

        $noConformidad->update($this->validarDatos($request));

        $this->sincronizarObservaciones($noConformidad, $request->input('observaciones', []));

        return back(fallback: route('no-conformidades.show', $noConformidad))
            ->with('success', 'No Conformidad actualizada.');
    }

    // ── Transiciones del flujo (§4) ─────────────────────────────────────────

    /** Borrador → Pendiente de aprobación (§3). */
    public function enviarAAprobacion(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('enviarAAprobacion', $noConformidad);
        $this->exigirTransicion($noConformidad, 'pendiente_aprobacion');

        $noConformidad->update(['estado' => 'pendiente_aprobacion']);

        return back()->with('success', 'Enviada a aprobación.');
    }

    /**
     * Pendiente de aprobación → Abierta (§4.3). **Acá se asigna el número.**
     *
     * Va envuelta en `altaConNumero()` por la misma razón que el alta de una
     * observación: entre que se lee el máximo y se escribe el UPDATE hay una
     * ventana en la que otra aprobación simultánea puede quedarse con el mismo
     * correlativo, y `numero` es único.
     */
    public function aprobar(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('aprobar', $noConformidad);
        $this->exigirTransicion($noConformidad, 'abierta');

        NoConformidad::altaConNumero($noConformidad->anio, function (string $numero) use ($noConformidad) {
            $noConformidad->update(['estado' => 'abierta', 'numero' => $numero]);
        });

        $noConformidad->refresh();

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_APROBACION,
            'cambios' => ['numero' => $noConformidad->numero],
        ]);

        return back()->with('success', "Aprobada. Quedó registrada como {$noConformidad->numero}.");
    }

    /** Pendiente de aprobación → Borrador, con motivo obligatorio (§4.3). */
    public function devolver(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('aprobar', $noConformidad);
        $this->exigirTransicion($noConformidad, 'borrador');

        $motivo = $this->validarMotivo($request);

        $noConformidad->update(['estado' => 'borrador']);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_DEVOLUCION,
            'nota' => $motivo,
        ]);

        return back()->with('success', 'Devuelta a borrador para corregir.');
    }

    /**
     * Pendiente de aprobación → Rechazada, con motivo obligatorio (§4.3).
     *
     * ⚠️ Una NC rechazada **no se borra**: queda registrada como antecedente.
     */
    public function rechazar(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('aprobar', $noConformidad);
        $this->exigirTransicion($noConformidad, 'rechazada');

        $motivo = $this->validarMotivo($request);

        $noConformidad->update(['estado' => 'rechazada']);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_RECHAZO,
            'nota' => $motivo,
        ]);

        return back()->with('success', 'Rechazada. Queda registrada como antecedente.');
    }

    /**
     * Designa (o cambia) el responsable del caso — "pasar la posta".
     *
     * ⚠️ Lo puede hacer **el responsable actual**, sin pasar por Calidad: es el
     * pedido del cliente del 25/9/2026. Sin eso, cada licencia o cambio de mano
     * obligaría a molestar a Calidad, que es el cuello de botella que este
     * cambio viene a sacar. La regla completa está en
     * `NoConformidadPolicy::asignarResponsable()`.
     *
     * Se permite **dejarlo vacío**: un caso sin responsable vuelve a ser de
     * Calidad, que es el estado en el que nace.
     */
    public function asignarResponsable(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('asignarResponsable', $noConformidad);

        $data = $request->validate([
            'responsable_id' => ['nullable', 'exists:users,id'],
        ], [], ['responsable_id' => 'responsable']);

        $anterior = $noConformidad->responsable_id;
        $nuevo = $data['responsable_id'] ?? null;

        if ($anterior === $nuevo) {
            return back();
        }

        $noConformidad->update(['responsable_id' => $nuevo]);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_RESPONSABLE,
            'cambios' => [
                'de' => $anterior ? $this->nombreDe($anterior) : null,
                'a' => $nuevo ? $this->nombreDe($nuevo) : null,
            ],
        ]);

        if ($nuevo && $nuevo !== $request->user()->id) {
            try {
                User::find($nuevo)?->notify(new NoConformidadAsignadaNotification($noConformidad));
            } catch (\Throwable $e) {
                Log::error('No se pudo avisar de la NC asignada: '.$e->getMessage());
            }
        }

        return back()->with('success', $nuevo
            ? 'Responsable asignado.'
            : 'La No Conformidad quedó sin responsable: la gestiona Garantía de Calidad.');
    }

    /** El nombre para la bitácora. Se guarda el texto, no el id: es histórico. */
    private function nombreDe(int $userId): string
    {
        $user = User::find($userId);

        return $user ? trim($user->name.' '.$user->apellido) : "Usuario #{$userId}";
    }

    // ── Vínculo con observaciones (§5) ──────────────────────────────────────

    /**
     * Agrega y quita observaciones vinculadas.
     *
     * ⚠️ **Va con `gestionar` y no con `update`.** `update` es más angosto
     * —creador en borrador, o `nc.gestionar`— y dejaría afuera al responsable
     * del caso, que desde el 25/9/2026 lo gestiona entero y es justamente quien
     * necesita vincular. Es la misma razón por la que `asignarResponsable` está
     * fuera del grupo de rutas de Calidad.
     *
     * Endpoint propio y no parte de `update()`: vincular observaciones se hace
     * desde la ficha, en cualquier momento del circuito, y no al editar la
     * cabecera.
     */
    public function vincularObservaciones(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);

        $data = $request->validate([
            'observaciones' => ['present', 'array'],
            'observaciones.*' => ['integer', 'exists:observations,id'],
        ], [], ['observaciones' => 'observaciones']);

        $this->sincronizarObservaciones($noConformidad, $data['observaciones']);

        return back()->with('success', 'Observaciones vinculadas actualizadas.');
    }

    /**
     * Crea un desvío a partir de una observación y las deja vinculadas.
     *
     * Es la puerta de entrada que faltaba: el vínculo existía en la base desde
     * el principio, pero no había ninguna pantalla capaz de crearlo.
     *
     * La NC **nace en borrador y pasa por aprobación como cualquier otra** —
     * mismo criterio que `derivar()`. Se le precarga el encuadre (tipo, sector,
     * cliente) porque es dato que la observación ya tiene y volver a tipearlo
     * es la clase de fricción que hace que la gente no use la función.
     *
     * ⚠️ La observación queda en `derivada_nc` pero **se cierra a mano, por
     * separado** (decisión del cliente del 28/9/2026). Por eso el desvío no
     * exige que esté cerrada para poder cerrarse: si lo exigiera, los dos se
     * esperarían para siempre.
     */
    public function crearDesdeObservacion(Request $request, Observacion $observacion): RedirectResponse
    {
        $this->authorize('nc.create');
        $this->authorize('update', $observacion);

        $nc = NoConformidad::create([
            'anio' => now()->year,
            'estado' => 'borrador',
            // Un reclamo de un cliente es un desvío externo; uno cargado
            // internamente, interno. Es el encuadre más probable y se puede
            // corregir antes de mandarla a aprobación.
            'tipo_desvio' => $observacion->origen === 'externa' ? 'externo' : 'interno',
            'fecha_deteccion' => $observacion->created_at?->toDateString() ?? now()->toDateString(),
            'motivo' => "Derivada de la observación {$observacion->numero}",
            'sector_id' => $observacion->sector_id,
            'cliente_id' => $observacion->cliente_id,
            'descripcion' => $observacion->descripcion,
            'creado_por' => $request->user()->id,
        ]);

        $this->sincronizarObservaciones($nc, [$observacion->id]);

        // El cambio de estado lo registra solo `ObservacionObserver`, que ya
        // escribe el "De X a Y" en la bitácora de la observación. Escribirlo
        // acá también dejaría la entrada duplicada.
        $observacion->update(['estado' => 'derivada_nc']);

        return redirect()
            ->route('no-conformidades.show', $nc)
            ->with('success', "Se abrió un desvío a partir de la observación {$observacion->numero}. Completá el encuadre y mandalo a aprobación.");
    }

    // ── Investigación y plan de acción (§4.4 y §4.5) ────────────────────────

    /**
     * Guarda la investigación. Se puede guardar incompleta: la etapa lleva días
     * y nadie carga todo de una sentada. Lo que exige completitud es avanzar.
     */
    public function guardarInvestigacion(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);

        $data = $request->validate([
            'investigacion' => ['nullable', 'string', 'max:10000'],
            'alcance' => ['nullable', 'string', 'max:5000'],
            'afectados' => ['nullable', 'string', 'max:5000'],
            'evaluacion_riesgo' => ['nullable', 'string', 'max:5000'],
            'es_grave' => ['boolean'],
            'es_repetitivo' => ['boolean'],
            'requiere_capa' => ['boolean'],
            'causa_raiz' => ['nullable', 'string', 'max:10000'],
            'causa_raiz_factores' => ['nullable', 'array'],
            // Solo los seis factores del catálogo; el resto se descarta.
            'causa_raiz_factores.*' => ['nullable', 'string', 'max:2000'],
            'conclusion' => ['nullable', 'string', 'max:5000'],
        ]);

        // ⚠️ Solo se toca si vino en el request. Las secciones 2 y 4 del
        // formulario son dos bloques distintos que guardan contra este mismo
        // endpoint: si se normalizara siempre, guardar la investigación
        // borraría las 6M que cargó el otro bloque, en silencio.
        if ($request->has('causa_raiz_factores')) {
            $data['causa_raiz_factores'] = $this->soloFactoresConocidos($data['causa_raiz_factores'] ?? []);
        }

        $noConformidad->update($data);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_INVESTIGACION,
            'cambios' => ['completa' => $noConformidad->investigacionCompleta()],
        ]);

        return back()->with('success', 'Investigación guardada.');
    }

    /**
     * Agrega una acción de contención (sección 3).
     *
     * ⚠️ **Fila por fila, no la lista entera.** Hasta el 25/9/2026 la sección se
     * guardaba de una sola vez borrando y rehaciendo todo. Dejó de servir cuando
     * el responsable de UNA fila pasó a poder editarla: al mandar el array
     * completo estaría reescribiendo las filas de los demás. Ahora sigue el
     * mismo molde que el plan de acción.
     */
    public function guardarContencion(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        // ⚠️ `cargarContencion` y no `gestionar`: la sección 3 se escribe desde
        // antes de la aprobación, cuando quien cargó el desvío todavía no es
        // responsable ni tiene `nc.gestionar`. Ver la Policy.
        $this->authorize('cargarContencion', $noConformidad);

        $contencion = $noConformidad->contenciones()->create($this->validarContencion($request));

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_CONTENCION,
            'cambios' => ['agregada' => true],
            'nota' => $contencion->accion,
        ]);

        $this->avisarAlResponsableDeRenglon($contencion->responsable_id, $noConformidad, $contencion->accion);

        return back()->with('success', 'Acción de contención agregada.');
    }

    /**
     * Edita una fila de contención.
     *
     * Autoriza contra **la fila** y no contra la NC: quien la tiene asignada la
     * gestiona sin depender de Calidad (ver `NonConformityContainmentPolicy`).
     */
    public function actualizarContencion(
        Request $request,
        NoConformidad $noConformidad,
        NonConformityContainment $contencion,
    ): RedirectResponse {
        $this->exigirQueSeaDeLaNc($noConformidad, $contencion);
        $this->authorize('gestionar', $contencion);

        $responsableAnterior = $contencion->responsable_id;

        $contencion->update($this->validarContencion($request));

        if ($contencion->responsable_id !== $responsableAnterior) {
            $this->avisarAlResponsableDeRenglon($contencion->responsable_id, $noConformidad, $contencion->accion);
        }

        return back()->with('success', 'Acción de contención actualizada.');
    }

    /** Quitar una fila es de quien arma la contención, no de quien la ejecuta. */
    public function eliminarContencion(
        Request $request,
        NoConformidad $noConformidad,
        NonConformityContainment $contencion,
    ): RedirectResponse {
        $this->exigirQueSeaDeLaNc($noConformidad, $contencion);
        $this->authorize('eliminar', $contencion);

        $descripcion = $contencion->accion;
        $contencion->delete();

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_CONTENCION,
            'cambios' => ['quitada' => true],
            'nota' => $descripcion,
        ]);

        return back()->with('success', 'Acción de contención quitada.');
    }

    /** @return array<string, mixed> */
    private function validarContencion(Request $request): array
    {
        return $request->validate([
            'fecha' => ['nullable', 'date'],
            'accion' => ['required', 'string', 'min:3', 'max:5000'],
            'responsable_id' => ['nullable', 'exists:users,id'],
        ], [], ['responsable_id' => 'responsable']);
    }

    /**
     * Abierta → Plan de acción.
     *
     * ⚠️ §4.4: no se avanza con la investigación o el análisis de causa
     * incompletos. La regla vive en `investigacionCompleta()`.
     */
    public function avanzarAPlanAccion(NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);
        $this->exigirTransicion($noConformidad, 'plan_accion');

        if (! $noConformidad->investigacionCompleta()) {
            throw ValidationException::withMessages([
                'investigacion' => 'Para avanzar al plan de acción hay que completar la investigación y el análisis de causa raíz.',
            ]);
        }

        $noConformidad->update(['estado' => 'plan_accion']);

        return back()->with('success', 'Pasó a Plan de acción.');
    }

    /**
     * Cuándo se va a comprobar si el plan sirvió (sección 5).
     *
     * ⚠️ Se carga **acá y no en la sección 6**, que es donde vive el resto de la
     * verificación: esa sección recién se habilita al llegar a la etapa de
     * verificación de eficacia, y para entonces ya es tarde para avisar. El
     * cliente lo pidió así el 28/9/2026 —"el usuario tiene que cargar la fecha
     * de verificación de eficacia"— justamente para poder ajustarla según el
     * tipo de desvío en vez de tener un plazo fijo para todos.
     *
     * ⚠️ No confundir con `fecha_seguimiento`, que es cuándo se verificó.
     *
     * Cambiar la fecha borra `verificacion_avisada_at`: si se corre para
     * adelante, el aviso tiene que volver a salir en la fecha nueva.
     */
    public function guardarFechaVerificacion(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);

        $data = $request->validate([
            'fecha_verificacion_prevista' => ['nullable', 'date'],
        ], [], ['fecha_verificacion_prevista' => 'fecha de verificación']);

        $noConformidad->update([
            'fecha_verificacion_prevista' => $data['fecha_verificacion_prevista'] ?? null,
            'verificacion_avisada_at' => null,
        ]);

        return back()->with('success', 'Fecha de verificación de eficacia guardada.');
    }

    public function guardarAccion(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);

        $data = $this->validarAccion($request);

        $accion = $noConformidad->acciones()->create($data);

        $this->avisarAlResponsableDeRenglon($accion->responsable_id, $noConformidad, $accion->descripcion);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_PLAN,
            // ⚠️ `agregada` era la etiqueta del tipo de acción, que ya no
            // existe. Se deja como bandera para que el front distinga "se
            // agregó" de "se quitó" — y `lib/nc.ts` sigue mostrando las
            // entradas viejas, que traen la etiqueta vieja acá.
            'cambios' => ['agregada' => true],
            'nota' => $data['descripcion'],
        ]);

        return back()->with('success', 'Acción agregada al plan.');
    }

    public function actualizarAccion(Request $request, NoConformidad $noConformidad, NonConformityAction $accion): RedirectResponse
    {
        $this->exigirQueSeaDeLaNc($noConformidad, $accion);
        // ⚠️ Autoriza contra el RENGLÓN, no contra la NC: quien tiene la acción
        // asignada la gestiona sin depender de Calidad.
        $this->authorize('gestionar', $accion);

        $responsableAnterior = $accion->responsable_id;

        $accion->update($this->validarAccion($request));

        if ($accion->responsable_id !== $responsableAnterior) {
            $this->avisarAlResponsableDeRenglon($accion->responsable_id, $noConformidad, $accion->descripcion);
        }

        return back()->with('success', 'Acción actualizada.');
    }

    public function eliminarAccion(Request $request, NoConformidad $noConformidad, NonConformityAction $accion): RedirectResponse
    {
        $this->exigirQueSeaDeLaNc($noConformidad, $accion);
        // Habilidad propia: borrar NO es del responsable del renglón, que para
        // eso tiene cancelar-con-justificación. Ver NonConformityActionPolicy.
        $this->authorize('eliminar', $accion);

        // Se borra de verdad y no se cancela: mientras se arma el plan, una
        // acción cargada por error es un error de tipeo, no una decisión de
        // gestión. Cancelar con justificación es de la etapa de implementación
        // (§4.6), cuando alguien ya se comprometió con ella.
        $descripcion = $accion->descripcion;
        $accion->delete();

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_PLAN,
            'cambios' => ['quitada' => true],
            'nota' => $descripcion,
        ]);

        return back()->with('success', 'Acción quitada del plan.');
    }

    /**
     * Plan de acción → En implementación.
     *
     * §4.5: Calidad revisa que las acciones sean adecuadas antes de arrancar.
     * Un plan vacío no es un plan.
     */
    public function avanzarAImplementacion(NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);
        $this->exigirTransicion($noConformidad, 'en_implementacion');

        if (! $noConformidad->tienePlanDeAccion()) {
            throw ValidationException::withMessages([
                'acciones' => 'Cargá al menos una acción antes de pasar a implementación.',
            ]);
        }

        $noConformidad->update(['estado' => 'en_implementacion']);

        return back()->with('success', 'Pasó a En implementación.');
    }

    // ── Implementación (§4.6) ───────────────────────────────────────────────

    /**
     * Avance de una acción: su estado, lo hecho hasta ahora y las evidencias.
     *
     * ⚠️ `vencida` **no** se elige a mano: es derivado de que pase la fecha
     * prevista sin completarse, y lo va a marcar la tarea agendada. Ofrecerlo
     * como opción invitaría a usarlo como "atrasada pero la sigo", que es
     * exactamente lo que el estado tiene que delatar solo.
     */
    public function actualizarAvance(Request $request, NoConformidad $noConformidad, NonConformityAction $accion): RedirectResponse
    {
        $this->exigirQueSeaDeLaNc($noConformidad, $accion);
        // ⚠️ Autoriza contra el RENGLÓN, no contra la NC: quien tiene la acción
        // asignada la gestiona sin depender de Calidad.
        $this->authorize('gestionar', $accion);

        $data = $request->validate([
            'estado' => ['required', Rule::in(['pendiente', 'en_curso', 'completada', 'cancelada'])],
            'avance' => ['nullable', 'string', 'max:5000'],
            'fecha_real' => ['nullable', 'date'],
            // §4.6: cancelar una acción exige justificación.
            'motivo_cancelacion' => ['required_if:estado,cancelada', 'nullable', 'string', 'min:5', 'max:1000'],
            'archivos' => ['nullable', 'array', 'max:10'],
            'archivos.*' => ['file', 'max:10240'],
        ], [], ['motivo_cancelacion' => 'motivo de cancelación']);

        // Completar sin fecha real sella hoy: es el dato que después alimenta
        // el KPI de cumplimiento en fecha, y dejarlo en null lo rompería.
        if ($data['estado'] === 'completada' && blank($data['fecha_real'] ?? null)) {
            $data['fecha_real'] = now()->toDateString();
        }

        $accion->update($data);

        $entrada = $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_AVANCE,
            'nota' => $data['motivo_cancelacion'] ?? ($data['avance'] ?? null),
            'cambios' => [
                'accion' => $accion->descripcion,
                'estado' => NonConformityAction::ESTADOS[$accion->estado],
            ],
        ]);

        foreach ($request->file('archivos', []) as $file) {
            $noConformidad->guardarAdjunto($file, [
                'non_conformity_history_id' => $entrada->id,
                'user_id' => $request->user()->id,
            ], subcarpeta: 'acciones');
        }

        return back()->with('success', 'Avance registrado.');
    }

    /**
     * En implementación → Verificación de eficacia.
     *
     * §4.6: no se avanza mientras queden acciones pendientes. Una cancelada con
     * justificación no cuenta como pendiente.
     */
    public function avanzarAVerificacion(NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);
        $this->exigirTransicion($noConformidad, 'verificacion_eficacia');

        if (! $noConformidad->accionesResueltas()) {
            throw ValidationException::withMessages([
                'acciones' => 'Quedan acciones sin completar ni cancelar. Resolvelas antes de verificar la eficacia.',
            ]);
        }

        $noConformidad->update(['estado' => 'verificacion_eficacia']);

        return back()->with('success', 'Pasó a Verificación de eficacia.');
    }

    // ── Verificación de eficacia (§4.7) ─────────────────────────────────────

    /**
     * Guarda la verificación de eficacia (sección 7 del formulario).
     *
     * ⚠️ **Un resultado ineficaz no mueve el estado.** Hasta el 24/9/2026
     * devolvía la NC a `abierta`, siguiendo §4.7 del instructivo ("deberá
     * regresar"). El formulario en papel dice otra cosa —tiene una celda "Nuevo
     * desvío N°" al lado del resultado— y el cliente confirmó ésa: la NC
     * ineficaz se cierra y se abre otra que la reemplaza. Abrirla es un paso
     * aparte y explícito (`derivar()`), porque implica crear un caso nuevo y eso
     * no puede pasar de callado al guardar un formulario.
     */
    public function guardarVerificacion(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);

        if ($noConformidad->estado !== 'verificacion_eficacia') {
            throw ValidationException::withMessages([
                'estado' => 'La eficacia se verifica recién cuando la NC llega a esa etapa.',
            ]);
        }

        $data = $request->validate([
            'metodo_seguimiento' => ['nullable', 'string', 'max:5000'],
            'fecha_seguimiento' => ['nullable', 'date'],
            'evidencia_revisada' => ['nullable', 'string', 'max:5000'],
            'resultado_eficacia' => ['required', Rule::in(array_keys(NoConformidad::RESULTADOS_EFICACIA))],
            'observaciones_verificacion' => ['nullable', 'string', 'max:5000'],
        ], [], ['resultado_eficacia' => 'resultado']);

        $noConformidad->update($data);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_VERIFICACION,
            'nota' => $data['observaciones_verificacion'] ?? null,
            'cambios' => ['resultado' => NoConformidad::RESULTADOS_EFICACIA[$data['resultado_eficacia']]],
        ]);

        return back()->with('success', $data['resultado_eficacia'] === 'ineficaz'
            ? 'Verificación ineficaz: hay que abrir un desvío nuevo antes de poder cerrar ésta.'
            : 'Verificación registrada.');
    }

    /**
     * Abre el desvío que reemplaza a éste, cuando la verificación dio ineficaz
     * — la celda "Nuevo desvío N°" de la sección 7.
     *
     * La NC nueva **nace en borrador y pasa por aprobación como cualquier
     * otra**: no hereda la aprobación de la anterior. Es un desvío distinto, con
     * su propia investigación por delante, y quien aprueba tiene derecho a
     * verlo antes de que entre al circuito.
     *
     * Se precarga lo que seguro se repite (tipo, sector, cliente/proveedor) y se
     * deja la descripción apuntando al caso anterior, pero **no se copia la
     * investigación ni el plan**: si esas acciones hubieran servido, no
     * estaríamos abriendo un desvío nuevo.
     */
    public function derivar(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);

        if ($noConformidad->resultado_eficacia !== 'ineficaz') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se abre un desvío nuevo cuando la verificación de eficacia dio ineficaz.',
            ]);
        }

        if ($noConformidad->reemplazadaPor()->exists()) {
            throw ValidationException::withMessages([
                'estado' => 'Esta No Conformidad ya tiene un desvío que la reemplaza.',
            ]);
        }

        $anterior = $noConformidad->numero ?? 'la anterior';

        $nueva = NoConformidad::create([
            'anio' => now()->year,
            'estado' => 'borrador',
            'reemplaza_a_id' => $noConformidad->id,
            'tipo_desvio' => $noConformidad->tipo_desvio,
            'fecha_deteccion' => now()->toDateString(),
            'motivo' => "Falta de eficacia de las acciones de {$anterior}",
            'sector_id' => $noConformidad->sector_id,
            'cliente_id' => $noConformidad->cliente_id,
            'proveedor_id' => $noConformidad->proveedor_id,
            'descripcion' => "El problema descripto en {$anterior} volvió a presentarse: "
                ."las acciones tomadas no resultaron eficaces.\n\n{$noConformidad->descripcion}",
            'creado_por' => $request->user()->id,
        ]);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_VERIFICACION,
            'nota' => 'Se abrió un desvío nuevo porque las acciones no fueron eficaces.',
            'cambios' => ['derivada_a' => $nueva->id],
        ]);

        return redirect()->route('no-conformidades.show', $nueva)
            ->with('success', "Se abrió el desvío nuevo a partir de {$anterior}. Completalo y mandalo a aprobación.");
    }

    // ── Cierre, reapertura y cancelación (§4.8 y §6) ────────────────────────

    public function cerrar(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('gestionar', $noConformidad);
        $this->exigirTransicion($noConformidad, 'cerrada');

        $data = $request->validate([
            'resultado_final' => ['required', 'string', 'min:5', 'max:5000'],
            'observaciones_finales' => ['nullable', 'string', 'max:5000'],
        ], [], ['resultado_final' => 'resultado final']);

        // Se informa CUÁL falta, no un "no se puede cerrar" mudo.
        if (! $noConformidad->puedeCerrarse()) {
            throw ValidationException::withMessages([
                'cierre' => 'Falta cumplir: '.implode(', ', $this->condicionesPendientes($noConformidad)).'.',
            ]);
        }

        $noConformidad->update([...$data, 'estado' => 'cerrada']);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_CIERRE,
            'nota' => $data['resultado_final'],
        ]);

        return back()->with('success', 'No Conformidad cerrada.');
    }

    /**
     * Reapertura de una NC cerrada (§4.8): **solo super-admin**, con motivo
     * obligatorio.
     *
     * No pasa por `exigirTransicion()` a propósito: `TRANSICIONES['cerrada']`
     * está vacío justamente para que reabrir no parezca parte del flujo normal.
     * Vuelve a `abierta` porque es donde se puede volver a investigar.
     */
    public function reabrir(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('reabrir', $noConformidad);

        if ($noConformidad->estado !== 'cerrada') {
            throw ValidationException::withMessages(['estado' => 'Solo se reabre una No Conformidad cerrada.']);
        }

        $motivo = $this->validarMotivo($request);

        // El observer limpia `cerrada_at`/`cerrada_por` al salir del estado
        // final: si no, un segundo cierre conservaría la fecha del primero.
        $noConformidad->update(['estado' => 'abierta']);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_REAPERTURA,
            'nota' => $motivo,
        ]);

        return back()->with('success', 'No Conformidad reabierta.');
    }

    /** Cancelación (§6): solo super-admin, con motivo obligatorio. */
    public function cancelar(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('cancelar', $noConformidad);

        if ($noConformidad->estaFinalizada()) {
            throw ValidationException::withMessages([
                'estado' => 'Esta No Conformidad ya está en un estado final.',
            ]);
        }

        $motivo = $this->validarMotivo($request);

        $noConformidad->update(['estado' => 'cancelada']);

        $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_CANCELACION,
            'nota' => $motivo,
        ]);

        return back()->with('success', 'No Conformidad cancelada.');
    }

    // ── Bitácora ────────────────────────────────────────────────────────────

    public function comentar(Request $request, NoConformidad $noConformidad): RedirectResponse
    {
        $this->authorize('nc.view');

        $data = $request->validate([
            'nota' => ['nullable', 'string', 'max:5000'],
            'archivos' => ['nullable', 'array', 'max:10'],
            'archivos.*' => ['file', 'max:10240'],
        ]);

        if (blank($data['nota'] ?? null) && empty($request->file('archivos', []))) {
            throw ValidationException::withMessages(['nota' => 'Escribí algo o adjuntá un archivo.']);
        }

        $entrada = $noConformidad->historial()->create([
            'user_id' => $request->user()->id,
            'accion' => NonConformityHistory::ACCION_COMENTARIO,
            'nota' => $data['nota'] ?? null,
        ]);

        foreach ($request->file('archivos', []) as $file) {
            $noConformidad->guardarAdjunto($file, [
                'non_conformity_history_id' => $entrada->id,
                'user_id' => $request->user()->id,
            ], subcarpeta: 'bitacora');
        }

        return back()->with('success', 'Comentario agregado a la bitácora.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Corta si la transición no está en el flujograma (§4.1).
     *
     * Es una `ValidationException` y no un 403: no es un problema de permisos
     * sino de estado — la misma persona sí puede hacerla más adelante.
     */
    private function exigirTransicion(NoConformidad $nc, string $destino): void
    {
        if (! $nc->puedeTransicionarA($destino)) {
            throw ValidationException::withMessages([
                'estado' => "No se puede pasar de \"{$nc->etiquetaEstado()}\" a \"".
                    (NoConformidad::ESTADOS[$destino] ?? $destino).'".',
            ]);
        }
    }

    /**
     * Las condiciones de cierre que todavía no se cumplen, en castellano.
     *
     * @return list<string>
     */
    private function condicionesPendientes(NoConformidad $nc): array
    {
        $etiquetas = [
            'investigacion' => 'completar la investigación',
            'causa' => 'analizar la causa raíz',
            'acciones' => 'completar o cancelar todas las acciones del plan',
            'verificada' => 'verificar la eficacia',
            'eficaz' => 'que la verificación dé eficaz, o abrir el desvío que reemplace a ésta',
        ];

        return array_values(array_intersect_key(
            $etiquetas,
            array_filter($nc->condicionesDeCierre(), fn (bool $ok) => ! $ok),
        ));
    }

    /**
     * La acción tiene que ser de ESTA NC.
     *
     * Reemplaza a `->scopeBindings()` en la ruta, que acá no sirve: resuelve el
     * hijo buscando la relación por el plural **inglés** del parámetro
     * (`accion` → `accions()`), y la relación se llama `acciones()`. Sin esto,
     * `/no-conformidades/1/acciones/99` borraría la acción 99 aunque sea de
     * otra NC.
     */
    private function exigirQueSeaDeLaNc(NoConformidad $nc, Model $renglon): void
    {
        abort_unless($renglon->non_conformity_id === $nc->id, 404);
    }

    /**
     * Le avisa a quien acaba de quedar a cargo de un renglón.
     *
     * ⚠️ **No le avisa a quien hizo la asignación** (`auth()->id()`): avisarle a
     * alguien de su propio clic es ruido. Mismo criterio que
     * `ObservacionObserver::avisarSiPasoACritica()`.
     *
     * Va en un `try/catch` que loguea porque el trabajo real —la fila— ya se
     * guardó: un fallo al notificar no puede devolverle un error al usuario y
     * hacerle creer que no se guardó.
     */
    private function avisarAlResponsableDeRenglon(?int $responsableId, NoConformidad $nc, string $descripcion): void
    {
        if (! $responsableId || $responsableId === auth()->id()) {
            return;
        }

        try {
            User::find($responsableId)?->notify(new AccionAsignadaNotification($nc, $descripcion));
        } catch (\Throwable $e) {
            Log::error('No se pudo avisar del renglón asignado: '.$e->getMessage());
        }
    }

    /**
     * Descarta cualquier clave que no sea uno de los seis factores del catálogo
     * y las que vinieron vacías: el JSON guarda solo lo que aportó algo.
     *
     * @param  array<string, mixed>  $factores
     * @return array<string, string>|null
     */
    private function soloFactoresConocidos(array $factores): ?array
    {
        $limpios = [];

        foreach (NoConformidad::FACTORES_CAUSA as $clave => $_) {
            if (filled($factores[$clave] ?? null)) {
                $limpios[$clave] = trim((string) $factores[$clave]);
            }
        }

        // `null` y no `[]`: "no se analizó por factores" es distinto de "se
        // analizó y ninguno aplicó", y en la pantalla se leen distinto.
        return $limpios === [] ? null : $limpios;
    }

    /** @return array<string, mixed> */
    private function validarAccion(Request $request): array
    {
        return $request->validate([
            'descripcion' => ['required', 'string', 'min:5', 'max:2000'],
            'responsable_id' => ['required', 'exists:users,id'],
            'fecha_prevista' => ['required', 'date'],
            'evidencia_requerida' => ['nullable', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'responsable_id' => 'responsable',
            'fecha_prevista' => 'fecha prevista',
        ]);
    }

    /** @return array<string, mixed> */
    private function validarDatos(Request $request): array
    {
        return $request->validate([
            'tipo_desvio' => ['required', Rule::in(array_keys(NoConformidad::TIPOS_DESVIO))],
            'fecha_deteccion' => ['required', 'date', 'before_or_equal:today'],
            // Texto libre: el formulario lo escribe a mano y ninguna lista
            // cerrada describe un caso concreto. Ver la migracion
            // `motivo_libre_en_non_conformities`.
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
            'sector_id' => ['required', 'exists:sectors,id'],
            // "Cliente o proveedor, cuando corresponda" (§3): los dos opcionales.
            'cliente_id' => ['nullable', 'exists:clientes,id'],
            'proveedor_id' => ['nullable', 'exists:proveedores,id'],
            'descripcion' => ['required', 'string', 'min:10', 'max:5000'],
            // ⚠️ Van validadas acá y no leídas crudas del request: `store()` y
            // `update()` las mandan directo a `sync()`, así que sin esto un id
            // inventado entraba sin pasar por ninguna regla. Es la misma regla
            // que ya aplica `vincularObservaciones()`, para que los tres
            // caminos que escriben el vínculo coincidan.
            'observaciones' => ['sometimes', 'array'],
            'observaciones.*' => ['integer', 'exists:observations,id'],
        ], [], [
            'tipo_desvio' => 'tipo de desvío',
            'fecha_deteccion' => 'fecha de detección',
            'sector_id' => 'sector involucrado',
        ]);
    }

    private function validarMotivo(Request $request): string
    {
        return $request->validate([
            'motivo_decision' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['motivo_decision' => 'motivo'])['motivo_decision'];
    }

    /**
     * Vincula observaciones a la NC y lo deja en la bitácora (§5).
     *
     * ⚠️ Va en el controller y no en el observer porque `sync()` sobre una
     * relación **no dispara el evento `updated`** del modelo: el observer no se
     * entera. Mismo caso y mismo remedio que
     * `ObservacionController::sincronizarNotificados()`.
     *
     * @param  array<int, int|string>  $ids
     */
    private function sincronizarObservaciones(NoConformidad $nc, array $ids): void
    {
        $ids = array_map('intval', $ids);
        $previas = $nc->observaciones()->pluck('observations.id')->all();

        $nc->observaciones()->sync($ids);

        $sumadas = array_values(array_diff($ids, $previas));
        $sacadas = array_values(array_diff($previas, $ids));

        if ($sumadas === [] && $sacadas === []) {
            return;
        }

        // Se guardan los NÚMEROS, no los ids: la bitácora es un registro
        // histórico y tiene que poder leerse sin resolver una FK.
        $numeros = fn (array $ids) => $ids === []
            ? []
            : Observacion::whereKey($ids)->orderBy('numero')->pluck('numero')->all();

        $nc->historial()->create([
            'user_id' => auth()->id(),
            'accion' => NonConformityHistory::ACCION_OBSERVACIONES,
            'cambios' => ['sumadas' => $numeros($sumadas), 'sacadas' => $numeros($sacadas)],
        ]);
    }

    /** @return array<string, mixed> */
    private function opcionesDelFormulario(): array
    {
        return [
            'tiposDesvio' => NoConformidad::TIPOS_DESVIO,
            'estados' => NoConformidad::ESTADOS,
            'sectores' => Sector::orderBy('nombre')->get(['id', 'nombre']),
            'factoresCausa' => NoConformidad::FACTORES_CAUSA,
            'estadosAccion' => NonConformityAction::ESTADOS,
            'resultadosEficacia' => NoConformidad::RESULTADOS_EFICACIA,
            'usuarios' => User::orderBy('name')->get(['id', 'name', 'apellido']),
            'clientes' => Cliente::orderBy('razon_social')->limit(500)->get(['id', 'numero', 'razon_social']),
            'proveedores' => Proveedor::orderBy('razon_social')->limit(500)->get(['id', 'numero', 'razon_social']),
        ];
    }
}
