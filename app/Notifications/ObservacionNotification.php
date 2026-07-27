<?php

namespace App\Notifications;

use App\Models\Observacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Molde común de los avisos de una observación: mismo par de canales
 * (`database` alimenta la campana del panel, `mail` sale por Resend en
 * producción y al log en dev) y misma forma de payload para el frontend.
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
        return ['database', 'mail'];
    }

    /**
     * El canal `database` se resuelve en el momento, sin pasar por la cola.
     *
     * Es un INSERT local —no hay nada que pueda colgar la request— y es lo que
     * alimenta la campana y el modal de reclamos nuevos del panel: si esperara a
     * un worker, alguien podría entrar al sistema y no ver un reclamo que ya
     * está cargado. Encolar solo tiene sentido para `mail`, que sí sale por HTTP
     * a Resend, y ese sigue yendo a la conexión por defecto.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
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
            ->action('Ver en el sistema', route('observaciones.index'));
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
        ];
    }
}
