<?php

namespace App\Notifications;

use App\Models\NoConformidad;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Molde común de los avisos de una No Conformidad: `database` alimenta la
 * campana y `mail` sale por Resend.
 *
 * Molde de `ObservacionNotification`, **sin el canal `broadcast`**: las NC no
 * tienen toast en vivo, y sumar un canal que nadie escucha solo agrega un job
 * por aviso. Si alguna vez se agrega, hay que decidir explícitamente de qué
 * lado de `viaConnections()` va.
 *
 * Va encolada porque cada mail es una llamada HTTP a Resend: así la request del
 * usuario no espera, y una caída del proveedor no rompe la operación.
 */
abstract class NoConformidadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public NoConformidad $noConformidad) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * ⚠️ **`database` va a `sync`**: la fila se escribe en el acto, que es lo
     * que hace que la campana funcione aunque no haya un worker corriendo. Solo
     * el mail queda encolado, que es la llamada HTTP y lo único que justifica la
     * cola. Sin worker se acumulan los mails, no los avisos de la campana.
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

    /**
     * Líneas extra del cuerpo del mail, entre el mensaje y el número de caso.
     *
     * Hook en vez de sobreescribir `toMail()`, por el mismo motivo que en
     * `ObservacionNotification`: este método concentra el saludo, el número y el
     * botón, escritos una sola vez.
     *
     * @return array<int, string>
     */
    protected function detalles(object $notifiable): array
    {
        return [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->asunto())
            ->greeting("Hola {$notifiable->name},")
            ->line($this->mensaje());

        foreach ($this->detalles($notifiable) as $detalle) {
            $mail->line($detalle);
        }

        return $mail
            ->line($this->identificacion())
            ->action('Ver en el sistema', route('no-conformidades.show', $this->noConformidad));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => $this->tipo(),
            'no_conformidad_id' => $this->noConformidad->id,
            // ⚠️ Puede ser `null`: una NC en borrador todavía no tiene número, y
            // eso no es un dato faltante. El front lo resuelve con `numeroDe()`.
            'numero' => $this->noConformidad->numero,
            'mensaje' => $this->mensaje(),
            'url' => route('no-conformidades.show', $this->noConformidad),
        ];
    }

    /** Cómo se nombra el caso en el mail, con o sin número asignado. */
    protected function identificacion(): string
    {
        $numero = $this->noConformidad->numero;

        return $numero
            ? "No Conformidad {$numero}: {$this->noConformidad->motivo}"
            : "No Conformidad en borrador: {$this->noConformidad->motivo}";
    }
}
