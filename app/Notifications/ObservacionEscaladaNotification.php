<?php

namespace App\Notifications;

use App\Notifications\Concerns\DetallaElAtraso;

/** Segundo aviso: pasó otro plazo sin gestión y la observación sube al gerente. */
class ObservacionEscaladaNotification extends ObservacionNotification
{
    use DetallaElAtraso;

    protected function tipo(): string
    {
        return 'observacion_escalada';
    }

    protected function asunto(): string
    {
        $aCargoDe = $this->aCargoDe();

        // Mismo criterio que el aviso de vencimiento: el gerente decide si
        // abre por el nombre que ve en la bandeja.
        return "Escalamiento: observación {$this->observacion->numero}"
            .($aCargoDe !== '' ? " — {$aCargoDe}" : '');
    }

    protected function mensaje(): string
    {
        // Sin el nombre del responsable: este texto también es la primera línea
        // del mail, que le llega al propio responsable. Lo agregan `toArray()`
        // (para la campana) y `detalles()` (para el mail de los demás).
        return "La observación {$this->observacion->numero} sigue sin gestionarse después del aviso al supervisor.";
    }
}
