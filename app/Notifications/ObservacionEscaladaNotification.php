<?php

namespace App\Notifications;

/** Segundo aviso: pasó otro plazo sin gestión y la observación sube al gerente. */
class ObservacionEscaladaNotification extends ObservacionNotification
{
    protected function tipo(): string
    {
        return 'observacion_escalada';
    }

    protected function asunto(): string
    {
        return "Escalamiento: observación {$this->observacion->numero}";
    }

    protected function mensaje(): string
    {
        return "La observación {$this->observacion->numero} sigue sin gestionarse después del aviso al supervisor.";
    }
}
