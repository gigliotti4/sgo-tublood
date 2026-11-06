<?php

namespace App\Notifications;

use App\Models\Observacion;
use App\Models\ObservationExtension;

/**
 * Alguien pidió más plazo para una observación. Va a quien autoriza las
 * prórrogas (permiso `observaciones.prorrogas.autorizar`) por campana y mail:
 * hay una decisión esperando y no es algo que se mire en el panel por casualidad.
 */
class ObservacionProrrogaSolicitadaNotification extends ObservacionNotification
{
    public function __construct(Observacion $observacion, public ObservationExtension $prorroga)
    {
        parent::__construct($observacion);
    }

    protected function tipo(): string
    {
        return 'observacion_prorroga_solicitada';
    }

    protected function asunto(): string
    {
        return "Prórroga pedida: observación {$this->observacion->numero}";
    }

    protected function mensaje(): string
    {
        $quien = $this->prorroga->solicitante?->name ?? 'El responsable';

        return sprintf(
            '%s pide prorrogar hasta el %s la observación "%s".',
            $quien,
            $this->prorroga->fecha_pedida->format('d/m/Y'),
            $this->observacion->titulo,
        );
    }

    /** @return array<int, string> */
    protected function detalles(object $notifiable): array
    {
        return [
            'Motivo: '.(ObservationExtension::MOTIVOS[$this->prorroga->motivo] ?? $this->prorroga->motivo),
            'Qué se está haciendo: '.$this->prorroga->que_se_hace,
            'Justificación: '.$this->prorroga->justificacion,
        ];
    }
}
