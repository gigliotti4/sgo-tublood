<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Observacion;
use App\Models\ObservationExtension;
use App\Models\ObservationHistory;
use App\Models\User;
use App\Notifications\ObservacionProrrogaResueltaNotification;
use App\Notifications\ObservacionProrrogaSolicitadaNotification;
use App\Support\ReglasObservacion;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pedir y autorizar prórrogas del plazo de una observación (pedido del
 * 6/11/2026).
 *
 * Pedirla es gestionar el caso (`update`: el responsable). Resolverla es del
 * permiso `observaciones.prorrogas.autorizar` (`ObservacionPolicy::autorizarProrroga`):
 * quien pide no se la aprueba a sí mismo. Las dos autorizaciones están en la
 * ruta; acá no se repiten.
 *
 * "En prórroga" no es un estado: una aprobada escribe `observations.prorroga_hasta`
 * y el plazo que cuenta pasa a ser `Observacion::vencimiento()`.
 */
class ObservacionProrrogaController extends Controller
{
    public function store(Request $request, Observacion $observacion): RedirectResponse
    {
        if (! in_array($observacion->estado, Observacion::ESTADOS_ABIERTOS, true)) {
            throw ValidationException::withMessages([
                'fecha_pedida' => 'Solo se puede prorrogar una observación abierta.',
            ]);
        }

        if ($observacion->prorrogaPendiente()->exists()) {
            throw ValidationException::withMessages([
                'fecha_pedida' => 'Ya hay una prórroga esperando decisión para esta observación.',
            ]);
        }

        $datos = $request->validate([
            'fecha_pedida' => ['required', 'date', 'after:today'],
            'motivo' => ['required', Rule::in(array_keys(ObservationExtension::MOTIVOS))],
            'que_se_hace' => ['required', 'string', 'max:255'],
            'justificacion' => ['required', 'string', 'max:5000'],
            ...ReglasObservacion::adjuntosDelPanel(),
        ], [], ReglasObservacion::atributos('archivos'));

        $this->exigirQueExtienda($observacion, Carbon::parse($datos['fecha_pedida']), 'fecha_pedida');

        $prorroga = DB::transaction(function () use ($request, $observacion, $datos) {
            $prorroga = $observacion->prorrogas()->create([
                'solicitada_por' => $request->user()->id,
                'fecha_pedida' => $datos['fecha_pedida'],
                'motivo' => $datos['motivo'],
                'que_se_hace' => $datos['que_se_hace'],
                'justificacion' => $datos['justificacion'],
                'vencimiento_previo' => $observacion->vencimiento(),
            ]);

            $entrada = $observacion->historial()->create([
                'user_id' => $request->user()->id,
                'accion' => ObservationHistory::ACCION_PRORROGA_PEDIDA,
                'nota' => $datos['justificacion'],
                'cambios' => [
                    'fecha' => Carbon::parse($datos['fecha_pedida'])->format('d/m/Y'),
                    'motivo' => ObservationExtension::MOTIVOS[$datos['motivo']],
                    'que_se_hace' => $datos['que_se_hace'],
                ],
            ]);

            foreach ($request->file('archivos', []) as $file) {
                $observacion->guardarAdjunto($file, [
                    'observation_history_id' => $entrada->id,
                    'user_id' => $request->user()->id,
                ], subcarpeta: 'prorrogas');
            }

            return $prorroga;
        });

        $this->avisarALosAutorizadores($observacion, $prorroga);

        return back(fallback: route('observaciones.show', $observacion))
            ->with('success', 'Pediste la prórroga. Le llega a quien la autoriza.');
    }

