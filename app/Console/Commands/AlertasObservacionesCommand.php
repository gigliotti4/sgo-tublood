<?php

namespace App\Console\Commands;

use App\Models\Observacion;
use App\Notifications\ObservacionEscaladaNotification;
use App\Notifications\ObservacionVencidaNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Motor de alertas por vencimiento del plazo de gestión.
 *
 * `alerta_nivel` hace idempotente al comando: correrlo cada hora no repite
 * avisos, porque cada nivel se emite una sola vez por observación.
 */
class AlertasObservacionesCommand extends Command
{
    protected $signature = 'observaciones:alertas';

    protected $description = 'Avisa por observaciones que pasaron su plazo de gestión y las escala al gerente';

    public function handle(): int
    {
        $avisadas = 0;
        $escaladas = 0;

        // El plazo que cuenta es la prórroga autorizada si la hay, y si no el
        // original. Misma regla que `Observacion::vencimiento()`. Un NULL en el
        // COALESCE (sin plazo) no cumple el `<=`, así que no hace falta
        // `whereNotNull` aparte.
        $observaciones = Observacion::query()
            ->whereRaw('COALESCE(prorroga_hasta, vence_at) <= ?', [now()])
            ->where('alerta_nivel', '<', 2)
            ->whereNotNull('responsable_id')
            // No basta con excluir `estados_finales` (solo 'cerrada'): una
            // observación cancelada tampoco es trabajo pendiente, y sin este
            // filtro seguía venciendo y escalando al gerente indefinidamente.
            ->whereIn('estado', Observacion::ESTADOS_ABIERTOS)
            ->with(['responsable.sector', 'responsable.supervisor', 'responsable.gerente'])
            ->get();

        foreach ($observaciones as $observacion) {
            $responsable = $observacion->responsable;

            if (! $responsable) {
                continue;
            }

            if ($observacion->alerta_nivel === 0) {
                Notification::send(
                    collect([$responsable, $responsable->supervisor])->filter()->unique('id'),
                    new ObservacionVencidaNotification($observacion)
                );

                $observacion->update(['alerta_nivel' => 1]);
                $avisadas++;

                continue;
            }

            // El salto al gerente recién ocurre si pasó otro plazo igual sin gestión.
            $dias = $responsable->sector?->dias_gestion;

            // Contado desde el plazo vigente, no desde el original: tras una
            // prórroga el "otro plazo igual" arranca en la fecha autorizada.
            if (! $dias || now()->lt($observacion->vencimiento()->copy()->addWeekdays($dias))) {
                continue;
            }

            $destinatarios = collect([$responsable->gerente, $responsable->supervisor])
                ->filter()
                ->unique('id');

            // Sin gerente ni supervisor no hay a quién escalar: se deja el nivel
            // como está por si más adelante le cargan la cadena al responsable.
            if ($destinatarios->isEmpty()) {
                continue;
            }

            Notification::send($destinatarios, new ObservacionEscaladaNotification($observacion));

            $observacion->update(['alerta_nivel' => 2]);
            $escaladas++;
        }

        $this->info("Alertas enviadas: {$avisadas} · escalamientos: {$escaladas}");

        return self::SUCCESS;
    }
}
