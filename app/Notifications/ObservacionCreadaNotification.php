<?php

namespace App\Notifications;

use App\Models\Observacion;
use App\Support\TaxonomiaIncidencias;

/**
 * Se cargó una observación, por el canal que sea. Va a los super-admin, que son
 * los que necesitan ver todo lo que entra al sistema.
 *
 * Es una clase propia y no un reuso de `ObservacionExternaRecibidaNotification`
 * por tres motivos, el primero decisivo:
 *
 * 1. `HandleInertiaRequests` **excluye** ese tipo de `notificaciones.alertas`
 *    para no duplicar el bloque "Sin clasificar". Una observación interna nace
 *    `clasificada` y nunca está en ese bloque, así que reusarla dejaría el
 *    aviso escrito en la base y **sin ningún lugar donde mostrarse**: llegaría
 *    el mail y nada en la campana.
 * 2. `externasPendientes()` arma el modal "Entró un reclamo nuevo" cruzando los
 *    sin clasificar con esa notificación: reusarla metería al super-admin en un
 *    flujo pensado para quien clasifica.
 * 3. "Nuevo reclamo de cliente" es falso para una interna de Producción.
 *
 * Quién la recibe y quién queda afuera lo decide
 * `ObservacionObserver::avisarALosSuperAdmin()`.
 */
class ObservacionCreadaNotification extends ObservacionNotification
{
    protected function tipo(): string
    {
        return 'observacion_creada';
    }

    protected function asunto(): string
    {
        return "Nueva observación {$this->observacion->numero}: {$this->observacion->titulo}";
    }

    protected function mensaje(): string
    {
        $tipo = TaxonomiaIncidencias::etiquetasTipos()[$this->observacion->tipo] ?? $this->observacion->tipo;
        $origen = Observacion::ORIGENES[$this->observacion->origen] ?? $this->observacion->origen;

        // Quién la cargó cambia de lugar según el origen: en una externa es el
        // cliente que la tipeó en el portal; en una interna, el usuario del
        // panel, que puede no estar cargado (altas por consola o por seeder).
        $quien = $this->observacion->origen === 'externa'
            ? ($this->observacion->contacto_nombre ?: 'un cliente')
            : ($this->observacion->creador?->nombreCompleto ?: 'el sistema');

        return "Se cargó una observación {$origen} de tipo {$tipo}, por {$quien}.";
    }
}
