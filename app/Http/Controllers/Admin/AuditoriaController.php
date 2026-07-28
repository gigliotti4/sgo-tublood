<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vista transversal de la bitácora: a diferencia de BitacoraObservacion.vue
 * (el historial de un caso puntual, embebido en Show/Index de Observaciones),
 * acá se consulta la tabla `observation_history` completa, filtrable por
 * usuario, sector, tipo de acción y rango de fechas.
 *
 * Gateada con su propio permiso (`auditoria.view`) y no con `observaciones.view`:
 * ver quién hizo qué en todo el sistema es una capacidad distinta —y más
 * sensible— que ver el listado de casos.
 */
class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('auditoria.view');

        $filters = $request->validate([
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

        return inertia('Admin/Auditoria/Index', [
            'entradas' => ObservationHistory::query()
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
                ->when($filters['hasta'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '<=', $v))
                ->latest()
                ->paginate(30)
                ->withQueryString(),
            'filters' => $filters,
            'usuarios' => User::orderBy('name')->get(['id', 'name', 'apellido']),
            'sectores' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
