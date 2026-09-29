<?php

namespace App\Console\Commands;

use App\Models\NoConformidad;
use App\Models\NonConformityAction;
use App\Models\User;
use App\Notifications\AccionVencidaNotification;
use App\Notifications\VerificacionPendienteNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Los dos recordatorios de plazos de una No Conformidad.
 *
 * Es quien por fin **escribe el estado `vencida`** de una acción: estaba en el
 * catálogo desde el principio y nadie lo escribía, y por eso tampoco se puede
 * elegir a mano — ofrecerlo lo convertiría en "atrasada pero la sigo", que es
 * justo lo que el estado tiene que delatar solo.
 *
 * ⚠️ **Idempotencia por dos mecanismos distintos**, porque los dos casos no se
 * parecen:
 * - Las acciones salen del filtro solas al pasar a `vencida`, así que el propio
 *   cambio de estado alcanza (mismo truco que `alerta_nivel` en observaciones).
 * - La verificación no cambia ningún estado —la NC sigue donde estaba— así que
 *   necesita una marca propia: `verificacion_avisada_at`.
 */
class RecordatoriosNoConformidadesCommand extends Command
{
    protected $signature = 'nc:recordatorios';

    protected $description = 'Marca vencidas las acciones pasadas de plazo y avisa cuando toca verificar la eficacia';

    public function handle(): int
    {
        $vencidas = $this->marcarAccionesVencidas();
        $verificaciones = $this->avisarVerificacionesPendientes();

        $this->info("Acciones vencidas: {$vencidas} · verificaciones avisadas: {$verificaciones}");

        return self::SUCCESS;
    }

    /**
     * Las acciones que se pasaron de su fecha prevista y siguen sin resolverse.
     *
     * Se avisa al responsable de la acción y al del caso — son dos personas
     * distintas desde la delegación del 25/9/2026, y las dos necesitan saberlo:
     * una para hacerla, la otra porque el caso es suyo.
     */
    private function marcarAccionesVencidas(): int
    {
        $acciones = NonConformityAction::query()
            ->whereNotNull('fecha_prevista')
            ->whereDate('fecha_prevista', '<', now()->toDateString())
            // `vencida` queda afuera a propósito: es lo que hace que no se
            // vuelva a avisar de la misma acción en la corrida siguiente.
            ->whereIn('estado', ['pendiente', 'en_curso'])
            // Una acción de una NC cerrada, cancelada o rechazada no es trabajo
            // pendiente de nadie. Sin esto seguirían venciendo para siempre.
            ->whereHas('noConformidad', fn ($q) => $q
                ->whereIn('estado', NoConformidad::ESTADOS_ABIERTOS))
            ->with(['responsable', 'noConformidad.responsable'])
            ->get();

        $marcadas = 0;

        foreach ($acciones as $accion) {
            $accion->update(['estado' => 'vencida']);
            $marcadas++;

            $destinatarios = collect([$accion->responsable, $accion->noConformidad->responsable])
                ->filter()
                ->unique('id');

            if ($destinatarios->isEmpty()) {
                continue;
            }

            // El aviso no puede tumbar la corrida: el estado `vencida` ya quedó
            // escrito, que es lo que no se puede perder.
            try {
                Notification::send($destinatarios, new AccionVencidaNotification(
                    $accion->noConformidad,
                    $accion->descripcion,
                    $accion->fecha_prevista->format('d/m/Y'),
                ));
            } catch (\Throwable $e) {
                Log::error('No se pudo avisar de la acción vencida: '.$e->getMessage());
            }
        }

        return $marcadas;
    }

    /**
     * Las NC a las que les llegó la fecha de verificar la eficacia sin tener el
     * resultado cargado.
     *
     * Se avisa desde `en_implementacion` y no solo desde `verificacion_eficacia`
     * a propósito: la gracia es enterarse de que llegó el momento, y si hubiera
     * que esperar a estar en la etapa, el aviso llegaría cuando alguien ya se
     * acordó solo.
     */
    private function avisarVerificacionesPendientes(): int
    {
        $ncs = NoConformidad::query()
            ->whereNotNull('fecha_verificacion_prevista')
            ->whereDate('fecha_verificacion_prevista', '<=', now()->toDateString())
            ->whereNull('verificacion_avisada_at')
            ->whereNull('resultado_eficacia')
            ->whereIn('estado', ['en_implementacion', 'verificacion_eficacia'])
            ->with('responsable')
            ->get();

        // Una NC sin responsable es de Calidad — es el estado en el que nace.
        // Sin este fallback el recordatorio se perdería en silencio justo en
        // los casos que más lo necesitan: los que no tienen dueño.
        $calidad = $ncs->contains(fn (NoConformidad $nc) => ! $nc->responsable)
            ? $this->gestionDeCalidad()
            : collect();

        $avisadas = 0;

        foreach ($ncs as $nc) {
            // La marca se escribe SIEMPRE, incluso sin destinatario: si no, la
            // NC se recorrería en cada corrida para nada.
            $nc->update(['verificacion_avisada_at' => now()]);
            $avisadas++;

            $destinatarios = $nc->responsable
                ? collect([$nc->responsable])
                : $calidad;

            if ($destinatarios->isEmpty()) {
                continue;
            }

            try {
                Notification::send($destinatarios, new VerificacionPendienteNotification($nc));
            } catch (\Throwable $e) {
                Log::error('No se pudo avisar de la verificación pendiente: '.$e->getMessage());
            }
        }

        return $avisadas;
    }

    /**
     * Quiénes son "Gestión de Calidad" a los fines del fallback.
     *
     * ⚠️ Se filtra con `whereHas`, **nunca** con el scope `permission()` de
     * Spatie: ese scope tira `PermissionDoesNotExist` si el permiso no está
     * creado, y una tarea agendada no puede caerse por eso. Es la misma trampa
     * que `User::role()` en el alta del portal.
     *
     * @return Collection<int, User>
     */
    private function gestionDeCalidad(): Collection
    {
        return User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', 'nc.gestionar'))
            ->orWhereHas('permissions', fn ($q) => $q->where('name', 'nc.gestionar'))
            ->get();
    }
}
