<?php

namespace App\Notifications;

use App\Models\Observacion;
use App\Models\ObservationExtension;

/**
 * Se resolvió la prórroga que pidió el responsable. Le llega a quien la pidió,
 * por campana y mail: de la respuesta depende si el caso sigue corriendo o no.
 */
class ObservacionProrrogaResueltaNotification extends ObservacionNotification
{
    public function __construct(Observacion $observacion, public ObservationExtension $prorroga)
    {
        parent::__construct($observacion);
    }

    protected function tipo(): string
    {
        return 'observacion_prorroga_resuelta';
    }

    protected function asunto(): string
    {
        $resultado = $this->prorroga->estado === ObservationExtension::ESTADO_APROBADA ? 'aprobada' : 'rechazada';

        return "Prórroga {$resultado}: observación {$this->observacion->numero}";
    }

    protected function mensaje(): string
    {
        if ($this->prorroga->estado === ObservationExtension::ESTADO_APROBADA) {
            return sprintf(
                'Se aprobó la prórroga de "%s" hasta el %s. Mientras tanto no figura como vencida.',
                $this->observacion->titulo,
                $this->prorroga->fecha_autorizada->format('d/m/Y'),
            );
        }

        return sprintf(
            'No se aprobó la prórroga de "%s". Sigue corriendo el vencimiento original.',
            $this->observacion->titulo,
        );
    }

    /** @return array<int, string> */
    protected function detalles(object $notifiable): array
    {
        $detalles = [];

        // Si la fecha autorizada difiere de la pedida, que no pase inadvertido.
        if ($this->prorroga->estado === ObservationExtension::ESTADO_APROBADA
            && ! $this->prorroga->fecha_autorizada->isSameDay($this->prorroga->fecha_pedida)) {
            $detalles[] = 'Pediste hasta el '.$this->prorroga->fecha_pedida->format('d/m/Y').'; se autorizó otra fecha.';
        }

        if (filled($this->prorroga->comentario)) {
            $detalles[] = 'Comentario: '.$this->prorroga->comentario;
        }

        return $detalles;
    }
}
