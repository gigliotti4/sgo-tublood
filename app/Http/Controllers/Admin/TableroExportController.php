<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Observacion;
use App\Services\ObservacionExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel de lo que se está viendo en el tablero, con fecha de corte (punto 20 del
 * pedido del cliente del 6/11/2026).
 *
 * Los filtros del tablero se resuelven en el navegador, así que el servidor no los
 * conoce: el navegador manda los **ids** de las observaciones que quedaron en
 * pantalla y acá solo se arma el archivo. Lo que se baja es exactamente lo que se
 * ve, y no hay que duplicar en PHP los filtros por clic del tablero.
 *
 * "Fecha de corte": el archivo no incluye casos cargados después de esa fecha y
 * dice, en la hoja "Corte", de cuándo es y con qué filtros se armó. ⚠️ Los
 * estados son los de hoy: el corte limita qué casos entran, no reconstruye cómo
 * estaban ese día.
 *
 * Reusa `ObservacionExportService`, así que hereda la puerta del responsable del
 * fallo: la columna solo existe para jefes y Calidad.
 */
class TableroExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $this->authorize('tablero.view');

        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'corte' => ['nullable', 'date'],
            'periodo' => ['nullable', 'string', 'max:200'],
            'filtros' => ['nullable', 'array', 'max:20'],
            'filtros.*' => ['string', 'max:200'],
        ]);

        $corte = ($datos['corte'] ?? null) ?: now()->toDateString();
        $verResponsable = $request->user()->veResponsableDelFallo();

        $observaciones = Observacion::query()
            ->whereIn('id', $datos['ids'])
            ->whereDate('created_at', '<=', $corte)
            ->with([
                'responsable:id,name', 'sector:id,nombre', 'cliente:id,razon_social', 'creador:id,name',
                ...($verResponsable ? ['responsableFallo:id,nombre'] : []),
                ...ObservacionController::EAGER_PRODUCTOS,
            ])
            ->orderBy('created_at')
            ->get();

        return (new ObservacionExportService)->exportar($observaciones, $verResponsable, [
            'Fecha de corte' => date('d/m/Y', strtotime($corte)),
            'Generado' => now()->format('d/m/Y H:i'),
            'Período' => $datos['periodo'] ?? '—',
            'Filtros' => implode(' · ', $datos['filtros'] ?? []) ?: 'ninguno',
            'Observaciones' => (string) $observaciones->count(),
            'Aviso' => 'El corte limita qué casos entran; los estados son los de la fecha de generación.',
        ]);
    }
}
