<?php

namespace App\Observers;

use App\Models\Observacion;
use App\Models\User;
use App\Notifications\ObservacionFinalizadaNotification;

/**
 * Arranca y corta el reloj de gestión de una observación.
 *
 * Vive en un observer y no en el controller porque el responsable se asigna
 * desde tres lugares distintos (`storeInterna`, `storeInternaEspecial` y
 * `update` de Admin\ObservacionController), y así queda un solo punto de verdad.
 */
class ObservacionObserver
{
    public function saving(Observacion $observacion): void
    {
        if (! $observacion->isDirty('responsable_id')) {
            return;
        }

        // Reasignar (o desasignar) reinicia el reloj: el plazo es de la persona
        // que tiene la observación ahora, no de la que la tenía antes.
        $observacion->responsable_asignado_at = null;
        $observacion->vence_at = null;
        $observacion->alerta_nivel = 0;

        if ($observacion->responsable_id === null) {
            return;
        }

        $dias = User::with('sector')->find($observacion->responsable_id)?->sector?->dias_gestion;

        $observacion->responsable_asignado_at = now();
        // Sin sector o sin plazo cargado no hay contra qué medir: la observación
        // queda sin vencimiento y nunca alerta.
        $observacion->vence_at = $dias ? now()->addWeekdays($dias) : null;
    }

    public function updated(Observacion $observacion): void
    {
        if (! $observacion->wasChanged('estado') || ! $observacion->estaFinalizada()) {
            return;
        }

        $observacion->responsable?->gerente
            ?->notify(new ObservacionFinalizadaNotification($observacion));
    }
}
