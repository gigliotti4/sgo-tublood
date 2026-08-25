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

    // Sin override de via(): hereda ['database', 'broadcast', 'mail'] de
    // ObservacionNotification. Antes se recortaba a solo panel — "no genera un
    // correo adicional" — pero el responsable no tiene por qué estar mirando
    // el sistema en el momento en que le asignan un caso.

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
