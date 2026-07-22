<?php

namespace App\Notifications;

/**
 * Entró un reclamo por el portal público. Va al equipo de Garantía de Calidad,
 * que es quien lo clasifica.
 */
class ObservacionExternaRecibidaNotification extends ObservacionNotification
{
    protected function tipo(): string
    {
        return 'observacion_externa_recibida';
    }

    protected function asunto(): string
    {
        return "Nuevo reclamo de cliente: {$this->observacion->numero}";
    }

    protected function mensaje(): string
    {
        return "Entró un reclamo nuevo por el portal, cargado por {$this->observacion->contacto_nombre}. Está pendiente de clasificación.";
    }
}
