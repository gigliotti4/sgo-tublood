<?php

namespace App\Notifications;

/**
 * Te designaron responsable de un desvío: lo gestionás de punta a punta.
 *
 * Es el aviso que hace usable la delegación. Sin él, derivar un caso significa
 * que la persona no se entera hasta que alguien le avisa por fuera del sistema.
 */
class NoConformidadAsignadaNotification extends NoConformidadNotification
{
    protected function tipo(): string
    {
        return 'nc_asignada';
    }

    protected function asunto(): string
    {
        $numero = $this->noConformidad->numero;

        return $numero
            ? "Quedaste a cargo de la No Conformidad {$numero}"
            : 'Quedaste a cargo de una No Conformidad';
    }

    protected function mensaje(): string
    {
        return 'Quedaste como responsable de este desvío: la investigación, el plan de acción y el cierre están a tu cargo.';
    }

    /** @return array<int, string> */
    protected function detalles(object $notifiable): array
    {
        $sector = $this->noConformidad->sector?->nombre;

        return array_values(array_filter([
            $sector ? "Sector involucrado: {$sector}." : null,
            'Garantía de Calidad hace el seguimiento y está para consultarle, pero la gestión es tuya.',
        ]));
    }
}
