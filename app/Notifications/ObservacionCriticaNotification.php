<?php

namespace App\Notifications;

/**
 * El caso pasó a prioridad crítica. Va al responsable.
 *
 * Solo se manda al **entrar** en crítica, no ante cualquier cambio de
 * prioridad: bajar de crítica a alta no es una señal que requiera interrumpir a
 * nadie, y avisar cada movimiento convertiría la campana en ruido.
 *
 * Se avisa en el panel y no por mail, igual que la asignación: quien tiene el
 * caso a cargo lo está mirando ahí, y el mail queda para lo que exige salir de
 * la aplicación (vencimiento, escalamiento, cierre).
 */
class ObservacionCriticaNotification extends ObservacionNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    protected function tipo(): string
    {
        return 'observacion_critica';
    }

    protected function asunto(): string
    {
        return "Observación {$this->observacion->numero} marcada como crítica";
    }

    protected function mensaje(): string
    {
        return "La observación \"{$this->observacion->titulo}\" pasó a prioridad crítica.";
    }
}
