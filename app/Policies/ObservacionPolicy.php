<?php

namespace App\Policies;

use App\Models\Observacion;
use App\Models\User;

class ObservacionPolicy
{
    /**
     * Puede gestionar el caso el responsable asignado, o cualquiera del sector
     * al que está asignada la observación.
     *
     * Lo segundo hace falta porque al derivar el responsable anterior queda
     * liberado ("sin asignar"): sin esta regla, una observación recién
     * derivada quedaría bloqueada hasta que alguien del sector destino se
     * autoasignara, y nadie de ese sector podría hacerlo porque justamente no
     * puede editarla. `sector_id === null` no cuenta como match aunque el
     * usuario tampoco tenga sector: dos nulls no son "el mismo sector".
     */
    public function update(User $user, Observacion $observacion): bool
    {
        if ($user->id === $observacion->responsable_id) {
            return true;
        }

        return $observacion->sector_id !== null && $user->sector_id === $observacion->sector_id;
    }

    /**
     * Dejar un comentario (con adjuntos) en la bitácora del caso.
     *
     * Más amplio que `update`: además de quien lo gestiona, pueden comentar los
     * usuarios sumados como "a notificar". Se les pide opinión o datos sobre el
     * caso, así que tienen que poder responder por el mismo canal donde queda
     * registrado — pero no reasignar, reclasificar ni cambiar el estado.
     */
    public function comentar(User $user, Observacion $observacion): bool
    {
        return $this->update($user, $observacion)
            || $observacion->notificados()->whereKey($user->id)->exists();
    }
}
