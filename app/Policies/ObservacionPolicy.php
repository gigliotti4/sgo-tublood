<?php

namespace App\Policies;

use App\Models\Observacion;
use App\Models\User;

class ObservacionPolicy
{
    public function update(User $user, Observacion $observacion): bool
    {
        return $user->id === $observacion->responsable_id;
    }
}
