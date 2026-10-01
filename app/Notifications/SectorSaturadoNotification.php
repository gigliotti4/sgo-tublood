<?php

namespace App\Notifications;

use App\Models\Sector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Un sector superó su tope de observaciones abiertas.
 *
 * Existe porque la única alerta que había era por caso: `observaciones:alertas`
 * avisa cuando una observación puntual venció. Eso llega tarde — para cuando
 * suena, el plazo ya se incumplió. Este aviso mira la carga del área y suena
 * **antes**, cuando todavía se puede redistribuir trabajo.
 *
 * ⚠️ **No hereda de `ObservacionNotification`.** Esa clase recibe una
 * `Observacion` en el constructor y su `toArray()` arma `observacion_id`,
 * `numero` y la URL de un caso puntual. Acá el sujeto es un sector, no un caso,
 * así que el molde es `JobsFallandoNotification`: notificación suelta con su
 * propio payload.
 *
 * ⚠️ **El canal `database` va por la conexión `sync`**, igual que el resto de
 * las notificaciones del proyecto: la fila se escribe en el acto y la campana
 * funciona aunque el worker esté caído. Solo el mail queda encolado, que es lo
 * único que justifica la cola (es una llamada HTTP a Resend).
 */
class SectorSaturadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Sector $sector, public int $abiertas) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    private function mensaje(): string
    {
        return "{$this->sector->nombre} tiene {$this->abiertas} observaciones abiertas y su tope es {$this->sector->tope_observaciones}.";
    }

    /**
     * ⚠️ El enlace va al listado de **abiertas**, no al del sector: hoy
     * `observaciones.index` no tiene filtro por sector (sí por apertura,
     * responsable, origen y prioridad). El sector va dicho en el texto. Si
     * algún día se agrega ese filtro, acá es donde hay que enchufarlo.
     */
    private function url(): string
    {
        return route('observaciones.index', ['apertura' => 'abierta']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->sector->nombre} superó su tope de observaciones abiertas")
            ->greeting("Hola {$notifiable->name},")
            ->line($this->mensaje())
            // El texto no acusa a nadie: que un sector se sature puede ser un
            // pico de trabajo y no un problema de gestión. Mismo criterio que
            // los avisos de vencimiento, que dicen "está a cargo de" y nunca
            // "no dio respuesta".
            ->line('Puede ser un pico de trabajo o que haga falta repartir los casos. Conviene revisarlo con el equipo.')
            ->action('Ver las observaciones abiertas', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'sector_saturado',
            'mensaje' => $this->mensaje(),
            'sector_id' => $this->sector->id,
            'sector' => $this->sector->nombre,
            'abiertas' => $this->abiertas,
            'tope' => $this->sector->tope_observaciones,
            'url' => $this->url(),
        ];
    }
}
