<?php

namespace App\Console\Commands;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\SectorSaturadoNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa cuando un sector se pasa de su tope de observaciones abiertas.
 *
 * Es un comando propio y no un agregado a `observaciones:alertas` a propósito:
 * aquél escala **casos individuales** que vencieron, éste mira la **carga de un
 * área**. Son dos preguntas distintas y conviene poder correr una sin la otra.
 *
 * El tope vive en `sectors.tope_observaciones` y lo edita el ABM de Sectores.
 * Nace en `null` en los diez sectores, que significa "sin tope": mientras nadie
 * lo cargue, este comando no hace nada.
 */
class RevisarTopeDeSectoresCommand extends Command
{
    protected $signature = 'sectores:tope';

    protected $description = 'Avisa al gerente de un sector cuando supera su tope de observaciones abiertas';

    public function handle(): int
    {
        $abiertasPorSector = Observacion::query()
            ->whereIn('estado', Observacion::ESTADOS_ABIERTOS)
            // ⚠️ Las observaciones sin sector quedan afuera: todavía no son de
            // nadie, y contarlas inventaría una saturación que ningún gerente
            // puede resolver.
            ->whereNotNull('sector_id')
            // Una sola consulta agrupada y no un count() por sector: con diez
            // sectores daría igual, pero es gratis hacerlo bien.
            ->selectRaw('sector_id, COUNT(*) as total')
            ->groupBy('sector_id')
            ->pluck('total', 'sector_id');

        $avisados = 0;

        foreach (Sector::whereNotNull('tope_observaciones')->get() as $sector) {
            $abiertas = (int) ($abiertasPorSector[$sector->id] ?? 0);

            if (! $sector->superaElTope($abiertas)) {
                // ⚠️ Esto es lo que hace que el mecanismo sirva más de una vez.
                // Sin limpiar la marca al bajar del tope, el sector avisaría una
                // sola vez en su vida. Mismo patrón que
                // `verificacion_avisada_at` en `nc:recordatorios`.
                if ($sector->tope_avisado_at !== null) {
                    $sector->update(['tope_avisado_at' => null]);
                }

                continue;
            }

            // Ya se avisó de este episodio de saturación: no se repite hasta que
            // el sector baje del tope y vuelva a subir.
            if ($sector->tope_avisado_at !== null) {
                continue;
            }

            $destinatarios = $this->gerentesDe($sector);

            if ($destinatarios->isEmpty()) {
                $this->warn("{$sector->nombre} superó el tope pero no hay a quién avisarle.");

                continue;
            }

            try {
                Notification::send($destinatarios, new SectorSaturadoNotification($sector, $abiertas));
            } catch (\Throwable $e) {
                // Que falle el aviso no puede tumbar la tarea agendada.
                Log::error("No se pudo avisar que {$sector->nombre} superó su tope: ".$e->getMessage());

                continue;
            }

            $sector->update(['tope_avisado_at' => now()]);
            $avisados++;

            $this->warn("{$sector->nombre}: {$abiertas} abiertas sobre un tope de {$sector->tope_observaciones}.");
        }

        $this->info("Sectores avisados: {$avisados}.");

        return self::SUCCESS;
    }

    /**
     * Quién es "el gerente del sector".
     *
     * ⚠️ **No existe un gerente por sector.** `users.gerente_id` es una relación
     * entre usuarios y `es_gerente` es un flag, así que hay que derivarlo. Se
     * toma la **unión** de dos conjuntos, porque ninguno solo alcanza:
     *
     *  1. Los gerentes a los que reporta la gente de ese sector (`gerente_id`).
     *     Cubre al gerente que no está cargado en el sector que dirige.
     *  2. Los `es_gerente` que trabajan en el sector. Cubre al que sí lo está
     *     pero a quien nadie apunta todavía.
     *
     * ⚠️ Si no hay ninguno, **cae a los super-admin**: un aviso no puede
     * perderse porque falte cargar una jerarquía. Mismo criterio que el alta del
     * portal público y que el recordatorio de verificación de las NC.
     *
     * @return EloquentCollection<int, User>
     */
    private function gerentesDe(Sector $sector): EloquentCollection
    {
        $gerentes = User::query()
            ->where(fn ($q) => $q
                ->whereIn('id', User::query()
                    ->where('sector_id', $sector->id)
                    ->whereNotNull('gerente_id')
                    ->select('gerente_id'))
                ->orWhere(fn ($q) => $q
                    ->where('sector_id', $sector->id)
                    ->where('es_gerente', true)))
            ->get();

        if ($gerentes->isNotEmpty()) {
            return $gerentes;
        }

        // ⚠️ `whereHas` y nunca el scope `User::role()` de Spatie, que tira
        // `RoleDoesNotExist` si el rol no está creado. Una tarea agendada no
        // puede caerse por eso.
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', User::ROL_SUPER_ADMIN))
            ->get();
    }
}
