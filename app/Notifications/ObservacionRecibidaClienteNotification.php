<?php

namespace App\Notifications;

use App\Models\Observacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Acuse de recibo al cliente que cargó un reclamo por el portal público.
 *
 * No hereda de {@see ObservacionNotification} porque el destinatario no es un
 * usuario del sistema: se manda con `Notification::route('mail', $email)`, así
 * que no hay canal `database` (no tiene campana) ni nombre en el notifiable.
 * Es además el único mail que sale hacia afuera de Tublood, así que no lleva
 * ningún dato interno ni links al panel.
 */
class ObservacionRecibidaClienteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Observacion $observacion) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Recibimos tu reclamo — N° {$this->observacion->numero}")
            ->greeting("Hola {$this->observacion->contacto_nombre},")
            ->line('Recibimos tu reclamo y ya está en revisión por nuestro equipo de Garantía de Calidad.')
            ->line("**Número de seguimiento: {$this->observacion->numero}**")
            ->line("Asunto: {$this->observacion->titulo}")
            ->line('Guardá este número: es la referencia para cualquier consulta sobre el caso.')
            ->line('Te vamos a contactar a este mismo correo cuando tengamos novedades.')
            ->salutation('Gracias, equipo de Tublood.');
    }
}
