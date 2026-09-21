<?php

namespace App\Notifications;

use App\Notifications\Concerns\DetallaElAtraso;

/** Primer aviso: se cumplió el plazo de gestión. Va al responsable y a su supervisor. */
class ObservacionVencidaNotification extends ObservacionNotification
{
    use DetallaElAtraso;

    protected function tipo(): string
    {
        return 'observacion_vencida';
    }

    protected function asunto(): string
    {
        $aCargoDe = $this->aCargoDe();

        // El responsable va en el asunto porque un supervisor recibe varios de
        // estos y el asunto es lo único que puede comparar sin abrirlos. No
        // rompe la regla anti-spam de `toMail()`, que es sobre emojis y
        // mayúsculas al principio del asunto.
        return "Observación {$this->observacion->numero} vencida"
            .($aCargoDe !== '' ? " — {$aCargoDe}" : '');
    }

    protected function mensaje(): string
    {
        // Sin el nombre del responsable: este texto también es la primera línea
        // del mail, que le llega al propio responsable. Lo agregan `toArray()`
        // (para la campana) y `detalles()` (para el mail de los demás).
        return "La observación {$this->observacion->numero} pasó su plazo de gestión y sigue sin resolverse.";
    }
}
