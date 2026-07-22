<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        return inertia('Admin/Observaciones/Index', [
            'observaciones' => Observacion::query()
                ->with(['responsable:id,name', 'sector:id,nombre', 'cliente:id,numero,razon_social,mail,telefono', 'productos'])
                ->latest()
                ->paginate(20),
            // Cualquiera que vea el listado puede necesitar reasignar responsable/sector
            // en las filas que sí puede editar (ver ObservacionPolicy::update).
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
            'sectores' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'tipoLabels' => TaxonomiaIncidencias::etiquetasTipos(),
            'prioridades' => config('incidencias.prioridades'),
            'tiposCaso' => config('incidencias.tipos_caso'),
        ]);
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
            'prioridades' => config('incidencias.prioridades'),
            'tiposCaso' => config('incidencias.tipos_caso'),
            'prioridadSugerida' => config('incidencias.prioridad_sugerida'),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ]);
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
                'prioridad' => $base['prioridad'],
                'tipo_caso' => $base['tipo_caso'],
                'datos_especificos' => $especificos['datos_especificos'] ?? [],
            ]);

            $this->guardarAdjuntos($observacion, $request);
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
        $data = $request->validate([
            'institucion' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'provincia' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'equipamiento' => ['nullable', 'string', 'max:255'],
            'ejecutivo_cuenta' => ['nullable', 'string', 'max:255'],

            'productos' => ['required_if:tipo,falla_producto', 'array'],
            'productos.*.producto' => ['required', 'string', 'max:255'],
            'productos.*.codigo' => ['required', 'string', 'max:255'],
            'productos.*.cantidad_afectada' => ['required', 'integer', 'min:1'],
            'productos.*.lote' => ['required', 'string', 'max:255'],
            'productos.*.fecha_vencimiento' => ['required', 'date'],
            'productos.*.numero_remito' => ['required', 'string', 'max:255'],
            'productos.*.tipo_comprobante' => ['required', 'in:factura,remito'],
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
                'prioridad' => $base['prioridad'],
                'tipo_caso' => $base['tipo_caso'],
                'institucion' => $data['institucion'] ?? null,
                'provincia' => $data['provincia'] ?? null,
                'equipamiento' => $data['equipamiento'] ?? null,
                'ejecutivo_cuenta' => $data['ejecutivo_cuenta'] ?? null,
            ]);

            foreach ($data['productos'] ?? [] as $producto) {
                $observacion->productos()->create($producto);
            }

            $this->guardarAdjuntos($observacion, $request);
        });

        return redirect()->route('observaciones.index')
            ->with('success', 'Observación interna creada correctamente.');
    }

    private function guardarAdjuntos(Observacion $observacion, Request $request): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('observaciones', 'local');

            $observacion->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
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
        ]);

        // Clasificar: si se completó prioridad + tipo de caso y seguía pendiente,
        // pasa automáticamente a "clasificada" (flujo de Garantía de Calidad).
        if ($data['estado'] === 'pendiente_clasificacion' && ! empty($data['prioridad']) && ! empty($data['tipo_caso'])) {
            $data['estado'] = 'clasificada';
        }

        $observacion->update($data);

        return redirect()->route('observaciones.index')
            ->with('success', 'Observación actualizada correctamente.');
    }
}
