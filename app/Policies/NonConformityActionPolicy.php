<?php

namespace App\Policies;

use App\Models\NonConformityAction;
use App\Models\User;
use App\Policies\Concerns\GestionaRenglonesDeNc;

/**
 * Quién puede tocar UNA acción del plan (sección 5 del Informe de Desvío).
 *
 * Se auto-descubre por convención de nombre (`NonConformityAction` →
 * `NonConformityActionPolicy`): no hay que registrarla en ningún provider.
 *
 * ⚠️ Es una Policy aparte de `NoConformidadPolicy` a propósito: la pregunta no
 * es "¿podés gestionar este caso?" sino "¿podés gestionar ESTE renglón?", y la
 * respuesta cambia por fila. Sin esto, el responsable de una acción tenía que
 * pedirle a Calidad que se la marcara hecha.
 */
class NonConformityActionPolicy
{
    use GestionaRenglonesDeNc;

    /** Avance, evidencias, descripción, fecha y cancelación con justificación. */
    public function gestionar(User $user, NonConformityAction $accion): bool
    {
        return $this->puedeGestionarRenglon(
            $user,
            $accion->noConformidad,
            $accion->responsable_id,
        );
    }

    /**
     * Borrar la acción del plan.
     *
     * ⚠️ **No la abre al responsable del renglón**, a diferencia de `gestionar`.
     * Borrar es una decisión de quien arma el plan, y además borra el rastro; la
     * salida del responsable es **cancelarla con justificación**, que deja
     * registro de por qué se abandonó. Por eso acá se exige ser dueño del caso o
     * de Calidad.
     */
    public function eliminar(User $user, NonConformityAction $accion): bool
    {
        $nc = $accion->noConformidad;

        if (! $nc || $nc->trashed() || $nc->estaFinalizada()) {
            return false;
        }

        return $nc->responsable_id === $user->id || $user->can('nc.gestionar');
    }
}
