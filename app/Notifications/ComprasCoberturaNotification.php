<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * El mail mensual de Compras: los productos que no cubren stock.
 *
 * Solo mail: no es un caso puntual que se gestione desde la campana, es un
 * resumen. Va encolado como el resto de los mails (cada uno es una llamada HTTP
 * a Resend), así que todo lo que necesita viaja ya calculado y el Excel se lee
 * del disco al mandarlo — ver `AlertaCobertura::excel()`.
 */
class ComprasCoberturaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{producto: string, proveedor: string, comprar: float, entrega: ?string}>  $top
     */
    public function __construct(
        public int $total,
        public string $periodo,
        public array $top,
        public string $excel,
        /** Los filtros en palabras ("objetivo de 1 mes · Distribución · …"), armados desde la config. */
        public string $criterio,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $productos = $this->total === 1 ? '1 producto' : "{$this->total} productos";

        $mail = (new MailMessage)
            ->subject("Compras: {$productos} para comprar")
            ->greeting(isset($notifiable->name) ? "Hola {$notifiable->name}," : 'Hola,')
            ->line("Tenés **{$productos}** que no cubren el stock objetivo, según las ventas de {$this->periodo}.")
            ->line('Los que más faltan:');

        foreach ($this->top as $fila) {
            $detalle = number_format($fila['comprar'], 0, ',', '.').' u.';
            if ($fila['proveedor'] !== '') {
                $detalle .= " · {$fila['proveedor']}";
            }
            if ($fila['entrega'] !== null) {
                $detalle .= ' · OC con entrega '.date('d/m', strtotime($fila['entrega']));

                // Una OC pendiente con fecha pasada ya debería haber llegado:
                // es la que hay que reclamarle al proveedor.
                if ($fila['entrega'] < now()->toDateString()) {
                    $detalle .= ' (atrasada)';
                }
            }

            $mail->line("- **{$fila['producto']}** — {$detalle}");
        }

        if ($this->total > count($this->top)) {
            $mail->line('El detalle completo está en el Excel adjunto.');
        }

        $mail->action('Abrir el tablero de Compras', route('compras.index'))
            ->line("Se calcula igual que el tablero, con estos filtros: {$this->criterio}.");

        if (Storage::disk('local')->exists($this->excel)) {
            $mail->attach(Storage::disk('local')->path($this->excel), [
                'as' => basename($this->excel),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return $mail;
    }
}
