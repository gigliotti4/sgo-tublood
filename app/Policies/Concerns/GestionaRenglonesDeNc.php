<?php

namespace App\Policies\Concerns;

use App\Models\NoConformidad;
use App\Models\User;

/**
 * La regla de quién puede tocar un renglón de una No Conformidad — una acción
 * del plan (sección 5) o una acción de contención (sección 3).
 *
 * Vive en un trait y no copiada en las dos Policies porque son exactamente la
 * misma regla: si se escribieran dos veces, cambiar una dejaría la otra con el
 * criterio viejo y nadie se enteraría hasta que alguien no pudiera trabajar.
 */
trait GestionaRenglonesDeNc
{
    /**
     * Tres caminos, en orden de cercanía al trabajo:
     *
     * 1. **El responsable del renglón.** Es el cambio del 25/9/2026: quien tiene
     *    una acción asignada la gestiona de principio a fin —avance, evidencias,
     *    descripción, fecha y cancelación con justificación— sin depender de que
     *    Calidad se la mueva.
     * 2. **El responsable del caso**, que es dueño de la NC entera.
     * 3. **Garantía de Calidad** (`nc.gestionar`), que conserva acceso a todo
     *    para seguimiento y control.
     *
     * ⚠️ Una NC finalizada bloquea a los tres. El super-admin la sigue tocando
     * igual, pero por el bypass de `Gate::before`, no por esta regla.
     */
    protected function puedeGestionarRenglon(User $user, ?NoConformidad $nc, ?int $responsableId): bool
    {
        if (! $nc || $nc->trashed() || $nc->estaFinalizada()) {
            return false;
        }

        return $responsableId === $user->id
            || $nc->responsable_id === $user->id
            || $user->can('nc.gestionar');
    }
}
