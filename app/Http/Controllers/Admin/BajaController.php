<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Observacion;
use App\Models\ObservationHistory;
use Illuminate\Http\Request;

/**
 * Historia de lo dado de baja: observaciones borradas (soft delete) y
 * canceladas, con motivo y autor. Gateada con `observaciones.delete` y no con
 * `observaciones.view`: quien necesita esta pantalla es quien puede dar de
 * baja y restaurar, no cualquiera que vea el listado — las canceladas ya son
 * visibles ahí (filtro Estado → Cerradas) para todos los demás.
 */
class BajaController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('observaciones.delete');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'in:cancelada,borrada'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        return inertia('Admin/Bajas/Index', [
            'observaciones' => Observacion::withTrashed()
                ->where(fn ($query) => $query->whereNotNull('deleted_at')->orWhere('estado', 'cancelada'))
                ->with([
                    'baja.user:id,name,apellido',
                    'responsable:id,name',
                    'sector:id,nombre',
                ])
                ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(function ($query) use ($q) {
                    $query->where('numero', 'like', "%{$q}%")
                        ->orWhere('titulo', 'like', "%{$q}%");
                }))
                ->when($filters['tipo'] ?? null, fn ($query, $v) => $v === 'borrada'
                    ? $query->whereNotNull('deleted_at')
                    : $query->whereNull('deleted_at')->where('estado', 'cancelada'))
                ->when($filters['desde'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '>=', $v))
                ->when($filters['hasta'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '<=', $v))
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }

    /**
     * Solo tiene sentido para las borradas (una cancelada no está "trashed",
     * solo cambia el estado desde el modal de edición como cualquier otra).
     */
    public function restore(Observacion $observacion)
    {
        $this->authorize('observaciones.delete');

        $observacion->restore();

        $observacion->historial()->create([
            'user_id' => auth()->id(),
            'accion' => ObservationHistory::ACCION_RESTAURACION,
        ]);

        return redirect()->route('bajas.index')
            ->with('success', 'Observación restaurada correctamente.');
    }
}