    public function decidir(Request $request, Observacion $observacion, ObservationExtension $prorroga): RedirectResponse
    {
        if (! $prorroga->estaPendiente()) {
            throw ValidationException::withMessages([
                'decision' => 'Esa prórroga ya fue resuelta.',
            ]);
        }

        $datos = $request->validate([
            'decision' => ['required', Rule::in(['aprobar', 'rechazar'])],
            // Puede ser distinta de la pedida: "Aprobar con otra fecha".
            'fecha_autorizada' => ['required_if:decision,aprobar', 'nullable', 'date', 'after_or_equal:today'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);

        $aprueba = $datos['decision'] === 'aprobar';

        if ($aprueba) {
            $this->exigirQueExtienda($observacion, Carbon::parse($datos['fecha_autorizada']), 'fecha_autorizada');
        }

        DB::transaction(function () use ($request, $observacion, $prorroga, $datos, $aprueba) {
            $prorroga->update([
                'estado' => $aprueba ? ObservationExtension::ESTADO_APROBADA : ObservationExtension::ESTADO_RECHAZADA,
                'decidida_por' => $request->user()->id,
                'decidida_at' => now(),
                'fecha_autorizada' => $aprueba ? $datos['fecha_autorizada'] : null,
                'comentario' => $datos['comentario'] ?? null,
            ]);

            if ($aprueba) {
                // Fin del día autorizado, y el contador de alertas vuelve a 0:
                // si pasada la nueva fecha sigue abierta, tiene que alertar de nuevo.
                $observacion->update([
                    'prorroga_hasta' => Carbon::parse($datos['fecha_autorizada'])->endOfDay(),
                    'alerta_nivel' => 0,
                ]);
            }

            $observacion->historial()->create([
                'user_id' => $request->user()->id,
                'accion' => ObservationHistory::ACCION_PRORROGA_RESUELTA,
                'nota' => $datos['comentario'] ?? null,
                'cambios' => [
                    'decision' => $aprueba ? 'aprobada' : 'rechazada',
                    'fecha' => $aprueba ? Carbon::parse($datos['fecha_autorizada'])->format('d/m/Y') : null,
                    'pedida' => $prorroga->fecha_pedida->format('d/m/Y'),
                ],
            ]);
        });

        $this->avisarAlSolicitante($observacion, $prorroga->fresh());

        return back(fallback: route('observaciones.show', $observacion))
            ->with('success', $aprueba ? 'Prórroga aprobada.' : 'Prórroga rechazada.');
    }

    /**
     * Una prórroga que no estira nada no es una prórroga: la fecha tiene que
     * ser posterior al plazo vigente (el original, o la prórroga anterior).
     */
    private function exigirQueExtienda(Observacion $observacion, Carbon $fecha, string $campo): void
    {
        $vigente = $observacion->vencimiento();

        if ($vigente && $fecha->copy()->endOfDay()->lte($vigente)) {
            throw ValidationException::withMessages([
                $campo => 'La fecha tiene que ser posterior al plazo vigente ('.$vigente->format('d/m/Y').').',
            ]);
        }
    }

    /**
     * Quién autoriza: el permiso `observaciones.prorrogas.autorizar`, asignado
     * directo a una persona o por rol. Se filtra con `whereHas` y no con el
     * scope `permission()` de Spatie, que tira excepción si el permiso no está
     * creado (mismo criterio que el resto de los avisos).
     *
     * Si nadie lo tiene (el usuario todavía no existe en este entorno) caen los
     * super-admin: un pedido de prórroga no puede quedar sin que se entere nadie.
     *
     * @return Collection<int, User>
     */
    private function autorizadores(): Collection
    {
        $permiso = 'observaciones.prorrogas.autorizar';

        $quienes = User::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permiso))
            ->orWhereHas('roles.permissions', fn ($q) => $q->where('name', $permiso))
            ->get();

        if ($quienes->isNotEmpty()) {
            return $quienes;
        }

        return User::whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))->get();
    }

    /**
     * Fuera de la transacción y con su propio try/catch: la fila ya se guardó, y
     * un fallo al avisar no puede devolverle un error a quien pidió la prórroga.
     */
    private function avisarALosAutorizadores(Observacion $observacion, ObservationExtension $prorroga): void
    {
        try {
            $destinatarios = $this->autorizadores()->reject(fn (User $u) => $u->id === $prorroga->solicitada_por);

            Notification::send($destinatarios, new ObservacionProrrogaSolicitadaNotification($observacion, $prorroga));
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar de la prórroga pedida', ['observacion' => $observacion->id, 'error' => $e->getMessage()]);
        }
    }

    private function avisarAlSolicitante(Observacion $observacion, ObservationExtension $prorroga): void
    {
        try {
            // Si quien decide es quien pidió (un super-admin), no se avisa a sí mismo.
            if ($prorroga->solicitada_por === $prorroga->decidida_por) {
                return;
            }

            User::find($prorroga->solicitada_por)?->notify(new ObservacionProrrogaResueltaNotification($observacion, $prorroga));
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar de la prórroga resuelta', ['observacion' => $observacion->id, 'error' => $e->getMessage()]);
        }
    }
}
