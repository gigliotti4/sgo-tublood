<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Una tarea de fondo está fallando más de lo normal.
 *
 * Existe por lo que pasó entre el 24/8 y el 29/9/2026: `SyncClientesJob` falló
 * 310 veces contra el ERP de RP Sistemas y **nadie se enteró en 36 días**. No
 * rompió nada —el sync se reintenta cada 5 minutos y los datos nunca quedaron
 * viejos— pero esas 310 entradas son justo lo que va a tapar el día que falle
 * algo que sí importe.
 *
 * ⚠️ **El canal `database` va por la conexión `sync`**, igual que en las otras
 * notificaciones del proyecto: la fila se escribe en el acto y la campana
 * funciona aunque el worker esté caído. Y eso acá importa más que en el resto,
 * porque **si lo que falla es el worker, un aviso encolado no saldría nunca**.
 */
class JobsFallandoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, int>  $porClase  Clase del job => cuántas veces falló
     */
    public function __construct(public array $porClase, public int $horas) {}

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

    private function total(): int
    {
        return array_sum($this->porClase);
    }

    /** "SyncClientesJob (34)", con el namespace recortado para que se lea. */
    private function detalle(): string
    {
        $partes = [];

        foreach ($this->porClase as $clase => $veces) {
            $partes[] = class_basename($clase)." ({$veces})";
        }

        return implode(', ', $partes);
    }

    private function mensaje(): string
    {
        return "Hay tareas de fondo fallando más de lo habitual en las últimas {$this->horas} horas: "
            .$this->detalle().'.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tareas de fondo con fallas: '.$this->detalle())
            ->greeting("Hola {$notifiable->name},")
            ->line($this->mensaje())
            // El texto no da por sentado que sea un bug nuestro: la causa más
            // común medida hasta hoy es que el ERP de RP Sistemas devuelve 502
            // o no responde, y eso se reclama afuera, no se arregla acá.
            ->line('La causa más frecuente es que el ERP de RP Sistemas no responda. Si se sostiene varios días seguidos, conviene reclamarlo.')
            ->line('El detalle completo, con el error de cada intento, está en la tabla `failed_jobs`.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'jobs_fallando',
            'mensaje' => $this->mensaje(),
            'total' => $this->total(),
            'por_clase' => $this->porClase,
        ];
    }
}
