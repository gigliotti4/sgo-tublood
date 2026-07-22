<?php

namespace App\Notifications;

/** Primer aviso: se cumplió el plazo de gestión. Va al responsable y a su supervisor. */
class ObservacionVencidaNotification extends ObservacionNotification
{
    protected function tipo(): string
    {
        return 'observacion_vencida';
    }

    protected function asunto(): string
    {
        return "Observación {$this->observacion->numero} vencida";
    }

    protected function mensaje(): string
    {
        return "La observación {$this->observacion->numero} pasó su plazo de gestión y sigue sin resolverse.";
    }
}
