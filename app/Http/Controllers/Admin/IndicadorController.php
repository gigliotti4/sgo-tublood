<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Indicador;
use App\Models\IndicadorCarga;
use App\Models\IndicadorValor;
use App\Models\Sector;
use App\Services\IndicadorExportService;
use App\Services\IndicadorImportService;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Objetivos de gestión (el "OBJETIVOS 2026" del cliente): ABM de los indicadores
 * y subida de la planilla.
 *
 * Subir la planilla son **dos pasos**: `previsualizar()` la lee y muestra qué
 * cambia sin escribir nada, y `aplicar()` la vuelve a leer y la escribe. El
 * archivo queda guardado entre los dos pasos con un token (no en la sesión: pesa
 * y la sesión se comparte entre pestañas).
 */
class IndicadorController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('objetivos.view');

        return inertia('Admin/Objetivos/Index', [
            'indicadores' => Indicador::with(['valores' => fn ($q) => $q->orderBy('mes')])
                ->orderBy('sector')->orderBy('id')->get(),
            'cargas' => IndicadorCarga::with('usuario:id,name,apellido')->latest()->limit(15)->get(),
            // Lo que dejó el paso anterior (una sola vez: es un flash).
            'previa' => session('objetivos.previa'),
            'tipos' => $this->tiposDisponibles(),
            'sectores' => Sector::orderBy('nombre')->get(['id', 'nombre']),
            'tiposMeta' => Indicador::TIPOS_META,
            'estados' => Indicador::ESTADOS,
        ]);
    }

    public function previsualizar(Request $request, IndicadorImportService $importador): RedirectResponse
    {
        $this->authorize('objetivos.edit');

        $request->validate(['archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240']], [], ['archivo' => 'archivo']);

        $this->limpiarPendientes();

        $token = (string) Str::uuid();
        $ruta = $request->file('archivo')->storeAs('objetivos/pendientes', "{$token}.xlsx", 'local');

        try {
            $leido = $importador->leer(Storage::disk('local')->path($ruta));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($ruta);

            return back()->with('error', $e->getMessage());
        }

        $vista = $importador->previsualizar($leido['filas']);

        session()->flash('objetivos.previa', [
            'token' => $token,
            'archivo' => $request->file('archivo')->getClientOriginalName(),
            'hoja' => $leido['hoja'],
            'errores' => $leido['errores'],
            'resumen' => $vista['resumen'],
            'filas' => $vista['filas'],
            'aplicable' => $leido['errores'] === [] && $vista['resumen']['con_errores'] === 0,
        ]);

        return redirect()->route('objetivos.index');
    }

    public function aplicar(Request $request, IndicadorImportService $importador): RedirectResponse
    {
        $this->authorize('objetivos.edit');

        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'archivo' => ['required', 'string', 'max:255'],
        ]);

        $ruta = "objetivos/pendientes/{$data['token']}.xlsx";

        if (! Storage::disk('local')->exists($ruta)) {
            return redirect()->route('objetivos.index')
                ->with('error', 'La planilla ya no está disponible: volvé a subirla.');
        }

        try {
            $leido = $importador->leer(Storage::disk('local')->path($ruta));

            if ($leido['errores'] !== []) {
                throw new RuntimeException('La planilla tiene errores: '.implode(' ', $leido['errores']));
            }

            $resumen = $importador->aplicar($leido['filas'], $request->user(), $data['archivo']);
        } catch (RuntimeException $e) {
            return redirect()->route('objetivos.index')->with('error', $e->getMessage());
        }

        Storage::disk('local')->delete($ruta);

        return redirect()->route('objetivos.index')->with('success', sprintf(
            'Planilla aplicada: %d nuevos, %d actualizados, %d valores mensuales.',
            $resumen['nuevos'], $resumen['actualizados'], $resumen['valores'],
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('objetivos.edit');

        $data = $this->validar($request);

        Indicador::create([
            ...$data,
            'sector_id' => $this->sectorDe($data['sector']),
            'origen_dato' => empty($data['tipos']) ? 'manual' : 'sgo',
        ]);

        return back(fallback: route('objetivos.index'))->with('success', 'Indicador creado.');
    }

    public function update(Request $request, Indicador $indicador): RedirectResponse
    {
        $this->authorize('objetivos.edit');

        $data = $this->validar($request, $indicador);

        $indicador->update([
            ...$data,
            'sector_id' => $this->sectorDe($data['sector']),
            // Con tipos cargados se cuenta del SGO; sin ellos, a mano.
            'origen_dato' => empty($data['tipos']) ? 'manual' : 'sgo',
        ]);

        return back(fallback: route('objetivos.index'))->with('success', 'Indicador actualizado.');
    }

    /**
     * Los valores mensuales de un indicador que NO sale del SGO. Un mes vacío se borra.
     */
    public function valores(Request $request, Indicador $indicador): RedirectResponse
    {
        $this->authorize('objetivos.edit');

        abort_if($indicador->esDelSgo(), 422, 'Este indicador se cuenta solo desde las observaciones.');

        $request->validate([
            'valores' => ['required', 'array'],
            'valores.*' => ['nullable', 'numeric'],
        ]);

        foreach ($request->input('valores') as $mes => $valor) {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $mes)) {
                continue;
            }

            if ($valor === null || $valor === '') {
                IndicadorValor::where('indicador_id', $indicador->id)->where('mes', $mes)->delete();

                continue;
            }

            IndicadorValor::updateOrCreate(
                ['indicador_id' => $indicador->id, 'mes' => $mes],
                ['valor' => $valor, 'cargado_por' => $request->user()->id],
            );
        }

        return back(fallback: route('objetivos.index'))->with('success', 'Valores guardados.');
    }

    /** Los objetivos vigentes en el mismo formato de la planilla: lo que se baja se puede volver a subir. */
    public function export(IndicadorExportService $exportador): StreamedResponse
    {
        $this->authorize('objetivos.view');

        return $exportador->exportar(Indicador::with('valores')->orderBy('sector')->orderBy('id')->get());
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Indicador $indicador = null): array
    {
        $tipos = array_keys(collect($this->tiposDisponibles())->keyBy('clave')->all());

        $data = $request->validate([
            'sector' => ['required', 'string', 'max:255'],
            'fallo' => ['required', 'string', 'max:255', Rule::unique('indicadores', 'fallo')
                ->where('sector', $request->input('sector'))->ignore($indicador)],
            'tipo_meta' => ['required', Rule::in(array_keys(Indicador::TIPOS_META))],
            'objetivo' => ['required', 'numeric', 'min:0'],
            'tolerancia' => ['required', 'numeric', 'min:0'],
            'estado' => ['required', Rule::in(array_keys(Indicador::ESTADOS))],
            'importancia' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cant_pedidos' => ['nullable', 'numeric', 'min:0'],
            'tipos' => ['nullable', 'array'],
            'tipos.*' => ['string', Rule::in($tipos)],
            'criterios' => ['nullable', 'array'],
            'criterios.titulo_contiene' => ['nullable', 'string', 'max:255'],
            'criterios.titulo_no_contiene' => ['nullable', 'string', 'max:255'],
            'criterios.motivo' => ['nullable', 'array'],
            'criterios.motivo.*' => ['string', 'max:255'],
            'criterios.motivo_no' => ['nullable', 'array'],
            'criterios.motivo_no.*' => ['string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'activo' => ['boolean'],
        ]);

        // Con un máximo la tolerancia es el techo; con un mínimo, el piso.
        if ($data['tipo_meta'] === Indicador::MAXIMO && $data['tolerancia'] < $data['objetivo']) {
            $this->falla('tolerancia', 'En un máximo de fallos la tolerancia no puede ser menor que el objetivo.');
        }
        if ($data['tipo_meta'] === Indicador::MINIMO && $data['tolerancia'] > $data['objetivo']) {
            $this->falla('tolerancia', 'En un mínimo a alcanzar la tolerancia no puede ser mayor que el objetivo.');
        }

        $data['criterios'] = array_filter($data['criterios'] ?? [], fn ($v) => filled($v)) ?: null;

        return $data;
    }

    /** Las planillas que se subieron para previsualizar y nunca se confirmaron: se descartan al día. */
    private function limpiarPendientes(): void
    {
        $disco = Storage::disk('local');

        foreach ($disco->files('objetivos/pendientes') as $archivo) {
            if ($disco->lastModified($archivo) < now()->subDay()->getTimestamp()) {
                $disco->delete($archivo);
            }
        }
    }

    private function falla(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    /** El sector del SGO que corresponde al nombre de la planilla, si se lo conoce. */
    private function sectorDe(string $nombre): ?int
    {
        $normal = Str::of($nombre)->ascii()->upper()->squish()->toString();
        $slug = config('objetivos.sectores.'.$normal);

        return $slug ? Sector::where('slug', $slug)->value('id') : null;
    }

    /**
     * Los tipos de incidencia que un indicador puede contar, con su código y su
     * etiqueta. Incluye los retirados (3.5, 6.2): un indicador viejo puede
     * apuntarlos y tiene que poder verse.
     *
     * @return list<array{clave: string, codigo: string, label: string, obsoleto: bool}>
     */
    private function tiposDisponibles(): array
    {
        $lista = [];

        foreach (TaxonomiaIncidencias::taxonomia() as $tipos) {
            foreach ($tipos as $clave => $def) {
                $lista[] = [
                    'clave' => $clave,
                    'codigo' => $def['codigo'] ?? '',
                    'label' => $def['label'] ?? $clave,
                    'obsoleto' => ! empty($def['obsoleto']),
                ];
            }
        }

        return $lista;
    }
}
