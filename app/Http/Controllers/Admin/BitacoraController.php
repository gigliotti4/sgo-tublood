<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Controller;
use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vista transversal de la bitácora: a diferencia de BitacoraObservacion.vue
 * (el historial de un caso puntual, embebido en Show/Index de Observaciones),
 * acá se consulta la tabla `observation_history` completa, filtrable por
 * usuario, sector, tipo de acción y rango de fechas.
 *
 * Gateada con su propio permiso (`bitacora.view`) y no con `observaciones.view`:
 * ver quién hizo qué en todo el sistema es una capacidad distinta —y más
 * sensible— que ver el listado de casos.
 */
class BitacoraController extends Controller
{
    use OrdenaListados;

    /**
     * Qué columnas se pueden ordenar. "Detalle" queda afuera: el JSON `cambios`
     * cambia de forma según la acción (ver `resources/js/lib/bitacora.ts`), así
     * que no hay un valor único contra el cual ordenar.
     *
     * @return array<string, string|Closure>
     */
    private function ordenables(): array
    {
        return [
            'fecha' => 'observation_history.created_at',
            'accion' => 'observation_history.accion',
            'usuario' => fn (Builder $q, string $dir) => $q->orderBy(
                User::query()->select('name')->whereColumn('users.id', 'observation_history.user_id'),
                $dir,
            ),
            'observacion' => fn (Builder $q, string $dir) => $q->orderBy(
                Observacion::query()->select('numero')->whereColumn('observations.id', 'observation_history.observation_id'),
                $dir,
            ),
        ];
    }

    public function index(Request $request)
    {
        $this->authorize('bitacora.view');

        $filters = $request->validate([
            // Sin estas dos reglas, `validate()` las descarta en silencio y el
            // ordenamiento no hace nada. Ver el trait `OrdenaListados`.
            'sort' => ['nullable', 'string', 'max:40'],
            'dir' => ['nullable', 'in:asc,desc'],
            'q' => ['nullable', 'string', 'max:255'],
            'accion' => ['nullable', Rule::in(ObservationHistory::ACCIONES)],
            // "sistema" es un valor especial (entradas sin autor, ver
            // whereNull más abajo); cualquier otro valor tiene que ser un id
            // de usuario real.
            'user_id' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value !== 'sistema' && ! User::whereKey($value)->exists()) {
                    $fail('El usuario seleccionado no es válido.');
                }
            }],
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'observacion_id' => ['nullable', 'integer', 'exists:observations,id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $entradas = ObservationHistory::query()
            ->with([
                'user:id,name,apellido',
                'observacion:id,numero,titulo,sector_id',
                'observacion.sector:id,nombre',
                'adjuntos:id,observation_history_id,original_name,size',
            ])
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(function ($query) use ($q) {
                $query->where('nota', 'like', "%{$q}%")
                    ->orWhereHas('observacion', fn ($o) => $o
                        ->where('numero', 'like', "%{$q}%")
                        ->orWhere('titulo', 'like', "%{$q}%"));
            }))
            ->when($filters['accion'] ?? null, fn ($query, $v) => $query->where('accion', $v))
            ->when($filters['user_id'] ?? null, fn ($query, $v) => $v === 'sistema'
                ? $query->whereNull('user_id')
                : $query->where('user_id', $v))
            ->when($filters['sector_id'] ?? null, fn ($query, $v) => $query->whereHas('observacion', fn ($o) => $o->where('sector_id', $v)))
            ->when($filters['observacion_id'] ?? null, fn ($query, $v) => $query->where('observation_id', $v))
            ->when($filters['desde'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '>=', $v))
            ->when($filters['hasta'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '<=', $v));

        return inertia('Admin/Bitacora/Index', [
            'entradas' => $this->aplicarOrden(
                $entradas,
                $request,
                $this->ordenables(),
                fn (Builder $q) => $q->latest(),
                'observation_history.id',
            )->paginate(30)->withQueryString(),
            'orden' => $this->orden($request, $this->ordenables()),
            'filters' => $filters,
            'usuarios' => User::orderBy('name')->get(['id', 'name', 'apellido']),
            'sectores' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
