<?php

namespace App\Notifications;

/**
 * Llegó la fecha de comprobar si el plan de acción sirvió.
 *
 * La fecha la cargó una persona al armar el plan (§4.5), justamente para que el
 * recordatorio se ajuste al tipo de desvío en vez de usar un plazo fijo para
 * todos: hay desvíos que se verifican al mes y otros que necesitan tres lotes.
 *
 * ⚠️ Se avisa **una sola vez**, y lo que lo garantiza es
 * `non_conformities.verificacion_avisada_at`. Acá no hay ningún estado que
 * cambie —la NC puede seguir en implementación— así que sin esa columna la
 * tarea repetiría el aviso todos los días. Mover la fecha para adelante limpia
 * la marca y el aviso vuelve a salir: eso es deliberado.
 */
class VerificacionPendienteNotification extends NoConformidadNotification
{
    protected function tipo(): string
    {
        return 'nc_verificacion_pendiente';
    }

    protected function asunto(): string
    {
        $numero = $this->noConformidad->numero;

        return $numero
            ? "Toca verificar la eficacia de la No Conformidad {$numero}"
            : 'Toca verificar la eficacia de una No Conformidad';
    }

    protected function mensaje(): string
    {
        return 'Llegó la fecha prevista para comprobar si el plan de acción sirvió.';
    }

    /** @return array<int, string> */
    protected function detalles(object $notifiable): array
    {
        return [
            'Hay que registrar el método de seguimiento, la evidencia revisada y el resultado.',
            'Si todavía no pasó el tiempo necesario para saberlo, se puede dejar como "Pendiente de evaluación" y volver más adelante.',
        ];
    }
}
