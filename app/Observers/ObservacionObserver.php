<?php

namespace App\Observers;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionFinalizadaNotification;

/**
 * Arranca y corta el reloj de gestión de una observación, y deja la entrada de
 * bitácora de cada cambio relevante.
 *
 * Vive en un observer y no en el controller porque el responsable (y el
 * sector, y la clasificación) se asignan desde varios lugares distintos
 * (`storeInterna`, `storeInternaEspecial` y `update` de Admin\ObservacionController,
 * más lo que sume la derivación el día que se implemente), y así queda un solo
 * punto de verdad tanto para el reloj como para la bitácora.
 */
class ObservacionObserver
{
    public function saving(Observacion $observacion): void
    {
        if (! $observacion->isDirty('responsable_id')) {
            return;
        }

        // Reasignar (o desasignar) reinicia el reloj: el plazo es de la persona
        // que tiene la observación ahora, no de la que la tenía antes.
        $observacion->responsable_asignado_at = null;
        $observacion->vence_at = null;
        $observacion->alerta_nivel = 0;

        if ($observacion->responsable_id === null) {
            return;
        }

        $dias = User::with('sector')->find($observacion->responsable_id)?->sector?->dias_gestion;

        $observacion->responsable_asignado_at = now();
        // Sin sector o sin plazo cargado no hay contra qué medir: la observación
        // queda sin vencimiento y nunca alerta.
        $observacion->vence_at = $dias ? now()->addWeekdays($dias) : null;
    }

    public function updated(Observacion $observacion): void
    {
        $this->registrarCambios($observacion);

        if (! $observacion->wasChanged('estado') || ! $observacion->estaFinalizada()) {
            return;
        }

        $observacion->responsable?->gerente
            ?->notify(new ObservacionFinalizadaNotification($observacion));
    }

    /**
     * Una entrada de bitácora por cada campo relevante que cambió en este
     * update. Solo corre en `updated()` (no en `created()`): una observación
     * recién creada no tiene "cambios" que contar, la bitácora arranca en la
     * primera modificación real.
     *
     * Los valores se resuelven a texto legible **en este momento**, no se
     * guardan los IDs crudos: es un registro histórico, así que tiene que
     * seguir contando lo mismo aunque después renombren un sector o borren
     * un usuario. `getOriginal()` da el valor de antes de este save.
     */
    private function registrarCambios(Observacion $observacion): void
    {
        if ($observacion->wasChanged('estado')) {
            $this->registrar($observacion, ObservationHistory::ACCION_ESTADO, [
                'de' => Observacion::ESTADOS[$observacion->getOriginal('estado')] ?? $observacion->getOriginal('estado'),
                'a' => Observacion::ESTADOS[$observacion->estado] ?? $observacion->estado,
            ]);
        }

        if ($observacion->wasChanged('responsable_id')) {
            $this->registrar($observacion, ObservationHistory::ACCION_RESPONSABLE, [
                'de' => $this->nombreUsuario($observacion->getOriginal('responsable_id')),
                'a' => $this->nombreUsuario($observacion->responsable_id),
            ]);
        }

        if ($observacion->wasChanged('sector_id')) {
            $this->registrar($observacion, ObservationHistory::ACCION_SECTOR, [
                'de' => $this->nombreSector($observacion->getOriginal('sector_id')),
                'a' => $this->nombreSector($observacion->sector_id),
            ]);
        }

        // Prioridad y tipo de caso van juntos: así se cargan en el formulario de
        // clasificación, y separarlos en dos entradas no aporta nada al relato.
        if ($observacion->wasChanged('prioridad') || $observacion->wasChanged('tipo_caso')) {
            $prioridades = config('incidencias.prioridades');

            $this->registrar($observacion, ObservationHistory::ACCION_CLASIFICACION, [
                'prioridad' => [
                    'de' => $prioridades[$observacion->getOriginal('prioridad')] ?? $observacion->getOriginal('prioridad') ?? 'Sin clasificar',
                    'a' => $prioridades[$observacion->prioridad] ?? $observacion->prioridad ?? 'Sin clasificar',
                ],
                'tipo_caso' => [
                    'de' => $observacion->getOriginal('tipo_caso') ?? 'Sin clasificar',
                    'a' => $observacion->tipo_caso ?? 'Sin clasificar',
                ],
            ]);
        }
    }

    /** @param  array<string, mixed>  $cambios */
    private function registrar(Observacion $observacion, string $accion, array $cambios): void
    {
        $observacion->historial()->create([
            'user_id' => auth()->id(),
            'accion' => $accion,
            'cambios' => $cambios,
        ]);
    }

    private function nombreUsuario(?int $id): string
    {
        if ($id === null) {
            return 'Sin asignar';
        }

        return User::find($id)?->name ?? "Usuario #{$id}";
    }

    private function nombreSector(?int $id): string
    {
        if ($id === null) {
            return 'Sin sector';
        }

        return Sector::find($id)?->nombre ?? "Sector #{$id}";
    }
}
