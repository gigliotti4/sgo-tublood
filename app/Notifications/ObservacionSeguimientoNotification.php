<?php

namespace App\Notifications;

/**
 * Te sumaron como usuario a notificar de una observación.
 *
 * Se manda una sola vez, al agregar a la persona: en cada guardado posterior
 * solo se avisa a los que se sumaron en ese momento, no a los que ya estaban.
 */
class ObservacionSeguimientoNotification extends ObservacionNotification
{
    protected function tipo(): string
    {
        return 'observacion_seguimiento';
    }

    protected function asunto(): string
    {
        return "Te sumaron al seguimiento de {$this->observacion->numero}";
    }

    protected function mensaje(): string
    {
        return "Te sumaron al seguimiento de \"{$this->observacion->titulo}\". Podés ver el caso y dejar comentarios en su bitácora.";
    }
}
