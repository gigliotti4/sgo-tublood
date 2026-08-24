<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A propósito NO implementa ShouldQueue, a diferencia de ObservacionNotification:
 * en dev (y en cualquier entorno sin worker activo) un reset encolado se
 * quedaría en la tabla `jobs` y la persona creería que el mail nunca llegó.
 * Un reset de contraseña tiene que salir dentro de la misma request.
 */
class RestablecerPasswordNotification extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = config('auth.passwords.users.expire');

        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Restablecer contraseña')
            ->greeting('Hola,')
            ->line('Recibimos un pedido para restablecer la contraseña de tu cuenta en SGO Tublood.')
            ->action('Restablecer contraseña', $url)
            ->line("Este enlace vence en {$minutos} minutos.")
            ->line('Si no pediste esto, podés ignorar este mensaje.');
    }
}
