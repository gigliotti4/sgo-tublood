<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Controller;
use App\Models\Observacion;
use App\Models\ObservationHistory;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Archivo de casos terminados: observaciones **cerradas**, **canceladas** y
 * **borradas** (soft delete), con quién las terminó y cuándo.
 *
 * Las bajas traen además el motivo, que se pide al cancelar y al borrar y queda
 * en la bitácora (`Observacion::baja()`). Cerrar un caso hoy no pide ninguno,
 * así que esa columna queda vacía en las cerradas y la pantalla lo dice en vez
 * de dejar un guion mudo. Quién cerró sale de la columna propia `cerrada_por`
 * y no de la bitácora — ver `ObservacionObserver::marcarCierre()`.
 *
 * ⚠️ Sigue gateada con `observaciones.delete` y no con `observaciones.view`.
 * Con las cerradas adentro el criterio original quedó corto —el archivo le
 * sirve a más gente que a quien puede borrar— pero **nadie pierde acceso**: las
 * cerradas y las canceladas ya son visibles en `/observaciones` con el filtro
 * de estado, y las borradas eran, y siguen siendo, lo único exclusivo de esta
 * pantalla. Ampliar el acceso (bajarlo a `observaciones.view` o darle permiso
 * propio) es una decisión a tomar aparte, porque expone el motivo y el autor de
 * cada baja.
 */
class BajaController extends Controller
{
    use OrdenaListados;

    /**
     * Qué columnas se pueden ordenar.
     *
     * "Motivo" y "Quién" quedan afuera: salen de un `hasOne` sobre la bitácora
     * con `latestOfMany()` (`Observacion::baja()`), y ordenar por eso pediría
     * un lateral join.
     *
     * @return array<string, string|Closure>
     */
    private function ordenables(): array
    {
        return [
            'numero' => 'observations.numero',
            'titulo' => 'observations.titulo',
            // Agrupa por bucket en el mismo orden en que la pantalla los
            // presenta, no alfabéticamente. El borrado gana sobre el estado,
            // igual que en `tipoDeBaja()` del front.
            'baja' => fn (Builder $q, string $dir) => $q->orderByRaw(
                "CASE WHEN observations.deleted_at IS NOT NULL THEN 0 WHEN observations.estado = 'cancelada' THEN 1 ELSE 2 END {$dir}"
            ),
            // La misma fecha que muestra la fila: la de la baja si la hay, si
            // no la del cierre, y como último recurso el último movimiento.
            'fecha' => fn (Builder $q, string $dir) => $q->orderByRaw(
                'COALESCE(observations.deleted_at, observations.cerrada_at, observations.updated_at) '.$dir
            ),
        ];
    }

    public function index(Request $request)
    {
        $this->authorize('observaciones.delete');

        $filters = $request->validate([
            // Sin estas dos reglas, `validate()` las descarta en silencio y el
            // ordenamiento no hace nada. Ver el trait `OrdenaListados`.
            'sort' => ['nullable', 'string', 'max:40'],
            'dir' => ['nullable', 'in:asc,desc'],
            'q' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'in:cancelada,borrada,cerrada'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $observaciones = Observacion::withTrashed()
            ->where(fn ($query) => $query
                ->whereNotNull('deleted_at')
                ->orWhereIn('estado', ['cancelada', 'cerrada']))
            ->with([
                'baja.user:id,name,apellido',
                // Quién cerró sale de la columna propia y no de la bitácora
                // — ver ObservacionObserver::marcarCierre().
                'cerradaPorUsuario:id,name,apellido',
                'responsable:id,name',
                'sector:id,nombre',
            ])
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(function ($query) use ($q) {
                $query->where('numero', 'like', "%{$q}%")
                    ->orWhere('titulo', 'like', "%{$q}%");
            }))
                // El slug del filtro coincide con el del estado en los dos
                // casos que no son borrado, así que la rama por defecto los
                // cubre a los dos. El `whereNull('deleted_at')` hace que cada
                // caso caiga en un solo bucket: el borrado gana, porque es el
                // único que habilita restaurar.
            ->when($filters['tipo'] ?? null, fn ($query, $v) => match ($v) {
                'borrada' => $query->whereNotNull('deleted_at'),
                default => $query->whereNull('deleted_at')->where('estado', $v),
            })
            ->when($filters['desde'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '>=', $v))
            ->when($filters['hasta'] ?? null, fn ($query, $v) => $query->whereDate('created_at', '<=', $v));

        return inertia('Admin/Bajas/Index', [
            'observaciones' => $this->aplicarOrden(
                $observaciones,
                $request,
                $this->ordenables(),
                fn (Builder $q) => $q->latest('updated_at'),
                'observations.id',
            )->paginate(20)->withQueryString(),
            'orden' => $this->orden($request, $this->ordenables()),
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

        // Una cancelada o una cerrada no están "trashed": restaurarlas no
        // significa nada y solo dejaría un save y unos eventos al pedo. El
        // botón ya se muestra solo para las borradas; esto es la contraparte
        // del backend.
        abort_unless($observacion->trashed(), 404);

        $observacion->restore();

        $observacion->historial()->create([
            'user_id' => auth()->id(),
            'accion' => ObservationHistory::ACCION_RESTAURACION,
        ]);

        // `back()`: restaurar se dispara desde el listado, y el redirect pelado
        // devolvía a la página 1 sin los filtros que traía.
        return back(fallback: route('bajas.index'))
            ->with('success', 'Observación restaurada correctamente.');
    }
}
