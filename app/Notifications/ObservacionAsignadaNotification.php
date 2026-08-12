<?php

namespace App\Notifications;

use App\Models\Observacion;

/** Aviso dentro del sistema para quien recibe una observación. */
class ObservacionAsignadaNotification extends ObservacionNotification
{
    public function __construct(Observacion $observacion, private readonly bool $reasignada = false)
    {
        parent::__construct($observacion);
    }

    /** La asignación se avisa en el panel; no genera un correo adicional. */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    protected function tipo(): string
    {
        return $this->reasignada ? 'observacion_reasignada' : 'observacion_asignada';
    }

    protected function asunto(): string
    {
        return "Te asignaron la observación {$this->observacion->numero}";
    }

    protected function mensaje(): string
    {
        $accion = $this->reasignada ? 'Te reasignaron' : 'Te asignaron';

        return "{$accion} la observación \"{$this->observacion->titulo}\".";
    }
}
