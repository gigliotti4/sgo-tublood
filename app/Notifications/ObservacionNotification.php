<?php

namespace App\Notifications;

use App\Models\Observacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Molde común de los avisos de una observación: `database` alimenta la
 * campana, `broadcast` actualiza el panel en vivo y `mail` sale por Resend.
 *
 * Va encolada porque cada mail es una llamada HTTP a Resend: así la request
 * del usuario no espera, y una caída del proveedor no rompe la operación.
 */
abstract class ObservacionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Observacion $observacion) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    /**
     * La fila de `database` se inserta en el momento. El broadcast se difiere
     * hasta después de responder y luego sale en sync: así funciona en hosting
     * compartido sin un worker permanente y un fallo externo no rompe el alta.
     * El mail conserva la conexión de cola por defecto.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'broadcast' => config('broadcasting.notification_queue_connection'),
        ];
    }

    /** Clave estable para que el frontend distinga el tipo de aviso. */
    abstract protected function tipo(): string;

    abstract protected function asunto(): string;

    abstract protected function mensaje(): string;

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->asunto())
            ->greeting("Hola {$notifiable->name},")
            ->line($this->mensaje())
            ->line("Observación {$this->observacion->numero}: {$this->observacion->titulo}")
            ->action('Ver en el sistema', route('observaciones.show', $this->observacion));
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))
            ->onConnection(config('broadcasting.event_queue_connection'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => $this->tipo(),
            'observacion_id' => $this->observacion->id,
            'numero' => $this->observacion->numero,
            'titulo' => $this->observacion->titulo,
            'mensaje' => $this->mensaje(),
            'url' => route('observaciones.show', $this->observacion),
        ];
    }
}
