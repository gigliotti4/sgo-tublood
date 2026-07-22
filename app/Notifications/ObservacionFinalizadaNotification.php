<?php

namespace App\Notifications;

/** Aviso final: la observación se cerró. Va al gerente del responsable. */
class ObservacionFinalizadaNotification extends ObservacionNotification
{
    protected function tipo(): string
    {
        return 'observacion_finalizada';
    }

    protected function asunto(): string
    {
        return "Observación {$this->observacion->numero} finalizada";
    }

    protected function mensaje(): string
    {
        return "La observación {$this->observacion->numero} pasó al estado \"{$this->observacion->estado}\".";
    }
}
