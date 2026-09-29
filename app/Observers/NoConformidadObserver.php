<?php

namespace App\Observers;

use App\Models\NoConformidad;
use App\Models\NonConformityHistory;

/**
 * Sella los timestamps derivados del estado de una No Conformidad y deja la
 * entrada de bitácora de cada cambio.
 *
 * Vive en un observer y no en el controller por el mismo motivo que
 * `ObservacionObserver`: el estado de una NC cambia desde varios endpoints
 * distintos (enviar a aprobación, aprobar, devolver, rechazar, avanzar de
 * etapa, verificar, cerrar, reabrir, cancelar) y así hay un solo punto de
 * verdad.
 *
 * ⚠️ **Reparto de responsabilidades con el controller**: acá se registra *qué*
 * cambió ("De X a Y"). El *por qué* —el motivo de una devolución, un rechazo,
 * una cancelación o una reapertura— lo escribe el controller, porque es texto
 * libre que el observer no puede ver. Los dos relatos sirven y conviven.
 */
class NoConformidadObserver
{
    public function saving(NoConformidad $nc): void
    {
        if (! $nc->isDirty('estado')) {
            return;
        }

        $this->marcarAprobacion($nc);
        $this->marcarCierre($nc);
    }

    /**
     * Sella quién y cuándo aprobó, al entrar en el flujo formal.
     *
     * Va en `saving()` y no en `updated()` a propósito: el valor viaja en el
     * mismo UPDATE que el cambio de estado. Hacerlo en `updated()` obligaría a
     * un segundo guardado con `saveQuietly()` para no re-disparar el observer.
     *
     * Solo se sella la primera vez. Una NC que vuelve a investigación por una
     * verificación ineficaz (§4.7) ya fue aprobada: conserva su aprobación
     * original, igual que conserva su número.
     */
    private function marcarAprobacion(NoConformidad $nc): void
    {
        if ($nc->estado !== 'abierta' || $nc->aprobada_at !== null) {
            return;
        }

        $nc->aprobada_at = now();
        $nc->aprobada_por = auth()->id();
    }

    /**
     * Sella (o borra) la fecha de cierre al entrar o salir de un estado final.
     *
     * Reabrir una NC vuelve las dos columnas a null: sin esto, una NC reabierta
     * y cerrada de nuevo conservaría la fecha del primer cierre. Mismo criterio
     * que `ObservacionObserver::marcarCierre()`.
     */
    private function marcarCierre(NoConformidad $nc): void
    {
        $finalizada = $nc->estaFinalizada();

        $nc->cerrada_at = $finalizada ? now() : null;
        $nc->cerrada_por = $finalizada ? auth()->id() : null;
    }

    public function updated(NoConformidad $nc): void
    {
        if (! $nc->wasChanged('estado')) {
            return;
        }

        $nc->historial()->create([
            'user_id' => auth()->id(),
            'accion' => NonConformityHistory::ACCION_ESTADO,
            'cambios' => [
                // Se guardan las etiquetas legibles, no los slugs: la bitácora
                // es un registro histórico y tiene que poder leerse aunque
                // mañana se renombre un estado.
                'de' => NoConformidad::ESTADOS[$nc->getOriginal('estado')] ?? $nc->getOriginal('estado'),
                'a' => NoConformidad::ESTADOS[$nc->estado] ?? $nc->estado,
            ],
        ]);
    }
}
